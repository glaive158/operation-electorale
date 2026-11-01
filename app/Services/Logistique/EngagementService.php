<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use Illuminate\Support\Collection;

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
