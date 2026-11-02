<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\EntiteBulletin;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\Remise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Données affichées par une décharge : seule source du document. Figées à la validation, ou
 * recalculées à la demande pour l'aperçu d'un brouillon.
 */
class DonneesDecharge
{
    /** @return array<string, mixed> */
    public static function construire(Decharge $decharge): array
    {
        $cycle = $decharge->cycle()->firstOrFail();
        $destinataire = $decharge->destinataire()->firstOrFail();
        $plan = $decharge->plan()->firstOrFail();
        $circuits = app(CircuitService::class);

        $circuit = $decharge->circuit ?? $circuits->circuitPour($cycle, $destinataire);
        $remisA = $decharge->remis_a_id !== null
            ? Destinataire::findOrFail($decharge->remis_a_id)
            : $circuits->remisA($cycle, $destinataire);

        $lignes = DechargeLigne::where('decharge_id', $decharge->id)->with('materiel')->get();
        $annexes = PlanDotationLigne::where('plan_dotation_id', $plan->id)
            ->where('destinataire_id', $destinataire->id)
            ->whereIn('materiel_id', $lignes->pluck('materiel_id'))->get()->keyBy('materiel_id');
        $entites = EntiteBulletin::where('cycle_id', $cycle->id)
            ->whereIn('materiel_id', $lignes->pluck('materiel_id'))->pluck('nom', 'materiel_id');

        $lignes = $lignes
            ->sort(fn (DechargeLigne $a, DechargeLigne $b) => $a->materiel->ordre <=> $b->materiel->ordre
                ?: TriLibelles::comparer($a->materiel->libelle, $b->materiel->libelle)
                ?: $a->id <=> $b->id)
            ->values()
            ->map(function (DechargeLigne $ligne) use ($annexes, $entites) {
                $annexe = $annexes->get($ligne->materiel_id);

                return [
                    'materiel_id' => $ligne->materiel_id,
                    'libelle' => $ligne->materiel->libelle,
                    'regroupement' => $ligne->materiel->regroupement,
                    'ordre' => $ligne->materiel->ordre,
                    'cle_affichee' => $ligne->cle_affichee,
                    'entite' => $entites->get($ligne->materiel_id),
                    'dotation_approuvee' => $ligne->dotation_approuvee,
                    'deja_engage' => $ligne->deja_engage,
                    'quantite' => $ligne->quantite,
                    'observations' => $ligne->observations,
                    'annexe' => $annexe === null ? null : [
                        'effectif' => $annexe->effectif,
                        'exact' => $annexe->exact,
                        'majoration' => $annexe->majoration,
                        'minimum' => $annexe->minimum,
                        'arrondi' => $annexe->arrondi,
                    ],
                ];
            })->all();

        return [
            'entete' => config('logistique.entete'),
            'titre' => 'DÉCHARGE DE MATÉRIEL ÉLECTORAL — '.Decharge::CATEGORIES[$decharge->categorie]
                .' — '.mb_strtoupper(Decharge::NATURES[$decharge->nature]),
            'categorie' => $decharge->categorie,
            'nature' => $decharge->nature,
            'numero' => $decharge->numero,
            'remplace' => $decharge->decharge_remplacee_id === null ? null : [
                'numero' => Decharge::whereKey($decharge->decharge_remplacee_id)->value('numero'),
            ],
            'cycle' => [
                'libelle' => $cycle->libelle,
                'type' => $cycle->type,
                'date_scrutin' => $cycle->date_scrutin?->format('Y-m-d'),
            ],
            'destinataire' => [
                'libelle' => $destinataire->libelle,
                'type' => $destinataire->type,
                'signataire_nom' => $destinataire->signataire_nom,
                'signataire_qualite' => $destinataire->signataire_qualite,
            ],
            'remis_a' => [
                'libelle' => $remisA->libelle,
                'signataire_nom' => $remisA->signataire_nom,
                'signataire_qualite' => $remisA->signataire_qualite,
            ],
            'circuit' => $circuit,
            'effectifs' => app(PlanDotationService::class)->effectifsDestinataire($plan, $destinataire),
            'plan' => ['id' => $plan->id, 'version' => $plan->version],
            'lieu' => config('logistique.lieu_document'),
            'date' => ($decharge->valide_le ?? now())->format('Y-m-d'),
            'nb' => config('logistique.nb_decharge'),
            'lignes' => $lignes,
        ];
    }

