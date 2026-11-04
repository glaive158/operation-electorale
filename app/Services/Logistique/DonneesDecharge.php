<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\EntiteBulletin;
use App\Models\Logistique\PlanDotationLigne;

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
}
