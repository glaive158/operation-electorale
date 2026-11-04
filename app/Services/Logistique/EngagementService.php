<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Engagement d'un destinataire : ce que le plan de dotation approuvé lui accorde, ce que les
 * décharges validées ont déjà promis, et ce qu'il reste à expédier. Lecture seule.
 */
class EngagementService
{
    /**
     * Indexé par article. Les articles hors du plan approuvé pour ce destinataire sont absents ;
     * collection vide si aucun plan n'est approuvé. Deux requêtes au plus.
     *
     * @return Collection<int, array{materiel: Materiel, dotation: int, engage: int, reste: int}>
     */
    public function etat(Cycle $cycle, Destinataire $destinataire, ?string $categorie = null): Collection
    {
        $articles = Materiel::query()
            ->join('plan_dotation_lignes', 'plan_dotation_lignes.materiel_id', '=', 'materiels.id')
            ->join('plans_dotation', 'plans_dotation.id', '=', 'plan_dotation_lignes.plan_dotation_id')
            ->where('plans_dotation.cycle_id', $cycle->id)
            ->where('plans_dotation.statut', 'approuve')
            ->where('plan_dotation_lignes.destinataire_id', $destinataire->id)
            ->whereNotNull('plan_dotation_lignes.dotation_approuvee')
            ->when($categorie !== null, fn ($q) => $q->where('materiels.categorie', $categorie))
            ->orderBy('materiels.ordre')->orderBy('materiels.id')
            ->select('materiels.*', 'plan_dotation_lignes.dotation_approuvee as dotation_plan')
            ->get();

        if ($articles->isEmpty()) {
            return collect();
        }

        $engages = DechargeLigne::query()
            ->join('decharges', 'decharges.id', '=', 'decharge_lignes.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->where('decharges.destinataire_id', $destinataire->id)
            ->where('decharges.statut_document', 'validee')
            ->whereIn('decharge_lignes.materiel_id', $articles->modelKeys())
            ->groupBy('decharge_lignes.materiel_id')
            ->selectRaw('decharge_lignes.materiel_id as materiel_id, SUM(decharge_lignes.quantite) as total')
            ->pluck('total', 'materiel_id');

        return $articles->mapWithKeys(function (Materiel $materiel) use ($engages) {
            $dotation = (int) $materiel->getAttribute('dotation_plan');
            $engage = (int) ($engages[$materiel->id] ?? 0);
            $materiel->offsetUnset('dotation_plan');

            return [$materiel->id => [
                'materiel' => $materiel,
                'dotation' => $dotation,
                'engage' => $engage,
                'reste' => max(0, $dotation - $engage),
            ]];
        });
    }

    /**
     * Dotation, engagé et reste de tous les destinataires par catégorie, en deux requêtes quel que soit
     * leur nombre. Même règle que etat() : le reste se calcule article par article (un surplus sur un
     * article ne compense pas le manque d'un autre). Collection vide si aucun plan n'est approuvé.
     *
     * @return Collection<string, array{destinataire_id: int, categorie: string, dotation: int, engage: int, reste: int}> clé « destinataire_id|catégorie »
     */
    public function synthese(Cycle $cycle, ?string $categorie = null): Collection
    {
        $plan = DB::table('plan_dotation_lignes')
            ->join('plans_dotation', 'plans_dotation.id', '=', 'plan_dotation_lignes.plan_dotation_id')
            ->join('materiels', 'materiels.id', '=', 'plan_dotation_lignes.materiel_id')
            ->where('plans_dotation.cycle_id', $cycle->id)
            ->where('plans_dotation.statut', 'approuve')
            ->whereNotNull('plan_dotation_lignes.dotation_approuvee')
            ->when($categorie !== null, fn ($q) => $q->where('materiels.categorie', $categorie))
            ->get([
                'plan_dotation_lignes.destinataire_id as destinataire_id',
                'plan_dotation_lignes.materiel_id as materiel_id',
                'materiels.categorie as categorie',
                'plan_dotation_lignes.dotation_approuvee as dotation',
            ]);
        if ($plan->isEmpty()) {
            return collect();
        }

        $engages = DB::table('decharge_lignes')
            ->join('decharges', 'decharges.id', '=', 'decharge_lignes.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->where('decharges.statut_document', 'validee')
            ->groupBy('decharges.destinataire_id', 'decharge_lignes.materiel_id')
            ->selectRaw('decharges.destinataire_id as destinataire_id, decharge_lignes.materiel_id as materiel_id, SUM(decharge_lignes.quantite) as total')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->destinataire_id.'|'.$l->materiel_id => (int) $l->total]);

        $lignes = [];
        foreach ($plan as $l) {
            $cle = $l->destinataire_id.'|'.$l->categorie;
            $lignes[$cle] ??= ['destinataire_id' => (int) $l->destinataire_id, 'categorie' => (string) $l->categorie, 'dotation' => 0, 'engage' => 0, 'reste' => 0];
            $dotation = (int) $l->dotation;
            $engage = $engages[$l->destinataire_id.'|'.$l->materiel_id] ?? 0;
            $lignes[$cle]['dotation'] += $dotation;
            $lignes[$cle]['engage'] += $engage;
            $lignes[$cle]['reste'] += max(0, $dotation - $engage);
        }

        return collect($lignes);
    }

    /** Quantité de l'article déjà promise à ce destinataire par les décharges validées du cycle. */
    public function engage(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        return (int) DechargeLigne::query()
            ->join('decharges', 'decharges.id', '=', 'decharge_lignes.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->where('decharges.destinataire_id', $destinataire->id)
            ->where('decharges.statut_document', 'validee')
            ->where('decharge_lignes.materiel_id', $materiel->id)
            ->sum('decharge_lignes.quantite');
    }
}