    /**
     * Données de l'exemplaire de réception, lues sur les tables vivantes (le reste du document vient des données
     * figées). Le reçu de chaque article est celui des déclarations retenues des remises non annulées adressées au
     * destinataire final, comme IndicateurService::recuUtilisable ; l'utilisable est le reçu moins le défectueux.
     *
     * @return array{remises: list<array<string, mixed>>, declarations: list<array<string, mixed>>, lignes: list<array<string, mixed>>}
     */
    public static function reception(Decharge $decharge): array
    {
        $remises = Remise::where('decharge_id', $decharge->id)->whereNotIn('statut', ['annulee', 'a_arbitrer'])->orderBy('id')->get();
        $declarations = Declaration::whereIn('remise_id', $remises->pluck('id'))
            ->whereIn('statut', ['retenue', 'concordante'])->with(['lignes.materiel', 'signataireUser', 'delegation.autorite'])->orderBy('id')->get();
        $retenues = $declarations->where('statut', 'retenue')->keyBy('remise_id');

        $utilisateurs = User::whereIn('id', $remises->flatMap(fn (Remise $r) => [
            $r->de_type === 'agent' ? $r->de_id : null, $r->a_type === 'agent' ? $r->a_id : null,
        ])->filter()->unique()->all())->get()->keyBy('id');
        $destinataires = Destinataire::whereIn('id', $remises->flatMap(fn (Remise $r) => [
            $r->de_type === 'destinataire' ? $r->de_id : null, $r->a_type === 'destinataire' ? $r->a_id : null,
        ])->filter()->unique()->all())->pluck('libelle', 'id');

        $partie = function (Remise $r, string $cote) use ($utilisateurs, $destinataires): string {
            $type = $r->{$cote.'_type'};
            $id = $r->{$cote.'_id'};

            return match ($type) {
                'magasin' => 'Magasin central',
                'agent' => trim(($utilisateurs->get($id)?->prenom ?? '').' '.($utilisateurs->get($id)?->name ?? '')) ?: 'Agent',
                'transporteur' => trim(($r->transporteur_nom ?? 'Transporteur').($r->transporteur_societe ? ' ('.$r->transporteur_societe.')' : '')),
                default => (string) $destinataires->get($id, 'Destinataire'),
            };
        };

        $listeRemises = $remises->map(fn (Remise $r) => [
            'id' => $r->id,
            'etape' => $r->etape,
            'etape_libelle' => Remise::ETAPES[$r->etape],
            'de' => $partie($r, 'de'),
            'a' => $partie($r, 'a'),
            'date' => ($retenues->get($r->id)?->created_at ?? $r->created_at)->format('Y-m-d'),
            'statut' => $r->statut,
            'statut_libelle' => Remise::STATUTS[$r->statut],
        ])->values()->all();

        $listeDeclarations = $declarations->map(function (Declaration $d) use ($remises) {
            return [
                'id' => $d->id,
                'remise_id' => $d->remise_id,
                'etape' => $remises->firstWhere('id', $d->remise_id)->etape,
                'mode' => $d->mode,
                'statut' => $d->statut,
                'statut_libelle' => Declaration::STATUTS[$d->statut],
                'signataire_nom' => $d->signataire_nom,
                'signataire_qualite' => $d->signataire_qualite,
                // Qui a réellement signé et comment son identité a été établie : une identité non établie ne s'imprime jamais comme confirmée.
                'signataire_compte' => $d->signataireUser?->nom_complet,
                'delegant' => $d->delegation?->autorite?->nom_complet,
                'verification_identite' => $d->verification_identite ?? 'non_verifiee',
                'verification_libelle' => $d->libelleVerification(),
                'identite_etablie' => $d->identiteEtablie(),
                'contestation_libelle' => in_array($d->contestation, ['contestee', 'levee'], true) ? Declaration::CONTESTATIONS[$d->contestation] : null,
                'horodatage_terminal' => $d->horodatage_terminal?->format('Y-m-d H:i:s'),
                'horodatage_serveur' => $d->created_at->format('Y-m-d H:i:s'),
                'lignes' => $d->lignes->map(fn ($l) => [
                    'materiel_id' => $l->materiel_id,
                    'libelle' => $l->materiel->libelle,
                    'recu' => $l->recu,
                    'defectueux' => $l->defectueux,
                    'observation' => $l->observation,
                ])->all(),
            ];
        })->values()->all();

        // Déclarations retenues des remises adressées au destinataire final, sommées par article.
        $finales = $remises->filter(fn (Remise $r) => $r->a_type === 'destinataire' && (int) $r->a_id === (int) $decharge->destinataire_id)->pluck('id');
        $lignesRecues = DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->whereIn('declarations.remise_id', $finales)
            ->where('declarations.statut', 'retenue')
            ->orderBy('declarations.id')
            ->get(['declaration_lignes.materiel_id', 'declaration_lignes.recu', 'declaration_lignes.defectueux', 'declaration_lignes.observation'])
            ->groupBy('materiel_id');

        $lignes = DechargeLigne::where('decharge_id', $decharge->id)->with('materiel')->get()
            ->sort(fn (DechargeLigne $a, DechargeLigne $b) => $a->materiel->ordre <=> $b->materiel->ordre
                ?: TriLibelles::comparer($a->materiel->libelle, $b->materiel->libelle)
                ?: $a->id <=> $b->id)
            ->values()
            ->map(function (DechargeLigne $ligne) use ($lignesRecues) {
                $recues = $lignesRecues->get($ligne->materiel_id, collect());
                $recu = (int) $recues->sum('recu');
                $defectueux = (int) $recues->sum('defectueux');

                return [
                    'materiel_id' => $ligne->materiel_id,
                    'libelle' => $ligne->materiel->libelle,
                    'regroupement' => $ligne->materiel->regroupement,
                    'annonce' => $ligne->quantite,
                    'recu' => $recu,
                    'defectueux' => $defectueux,
                    'utilisable' => $recu - $defectueux,
                    'observations' => $recues->pluck('observation')->prepend($ligne->observations)->filter(fn ($o) => filled($o))->implode(' ; '),
                ];
            })->all();

        return ['remises' => $listeRemises, 'declarations' => $listeDeclarations, 'lignes' => $lignes];
    }
}
