<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ce que chaque détenteur doit encore restituer à l'issue d'un cycle, par article réutilisable (spec §9.4).
 *
 * Destinataire D (préfecture, sous-préfecture, poste) :
 *   reçu retenu (défectueux compris) sur les remises qui lui sont adressées
 *   − redistributions émises par D (ni annulées ni à arbitrer)
 *   − retours déjà déclarés par ses restitutions du cycle.
 *
 * Agent A (transport) :
 *   matériel chargé à son nom
 *   − reçu retenu sur ses livraisons (le manque déclaré par le destinataire reste attendu de l'agent)
 *   − retours déjà déclarés par ses restitutions du cycle.
 *
 * Cinq requêtes agrégées au plus, groupées par détenteur et article, quel que soit le volume.
 */
class SoldeDetenteurService
{
    /**
     * Soldes strictement positifs du cycle, un par (détenteur, article réutilisable), triés par type, détenteur
     * puis article. Un solde nul ou négatif n'est jamais renvoyé.
     *
     * @param  ?string  $detenteurType  limite le calcul à ce détenteur (avec `$detenteurId`)
     * @param  ?int  $sauf  restitution dont les propres retours déclarés ne se déduisent pas (son recalcul)
     * @return Collection<int, array{detenteur_type: string, detenteur_id: int, materiel_id: int, solde: int}>
     */
    public function soldes(Cycle $cycle, ?string $detenteurType = null, ?int $detenteurId = null, ?int $sauf = null): Collection
    {
        $soldes = [];
        $ajouter = function (Collection $lignes, int $signe) use (&$soldes) {
            foreach ($lignes as $ligne) {
                $cle = $ligne->detenteur_type.':'.$ligne->detenteur_id.':'.$ligne->materiel_id;
                $soldes[$cle] ??= [
                    'detenteur_type' => $ligne->detenteur_type, 'detenteur_id' => (int) $ligne->detenteur_id,
                    'materiel_id' => (int) $ligne->materiel_id, 'solde' => 0,
                ];
                $soldes[$cle]['solde'] += $signe * (int) $ligne->quantite;
            }
        };

        // Destinataire : reçu retenu (le défectueux est compris dans le reçu).
        $ajouter($this->filtrer($this->recusRetenus($cycle)
            ->where('remises.a_type', 'destinataire')
            ->selectRaw("'destinataire' as detenteur_type, remises.a_id as detenteur_id, declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu) as quantite")
            ->groupBy('remises.a_id', 'declaration_lignes.materiel_id'), 'remises.a_id', 'destinataire', $detenteurType, $detenteurId)->get(), 1);

        // Destinataire : redistributions émises, hors annulées et à arbitrer.
        $ajouter($this->filtrer($this->lignesRemises($cycle)
            ->where('remises.etape', 'redistribution')->where('remises.de_type', 'destinataire')
            ->whereNotIn('remises.statut', ['annulee', 'a_arbitrer'])
            ->selectRaw("'destinataire' as detenteur_type, remises.de_id as detenteur_id, remise_lignes.materiel_id as materiel_id, SUM(remise_lignes.quantite) as quantite")
            ->groupBy('remises.de_id', 'remise_lignes.materiel_id'), 'remises.de_id', 'destinataire', $detenteurType, $detenteurId)->get(), -1);

        // Agent : matériel chargé à son nom.
        $ajouter($this->filtrer($this->lignesRemises($cycle)
            ->where('remises.etape', 'chargement')->where('remises.a_type', 'agent')->where('remises.statut', '!=', 'annulee')
            ->selectRaw("'agent' as detenteur_type, remises.a_id as detenteur_id, remise_lignes.materiel_id as materiel_id, SUM(remise_lignes.quantite) as quantite")
            ->groupBy('remises.a_id', 'remise_lignes.materiel_id'), 'remises.a_id', 'agent', $detenteurType, $detenteurId)->get(), 1);

        // Agent : reçu retenu sur ses livraisons.
        $ajouter($this->filtrer($this->recusRetenus($cycle)
            ->where('remises.etape', 'livraison')->where('remises.de_type', 'agent')
            ->selectRaw("'agent' as detenteur_type, remises.de_id as detenteur_id, declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu) as quantite")
            ->groupBy('remises.de_id', 'declaration_lignes.materiel_id'), 'remises.de_id', 'agent', $detenteurType, $detenteurId)->get(), -1);

        // Retours déjà déclarés par les restitutions du cycle.
        $retours = DB::table('restitution_lignes')
            ->join('restitutions', 'restitutions.id', '=', 'restitution_lignes.restitution_id')
            ->join('materiels', 'materiels.id', '=', 'restitution_lignes.materiel_id')
            ->where('restitutions.cycle_id', $cycle->id)->where('materiels.reutilisable', true)
            ->when($sauf !== null, fn (Builder $q) => $q->where('restitutions.id', '!=', $sauf))
            ->when($detenteurType !== null, fn (Builder $q) => $q->where('restitutions.detenteur_type', $detenteurType)->where('restitutions.detenteur_id', $detenteurId))
            ->selectRaw('restitutions.detenteur_type as detenteur_type, restitutions.detenteur_id as detenteur_id, restitution_lignes.materiel_id as materiel_id, SUM(restitution_lignes.declare_restitue) as quantite')
            ->groupBy('restitutions.detenteur_type', 'restitutions.detenteur_id', 'restitution_lignes.materiel_id')
            ->get();
        $ajouter($retours, -1);

        return collect($soldes)
            ->filter(fn (array $ligne) => $ligne['solde'] > 0)
            ->sortBy([['detenteur_type', 'asc'], ['detenteur_id', 'asc'], ['materiel_id', 'asc']])
            ->values();
    }

    /** Lignes de déclarations retenues du cycle, sur des remises non annulées et des articles réutilisables. */
    private function recusRetenus(Cycle $cycle): Builder
    {
        return DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->join('materiels', 'materiels.id', '=', 'declaration_lignes.materiel_id')
            ->where('decharges.cycle_id', $cycle->id)->where('materiels.reutilisable', true)
            ->where('declarations.statut', 'retenue')->where('remises.statut', '!=', 'annulee');
    }

    /** Lignes de remises du cycle sur des articles réutilisables. */
    private function lignesRemises(Cycle $cycle): Builder
    {
        return DB::table('remise_lignes')
            ->join('remises', 'remises.id', '=', 'remise_lignes.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->join('materiels', 'materiels.id', '=', 'remise_lignes.materiel_id')
            ->where('decharges.cycle_id', $cycle->id)->where('materiels.reutilisable', true);
    }

    /** Restreint une requête au détenteur demandé, si le type correspond. */
    private function filtrer(Builder $requete, string $colonne, string $type, ?string $detenteurType, ?int $detenteurId): Builder
    {
        if ($detenteurType === null) {
            return $requete;
        }

        return $detenteurType === $type ? $requete->where($colonne, $detenteurId) : $requete->whereRaw('1 = 0');
    }
}
