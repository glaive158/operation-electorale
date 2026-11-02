<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Écarts entre les décharges validées et le besoin (plan de dotation approuvé courant, remplacements, clôtures) : ce qu'il reste
 * à envoyer en reliquat, ce qu'il faut annuler avant départ, ce qu'il faut seulement signaler
 * parce que le matériel est déjà parti. Lecture seule, quatre requêtes au plus, quel que soit le nombre de destinataires.
 */
class EcartVersionService
{
    /**
     * Un article du plan approuvé absent pour un destinataire compte pour une dotation de zéro ; l'engagé se compare au besoin (dotation + remplacements − clôtures), la dotation reste exposée.
     * Collection vide si aucun plan n'est approuvé.
     *
     * @return Collection<int, array{destinataire: Destinataire, materiel: Materiel, categorie: string, dotation: int, besoin: int, engage: int, type: string, quantite: int, decharges: list<int>}>
     */
    public function analyser(Cycle $cycle): Collection
    {
        // Plan courant et ses lignes, remplacements et clôtures d'écart : une seule requête (union). La
        // jointure externe distingue « pas de plan » de « plan sans ligne » (tout l'engagé compte alors
        // pour une dotation de zéro) ; seules les lignes de plan portent une dotation.
        $remplacements = DB::table('besoins_remplacement')->where('cycle_id', $cycle->id)
            ->groupBy('destinataire_id', 'materiel_id')
            ->selectRaw("'remplacement' as source, destinataire_id, materiel_id, SUM(quantite) as quantite");
        $clotures = DB::table('arbitrages')->where('cycle_id', $cycle->id)->where('objet', 'cloture_ecart')
            ->whereNotNull('materiel_id')->groupBy('destinataire_id', 'materiel_id')
            ->selectRaw("'cloture' as source, destinataire_id, materiel_id, SUM(quantite_expedition_annulee) as quantite");
        // Remplacements déjà couverts par un brouillon de décharge vivant : le besoin est servi, pas encore engagé.
        $couverts = DB::table('besoins_remplacement')->where('cycle_id', $cycle->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('decharges')
                ->whereColumn('decharges.besoin_remplacement_id', 'besoins_remplacement.id')
                ->where('decharges.statut_document', 'brouillon'))
            ->groupBy('destinataire_id', 'materiel_id')
            ->selectRaw("'couvert' as source, destinataire_id, materiel_id, SUM(quantite) as quantite");
        $lignesPlan = DB::table('plans_dotation')
            ->leftJoin('plan_dotation_lignes', function ($join) {
                $join->on('plan_dotation_lignes.plan_dotation_id', '=', 'plans_dotation.id')
                    ->whereNotNull('plan_dotation_lignes.dotation_approuvee');
            })
            ->where('plans_dotation.cycle_id', $cycle->id)->where('plans_dotation.statut', 'approuve')
            ->selectRaw("'plan' as source, plan_dotation_lignes.destinataire_id as destinataire_id, plan_dotation_lignes.materiel_id as materiel_id, plan_dotation_lignes.dotation_approuvee as quantite")
            ->unionAll($remplacements)->unionAll($clotures)->unionAll($couverts)
            ->get();
        $plan = $lignesPlan->where('source', 'plan');
        if ($plan->isEmpty()) {
            return collect();
        }

        $dotations = $plan->whereNotNull('destinataire_id')
            ->mapWithKeys(fn ($l) => [$l->destinataire_id.'|'.$l->materiel_id => (int) $l->quantite]);
        // Écart du besoin à la dotation : remplacements en plus, expéditions annulées en moins.
        $ajustements = [];
        foreach ($lignesPlan->where('source', 'remplacement') as $l) {
            $ajustements[$l->destinataire_id.'|'.$l->materiel_id] = ($ajustements[$l->destinataire_id.'|'.$l->materiel_id] ?? 0) + (int) $l->quantite;
        }
        foreach ($lignesPlan->where('source', 'cloture') as $l) {
            $ajustements[$l->destinataire_id.'|'.$l->materiel_id] = ($ajustements[$l->destinataire_id.'|'.$l->materiel_id] ?? 0) - (int) $l->quantite;
        }

        $couvertsParPaire = [];
        foreach ($lignesPlan->where('source', 'couvert') as $l) {
            $couvertsParPaire[$l->destinataire_id.'|'.$l->materiel_id] = (int) $l->quantite;
        }

        // Une ligne par (décharge validée, article) : de quoi totaliser l'engagé, isoler le non parti
        // et nommer les décharges à annuler, sans requête par destinataire.
        $lignes = DB::table('decharge_lignes')
            ->join('decharges', 'decharges.id', '=', 'decharge_lignes.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->where('decharges.statut_document', 'validee')
            ->groupBy('decharges.id', 'decharges.destinataire_id', 'decharges.statut_acheminement', 'decharge_lignes.materiel_id')
            ->orderBy('decharges.id')
            ->selectRaw('decharges.id as decharge_id, decharges.destinataire_id as destinataire_id, decharges.statut_acheminement as acheminement, decharge_lignes.materiel_id as materiel_id, SUM(decharge_lignes.quantite) as total')
            ->get();

        $paires = [];
        foreach ($lignes as $ligne) {
            $cle = $ligne->destinataire_id.'|'.$ligne->materiel_id;
            $paires[$cle] ??= ['destinataire_id' => $ligne->destinataire_id, 'materiel_id' => $ligne->materiel_id, 'engage' => 0, 'non_parti' => 0, 'decharges' => []];
            $total = (int) $ligne->total;
            $paires[$cle]['engage'] += $total;
            if ($ligne->acheminement === 'a_preparer' && $total > 0) {
                $paires[$cle]['non_parti'] += $total;
                $paires[$cle]['decharges'][] = (int) $ligne->decharge_id;
            }
        }

        $bruts = [];
        foreach ($paires as $cle => $p) {
            $dotation = $dotations[$cle] ?? 0;
            $besoin = max(0, $dotation + ($ajustements[$cle] ?? 0));
            if ($p['engage'] <= 0 || $p['engage'] === $besoin) {
                continue;
            }
            $bruts[] = ['destinataire_id' => $p['destinataire_id'], 'materiel_id' => $p['materiel_id'], 'dotation' => $dotation, 'besoin' => $besoin, 'engage' => $p['engage']]
                + ['ecarts' => $this->classer($besoin, $p['engage'], $p['non_parti'], $p['decharges'], $couvertsParPaire[$cle] ?? 0)];
        }
        if ($bruts === []) {
            return collect();
        }

        $destinataires = Destinataire::query()->whereKey(array_column($bruts, 'destinataire_id'))->get()->keyBy('id');
        $materiels = Materiel::query()->whereKey(array_column($bruts, 'materiel_id'))->get()->keyBy('id');

        return collect($bruts)
            ->flatMap(fn (array $b) => array_map(fn (array $ecart) => [
                'destinataire' => $destinataires[$b['destinataire_id']],
                'materiel' => $materiels[$b['materiel_id']],
                'categorie' => (string) $materiels[$b['materiel_id']]->categorie,
                'dotation' => $b['dotation'],
                'besoin' => $b['besoin'],
                'engage' => $b['engage'],
            ] + $ecart, $b['ecarts']))
            ->sortBy([
                fn ($a, $b) => TriLibelles::comparer($a['destinataire']->libelle, $b['destinataire']->libelle),
                fn ($a, $b) => $a['materiel']->ordre <=> $b['materiel']->ordre,
                fn ($a, $b) => $a['materiel']->id <=> $b['materiel']->id,
            ])
            ->values();
    }

    /**
     * @param  list<int>  $decharges  décharges validées non parties portant l'article
     * @param  int  $couvert  remplacements déjà servis par un brouillon de décharge : ils ne sont pas un reliquat à préparer
     * @return list<array{type: string, quantite: int, decharges: list<int>}>
     */
    private function classer(int $dotation, int $engage, int $nonParti, array $decharges, int $couvert = 0): array
    {
        if ($dotation > $engage) {
            $reliquat = $dotation - $engage - $couvert;

            return $reliquat > 0 ? [['type' => 'reliquat', 'quantite' => $reliquat, 'decharges' => []]] : [];
        }

        $exces = $engage - $dotation;
        $ecarts = [];
        $aAnnuler = min($exces, $nonParti);
        if ($aAnnuler > 0) {
            $ecarts[] = ['type' => 'annulation', 'quantite' => $aAnnuler, 'decharges' => $decharges];
        }
        if ($exces - $nonParti > 0) {
            $ecarts[] = ['type' => 'signalement', 'quantite' => $exces - $nonParti, 'decharges' => []];
        }

        return $ecarts;
    }
}
