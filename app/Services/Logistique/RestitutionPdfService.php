<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\Logistique\RestitutionDocument;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Fiche de restitution du matériel électoral réutilisable : rendue depuis les données de la restitution, archivée
 * avec son empreinte SHA-256 et munie d'un QR vers la restitution au central. Deux exemplaires : la déclaration
 * (dès que le retour est pris en charge) et le contrôle (une fois la restitution contrôlée). Un exemplaire émis
 * n'est jamais régénéré : la réimpression sert le fichier archivé, après contrôle de son empreinte.
 */
class RestitutionPdfService
{
    use ArchivePdf;

    public const TITRE = 'FICHE DE RESTITUTION DU MATÉRIEL ÉLECTORAL RÉUTILISABLE';

    private const REFUS = [
        'declaration' => 'La fiche de déclaration ne peut être émise qu\'une fois le retour pris en charge.',
        'controle' => 'La fiche de contrôle ne peut être émise qu\'une fois la restitution contrôlée.',
    ];

    /** Journal de la restitution lu pour dater et attribuer les étapes. */
    private const ETAPES = ['prise_en_charge_restitution', 'reception_restitution', 'controle_restitution', 'decision_reforme_restitution'];

    /** HTML de la fiche. Aucun aperçu : un exemplaire non émissible ne se rend pas. */
    public function html(Restitution $restitution, string $exemplaire): string
    {
        $this->exigerExemplaire($exemplaire);
        $retenue = RestitutionDeclaration::where('restitution_id', $restitution->id)->where('statut', 'retenue')
            ->with(['signataireUser', 'delegation.autorite'])->first();
        $this->exigerEmissible($restitution, $exemplaire, $retenue);

        return view('logistique.restitutions.pdf', [
            'titre' => self::TITRE,
            'entete' => config('logistique.entete'),
            'exemplaire' => $exemplaire,
            'f' => $this->donnees($restitution, $exemplaire, $retenue),
            'qr' => $this->qr($restitution),
        ])->render();
    }

    /**
     * URL absolue de la restitution au central : l'hôte vient de la configuration (APP_URL), jamais de la requête,
     * car l'URL est imprimée sur un document officiel.
     */
    public function urlQr(Restitution $restitution): string
    {
        $chemin = Route::has('logistique.restitutions.show')
            ? route('logistique.restitutions.show', $restitution->id, false)
            : '/logistique/restitutions/'.$restitution->id;

        return rtrim((string) config('app.url'), '/').$chemin;
    }

    /** QR code (data URI PNG) de l'URL absolue de la restitution. */
    public function qr(Restitution $restitution): string
    {
        return $this->qrDepuisUrl($this->urlQr($restitution));
    }

    /**
     * Émet l'exemplaire : le PDF est rendu une seule fois, archivé et empreint. Idempotent — un exemplaire déjà
     * émis est renvoyé tel quel, y compris pour deux appels simultanés (le verrou de la ligne de la restitution
     * les sérialise).
     */
    public function emettre(Restitution $restitution, string $exemplaire): RestitutionDocument
    {
        $this->exigerExemplaire($exemplaire);
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut émettre les fiches de restitution.');
        }

