<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ce que chaque détenteur doit encore restituer à l'issue d'un cycle, par article réutilisable (spec §9.4).
 *
 * Destinataire D (préfecture, sous-préfecture, poste) :
 *   reçu retenu (défectueux compris) sur les remises qui lui sont adressées
 *   − reçu retenu sur les redistributions émises par D (le manque déclaré par le destinataire reste attendu de D)
 *   − attendu de ses restitutions contrôlées du cycle.
 *
 * Agent A (transport) :
 *   matériel chargé à son nom
 *   − reçu retenu sur ses livraisons (le manque déclaré par le destinataire reste attendu de l'agent)
 *   − attendu de ses restitutions contrôlées du cycle.
 *
 * Une restitution contrôlée solde tout son attendu : le non restitué reste un écart à justifier. Seul du matériel
 * reçu après son contrôle fait un nouveau solde.
 *
 * Cinq requêtes agrégées au plus, groupées par détenteur et article, quel que soit le volume.
 */
class SoldeDetenteurService
{
    /**
     * Soldes strictement positifs du cycle, un par (détenteur, article réutilisable), triés par type, détenteur
     * puis article. Un solde nul ou négatif n'est jamais renvoyé.
     *
     * @param  ?string  $detenteurType  limite le calcul à ce détenteur ; avec `$detenteurId`, les deux ou aucun
     * @param  ?int  $sauf  restitution dont l'attendu ne se déduit pas (son recalcul)
     * @return Collection<int, array{detenteur_type: string, detenteur_id: int, materiel_id: int, solde: int}>
     *
     * @throws InvalidArgumentException si un seul des deux est donné, ou si le type est inconnu
     */
    public function soldes(Cycle $cycle, ?string $detenteurType = null, ?int $detenteurId = null, ?int $sauf = null): Collection
    {
        return $this->soldesBruts($cycle, $detenteurType, $detenteurId, $sauf)
            ->filter(fn (array $ligne) => $ligne['solde'] > 0)
            ->values();
    }

    /**
     * Soldes du cycle sans borne, nuls et négatifs compris, mêmes paramètres et même tri que soldes(). Un solde
     * négatif dit qu'une restitution contrôlée a attendu du détenteur plus qu'il ne détient désormais : une
     * réception retenue après coup (redistribution du préfet, livraison de l'agent) l'a reporté sur un autre
     * détenteur, et cet écart-là n'est plus le sien (P6-R47).
     *
     * @return Collection<int, array{detenteur_type: string, detenteur_id: int, materiel_id: int, solde: int}>
     *
     * @throws InvalidArgumentException si un seul des deux est donné, ou si le type est inconnu
     */
    public function soldesBruts(Cycle $cycle, ?string $detenteurType = null, ?int $detenteurId = null, ?int $sauf = null): Collection
    {
        if (($detenteurType === null) !== ($detenteurId === null)) {
            throw new InvalidArgumentException('Le type et l\'identifiant du détenteur se donnent ensemble.');
        }
        if ($detenteurType !== null && ! in_array($detenteurType, ['agent', 'destinataire'], true)) {
            throw new InvalidArgumentException("Type de détenteur inconnu : {$detenteurType}.");
        }

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

        // Destinataire : reçu retenu sur ses redistributions (P6-R43, miroir de l'agent). Tant que le destinataire
        // n'a rien de retenu, le matériel reste attendu de l'émetteur ; un manque déclaré aussi.
        $ajouter($this->filtrer($this->recusRetenus($cycle)
            ->where('remises.etape', 'redistribution')->where('remises.de_type', 'destinataire')
            ->selectRaw("'destinataire' as detenteur_type, remises.de_id as detenteur_id, declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu) as quantite")
            ->groupBy('remises.de_id', 'declaration_lignes.materiel_id'), 'remises.de_id', 'destinataire', $detenteurType, $detenteurId)->get(), -1);

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

        // Restitutions contrôlées du cycle : chacune solde son attendu (P6-R40). Ce qui n'est pas revenu est un écart à
        // justifier (RestitutionService::ecarts), jamais une nouvelle restitution.
        $retours = DB::table('restitution_lignes')
            ->join('restitutions', 'restitutions.id', '=', 'restitution_lignes.restitution_id')
            ->join('materiels', 'materiels.id', '=', 'restitution_lignes.materiel_id')
            ->where('restitutions.cycle_id', $cycle->id)->where('restitutions.statut', 'controlee')->where('materiels.reutilisable', true)
            ->when($sauf !== null, fn (Builder $q) => $q->where('restitutions.id', '!=', $sauf))
            ->when($detenteurType !== null, fn (Builder $q) => $q->where('restitutions.detenteur_type', $detenteurType)->where('restitutions.detenteur_id', $detenteurId))
            ->selectRaw('restitutions.detenteur_type as detenteur_type, restitutions.detenteur_id as detenteur_id, restitution_lignes.materiel_id as materiel_id, SUM(restitution_lignes.attendu) as quantite')
            ->groupBy('restitutions.detenteur_type', 'restitutions.detenteur_id', 'restitution_lignes.materiel_id')
            ->get();
        $ajouter($retours, -1);

        return collect($soldes)
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
