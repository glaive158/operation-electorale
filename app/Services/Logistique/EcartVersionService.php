<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Écarts entre les décharges validées et le plan de dotation approuvé courant : ce qu'il reste
 * à envoyer en reliquat, ce qu'il faut annuler avant départ, ce qu'il faut seulement signaler
 * parce que le matériel est déjà parti. Lecture seule, quatre requêtes au plus, quel que soit le nombre de destinataires.
 */
class EcartVersionService
{
    /**
     * Un article du plan approuvé absent pour un destinataire compte pour une dotation de zéro.
     * Collection vide si aucun plan n'est approuvé.
     *
     * @return Collection<int, array{destinataire: Destinataire, materiel: Materiel, categorie: string, dotation: int, engage: int, type: string, quantite: int, decharges: list<int>}>
     */
    public function analyser(Cycle $cycle): Collection
    {
        // Plan courant et ses lignes en une requête ; la jointure externe distingue « pas de plan »
        // de « plan sans ligne » (tout l'engagé compte alors pour une dotation de zéro).
        $plan = DB::table('plans_dotation')
            ->leftJoin('plan_dotation_lignes', function ($join) {
                $join->on('plan_dotation_lignes.plan_dotation_id', '=', 'plans_dotation.id')
                    ->whereNotNull('plan_dotation_lignes.dotation_approuvee');
            })
            ->where('plans_dotation.cycle_id', $cycle->id)->where('plans_dotation.statut', 'approuve')
            ->get(['plan_dotation_lignes.destinataire_id', 'plan_dotation_lignes.materiel_id', 'plan_dotation_lignes.dotation_approuvee']);
        if ($plan->isEmpty()) {
            return collect();
        }

        $dotations = $plan->whereNotNull('destinataire_id')
            ->mapWithKeys(fn ($l) => [$l->destinataire_id.'|'.$l->materiel_id => (int) $l->dotation_approuvee]);

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
            if ($p['engage'] <= 0 || $p['engage'] === $dotation) {
                continue;
            }
            $bruts[] = ['destinataire_id' => $p['destinataire_id'], 'materiel_id' => $p['materiel_id'], 'dotation' => $dotation, 'engage' => $p['engage']]
                + ['ecarts' => $this->classer($dotation, $p['engage'], $p['non_parti'], $p['decharges'])];
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
     * @return list<array{type: string, quantite: int, decharges: list<int>}>
     */
    private function classer(int $dotation, int $engage, int $nonParti, array $decharges): array
    {
        if ($dotation > $engage) {
            return [['type' => 'reliquat', 'quantite' => $dotation - $engage, 'decharges' => []]];
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