        return DB::transaction(function () use ($restitution, $exemplaire) {
            // Ordre des verrous : cycle (partagé), puis restitution. L'archivage reste possible après la clôture.
            Cycle::whereKey($restitution->cycle_id)->sharedLock()->first();
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();

            $existant = RestitutionDocument::where('restitution_id', $actuelle->id)->where('exemplaire', $exemplaire)->first();
            if ($existant !== null) {
                return $existant;
            }

            // html() refuse un exemplaire qui n'est pas encore émissible.
            $octets = $this->pdf($this->html($actuelle, $exemplaire));
            $chemin = "logistique/restitutions/{$actuelle->cycle_id}/{$actuelle->id}-{$exemplaire}.pdf";
            if (! $this->disque()->put($chemin, $octets)) {
                throw new RegleLogistique("Archivage du document impossible : {$chemin}.");
            }

            // Le fichier est écrit hors transaction de base : si la suite échoue, on le retire pour ne
            // laisser aucun archivé sans ligne ni journal.
            try {
                $document = RestitutionDocument::create([
                    'restitution_id' => $actuelle->id,
                    'exemplaire' => $exemplaire,
                    'chemin' => $chemin,
                    'empreinte' => hash('sha256', $octets),
                    'taille' => strlen($octets),
                    'emis_par' => auth()->id(),
                    'emis_le' => now(),
                ]);

                Journal::noter('restitution', $actuelle->id, 'emission_pdf_restitution', null, [
                    'exemplaire' => $exemplaire, 'chemin' => $chemin, 'empreinte' => $document->empreinte, 'taille' => $document->taille,
                ]);
            } catch (Throwable $e) {
                $this->disque()->delete($chemin);

                throw $e;
            }

            return $document;
        }, attempts: 3);
    }

    /** Octets du fichier archivé, après contrôle de son empreinte. */
    public function contenu(RestitutionDocument $document): string
    {
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut télécharger les fiches de restitution.');
        }

        return $this->octetsArchives($document->chemin, $document->empreinte);
    }

    private function exigerExemplaire(string $exemplaire): void
    {
        if (! in_array($exemplaire, RestitutionDocument::EXEMPLAIRES, true)) {
            throw new InvalidArgumentException("Exemplaire inconnu : {$exemplaire}.");
        }
    }

    /** Déclaration : retour pris en charge (ou plus avancé) avec une déclaration retenue. Contrôle : restitution contrôlée. Dans les deux cas, la déclaration retenue est requise. */
    private function exigerEmissible(Restitution $restitution, string $exemplaire, ?RestitutionDeclaration $retenue): void
    {
        $permis = match ($exemplaire) {
            'declaration' => $retenue !== null && in_array($restitution->statut, ['en_retour', 'recue_central', 'controlee'], true),
            'controle' => $retenue !== null && $restitution->statut === 'controlee',
            default => false,
        };

        if (! $permis) {
            throw new RegleLogistique(self::REFUS[$exemplaire]);
        }
    }

    /**
     * Données imprimées. Les requêtes sont bornées : lignes (avec leurs articles), journal de la restitution,
     * comptes cités, détenteur.
     */
    private function donnees(Restitution $restitution, string $exemplaire, RestitutionDeclaration $retenue): array
    {
        $cycle = Cycle::findOrFail($restitution->cycle_id);
        $lignes = RestitutionLigne::where('restitution_id', $restitution->id)->with('materiel')->get()
            ->sort(fn (RestitutionLigne $a, RestitutionLigne $b) => $a->materiel->ordre <=> $b->materiel->ordre
                ?: TriLibelles::comparer($a->materiel->libelle, $b->materiel->libelle)
                ?: $a->id <=> $b->id)
            ->values();

        $journal = JournalEntree::where('objet_type', 'restitution')->where('objet_id', $restitution->id)
            ->whereIn('action', self::ETAPES)->orderBy('id')->get()->groupBy('action');
        $comptes = User::whereIn('id', collect([$restitution->agent_id])
            ->merge($journal->flatten()->pluck('user_id'))
            ->merge($lignes->pluck('reforme_decidee_par'))
            ->filter()->unique()->all())->get()->keyBy('id');
        $etape = function (string $action) use ($journal, $comptes): ?array {
            $entree = $journal->get($action)?->last();

            return $entree === null ? null : [
                'par' => $comptes->get($entree->user_id)?->nom_complet,
                'le' => $entree->created_at->format('d/m/Y H:i'),
            ];
        };

        $detenteur = $restitution->detenteur_type === 'agent'
            ? (User::find($restitution->detenteur_id)?->nom_complet ?: 'Agent')
            : (string) Destinataire::whereKey($restitution->detenteur_id)->value('libelle');

        $f = [
            'restitution_id' => $restitution->id,
            'detenteur_type_libelle' => Restitution::DETENTEURS[$restitution->detenteur_type],
            'detenteur' => $detenteur,
            'cycle' => $cycle->libelle,
            'date_scrutin' => $cycle->date_scrutin?->format('d/m/Y'),
            'lieu' => config('logistique.lieu_document'),
            'lignes' => $lignes->map(fn (RestitutionLigne $l) => [
                'libelle' => $l->materiel->libelle,
                'attendu' => $l->attendu,
                'declare' => $l->declare_restitue,
                'recu' => $l->recu_central,
                'bon' => $l->bon,
                'a_reparer' => $l->a_reparer,
                'reforme' => $l->reforme,
            ])->all(),
            'declaration' => [
                'mode' => Declaration::MODES[$retenue->mode] ?? $retenue->mode,
                'signataire_nom' => $retenue->signataire_nom,
                'signataire_qualite' => $retenue->signataire_qualite,
                // Qui a réellement signé et comment son identité a été établie : une identité non établie ne s'imprime jamais comme confirmée.
                'signataire_compte' => $retenue->signataireUser?->nom_complet,
                'delegant' => $retenue->delegation?->autorite?->nom_complet,
                'verification_libelle' => $retenue->libelleVerification(),
                'identite_etablie' => $retenue->identiteEtablie(),
                'terminal' => $retenue->horodatage_terminal?->format('d/m/Y H:i'),
                'serveur' => $retenue->created_at->format('d/m/Y H:i'),
            ],
            'agent' => ['nom' => $comptes->get($restitution->agent_id)?->nom_complet, 'prise_en_charge' => $etape('prise_en_charge_restitution')],
            'controle' => null,
        ];

        if ($exemplaire === 'controle') {
            $decisions = $journal->get('decision_reforme_restitution', collect())
                ->mapWithKeys(fn (JournalEntree $e) => [(int) ($e->apres['ligne_id'] ?? 0) => $e->created_at->format('d/m/Y H:i')]);
            $f['controle'] = [
                'reception' => $etape('reception_restitution'),
                'controle' => $etape('controle_restitution'),
                'reformes' => $lignes->filter(fn (RestitutionLigne $l) => $l->reforme > 0 && $l->motif_reforme !== null)
                    ->map(fn (RestitutionLigne $l) => [
                        'libelle' => $l->materiel->libelle,
                        'quantite' => $l->reforme,
                        'motif' => $l->motif_reforme,
                        'par' => $comptes->get($l->reforme_decidee_par)?->nom_complet,
                        'le' => $decisions->get($l->id),
                    ])->values()->all(),
            ];
        }

        return $f;
    }
}
