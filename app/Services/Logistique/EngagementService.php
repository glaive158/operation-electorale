<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Engagement d'un destinataire : le besoin d'expédition (dotation du plan approuvé, plus les besoins
 * de remplacement autorisés, moins les expéditions annulées par une clôture d'écart), ce que les
 * décharges validées ont déjà promis, et ce qu'il reste à expédier. Lecture seule.
 */
class EngagementService
{
    /**
     * Besoin d'expédition d'un article pour un destinataire : dotation approuvée du plan courant +
     * Σ remplacements − Σ expéditions annulées par clôture d'écart. Jamais négatif.
     */
    public function besoin(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        $plan = PlanDotation::courantApprouve($cycle);
        $dotation = $plan === null ? 0 : (int) PlanDotationLigne::where('plan_dotation_id', $plan->id)
            ->where('destinataire_id', $destinataire->id)->where('materiel_id', $materiel->id)
            ->sum('dotation_approuvee');
        $remplacements = (int) BesoinRemplacement::where('cycle_id', $cycle->id)
            ->where('destinataire_id', $destinataire->id)->where('materiel_id', $materiel->id)
            ->sum('quantite');
        $annulees = (int) Arbitrage::where('cycle_id', $cycle->id)->where('objet', 'cloture_ecart')
            ->where('destinataire_id', $destinataire->id)->where('materiel_id', $materiel->id)
            ->sum('quantite_expedition_annulee');

        return max(0, $dotation + $remplacements - $annulees);
    }

    /**
     * Indexé par article : les articles du plan approuvé pour ce destinataire, plus ceux qui n'ont
     * qu'un besoin de remplacement (dotation 0). Collection vide si le destinataire n'a ni l'un ni
     * l'autre. Deux requêtes au plus : les remplacements et les clôtures sont des sous-requêtes de la
     * première.
     *
     * @return Collection<int, array{materiel: Materiel, dotation: int, besoin: int, engage: int, reste: int}>
     */
    public function etat(Cycle $cycle, Destinataire $destinataire, ?string $categorie = null): Collection
    {
        $remplacements = fn () => DB::table('besoins_remplacement')
            ->where('besoins_remplacement.cycle_id', $cycle->id)
            ->where('besoins_remplacement.destinataire_id', $destinataire->id)
            ->where('besoins_remplacement.quantite', '>', 0);

        $articles = Materiel::query()
            ->leftJoin('plan_dotation_lignes', function ($join) use ($cycle, $destinataire) {
                $join->on('plan_dotation_lignes.materiel_id', '=', 'materiels.id')
                    ->where('plan_dotation_lignes.destinataire_id', $destinataire->id)
                    ->whereNotNull('plan_dotation_lignes.dotation_approuvee')
                    ->whereIn('plan_dotation_lignes.plan_dotation_id', fn ($q) => $q->select('plans_dotation.id')->from('plans_dotation')
                        ->where('plans_dotation.cycle_id', $cycle->id)->where('plans_dotation.statut', 'approuve'));
            })
            ->where(fn ($q) => $q
                ->whereNotNull('plan_dotation_lignes.id')
                ->orWhereIn('materiels.id', $remplacements()->select('besoins_remplacement.materiel_id')))
            ->when($categorie !== null, fn ($q) => $q->where('materiels.categorie', $categorie))
            ->orderBy('materiels.ordre')->orderBy('materiels.id')
            ->select('materiels.*', 'plan_dotation_lignes.dotation_approuvee as dotation_plan')
            ->selectSub(
                $remplacements()->whereColumn('besoins_remplacement.materiel_id', 'materiels.id')->selectRaw('COALESCE(SUM(besoins_remplacement.quantite), 0)'),
                'remplacement_somme'
            )
            ->selectSub(
                DB::table('arbitrages')
                    ->where('arbitrages.cycle_id', $cycle->id)->where('arbitrages.objet', 'cloture_ecart')
                    ->where('arbitrages.destinataire_id', $destinataire->id)
                    ->whereColumn('arbitrages.materiel_id', 'materiels.id')
                    ->selectRaw('COALESCE(SUM(arbitrages.quantite_expedition_annulee), 0)'),
                'cloture_somme'
            )
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
            $besoin = max(0, $dotation + (int) $materiel->getAttribute('remplacement_somme') - (int) $materiel->getAttribute('cloture_somme'));
            $engage = (int) ($engages[$materiel->id] ?? 0);
            $materiel->offsetUnset('dotation_plan');
            $materiel->offsetUnset('remplacement_somme');
            $materiel->offsetUnset('cloture_somme');

            return [$materiel->id => [
                'materiel' => $materiel,
                'dotation' => $dotation,
                'besoin' => $besoin,
                'engage' => $engage,
                'reste' => max(0, $besoin - $engage),
            ]];
        });
    }

    /**
     * Comme etat(), pour préparer une décharge initiale ou de reliquat : le reste est net des besoins de
     * remplacement (couverts par les seules décharges de remplacement) et ne retranche que ce que les décharges
     * validées initiales ou de reliquat ont déjà promis. Un article qui n'a qu'un besoin de remplacement n'y figure pas.
     *
     * @return Collection<int, array{materiel: Materiel, dotation: int, besoin: int, engage: int, reste: int}>
     */
    public function etatInitial(Cycle $cycle, Destinataire $destinataire, ?string $categorie = null): Collection
    {
        $etat = $this->etat($cycle, $destinataire, $categorie);
        if ($etat->isEmpty()) {
            return $etat;
        }

        $remplacements = DB::table('besoins_remplacement')
            ->where('cycle_id', $cycle->id)->where('destinataire_id', $destinataire->id)
            ->whereIn('materiel_id', $etat->keys()->all())
            ->groupBy('materiel_id')
            ->selectRaw('materiel_id, SUM(quantite) as total')
            ->pluck('total', 'materiel_id');
        $engages = DechargeLigne::query()
            ->join('decharges', 'decharges.id', '=', 'decharge_lignes.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->where('decharges.destinataire_id', $destinataire->id)
            ->where('decharges.statut_document', 'validee')
            ->whereIn('decharges.nature', ['initiale', 'reliquat'])
            ->whereIn('decharge_lignes.materiel_id', $etat->keys()->all())
            ->groupBy('decharge_lignes.materiel_id')
            ->selectRaw('decharge_lignes.materiel_id as materiel_id, SUM(decharge_lignes.quantite) as total')
            ->pluck('total', 'materiel_id');

        return $etat
            ->reject(fn (array $e, int $id) => $e['dotation'] === 0 && (int) ($remplacements[$id] ?? 0) > 0)
            ->map(function (array $e, int $id) use ($remplacements, $engages) {
                $horsRemplacement = max(0, $e['besoin'] - (int) ($remplacements[$id] ?? 0));
                $e['engage'] = (int) ($engages[$id] ?? 0);
                $e['besoin'] = $horsRemplacement;
                $e['reste'] = max(0, $horsRemplacement - $e['engage']);

                return $e;
            });
    }

    /**
     * Dotation, besoin, engagé et reste de tous les destinataires par catégorie, en trois requêtes
     * quel que soit leur nombre (plan, remplacements et clôtures, engagé). Même règle que etat() :
     * le reste se calcule article par article (un surplus sur un article ne compense pas le manque
     * d'un autre). Collection vide si aucun article n'a ni dotation approuvée ni remplacement.
     *
     * @return Collection<string, array{destinataire_id: int, categorie: string, dotation: int, besoin: int, engage: int, reste: int}> clé « destinataire_id|catégorie »
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

        $remplacements = DB::table('besoins_remplacement')
            ->join('materiels', 'materiels.id', '=', 'besoins_remplacement.materiel_id')
            ->where('besoins_remplacement.cycle_id', $cycle->id)
            ->when($categorie !== null, fn ($q) => $q->where('materiels.categorie', $categorie))
            ->groupBy('besoins_remplacement.destinataire_id', 'besoins_remplacement.materiel_id', 'materiels.categorie')
            ->selectRaw('besoins_remplacement.destinataire_id as destinataire_id, besoins_remplacement.materiel_id as materiel_id, materiels.categorie as categorie, SUM(besoins_remplacement.quantite) as remplacement, 0 as annulee');
        $ajustements = DB::table('arbitrages')
            ->join('materiels', 'materiels.id', '=', 'arbitrages.materiel_id')
            ->where('arbitrages.cycle_id', $cycle->id)
            ->where('arbitrages.objet', 'cloture_ecart')
            ->when($categorie !== null, fn ($q) => $q->where('materiels.categorie', $categorie))
            ->groupBy('arbitrages.destinataire_id', 'arbitrages.materiel_id', 'materiels.categorie')
            ->selectRaw('arbitrages.destinataire_id as destinataire_id, arbitrages.materiel_id as materiel_id, materiels.categorie as categorie, 0 as remplacement, SUM(arbitrages.quantite_expedition_annulee) as annulee')
            ->unionAll($remplacements)
            ->get();

        // Un article existe pour un destinataire dès qu'il a une dotation approuvée ou un remplacement ;
        // une clôture ne crée jamais d'article, elle ne fait que réduire un besoin existant.
        $articles = [];
        foreach ($plan as $l) {
            $cle = $l->destinataire_id.'|'.$l->materiel_id;
            $articles[$cle] = ['destinataire_id' => (int) $l->destinataire_id, 'materiel_id' => (int) $l->materiel_id, 'categorie' => (string) $l->categorie, 'dotation' => (int) $l->dotation, 'remplacement' => 0, 'annulee' => 0];
        }
        foreach ($ajustements as $a) {
            $cle = $a->destinataire_id.'|'.$a->materiel_id;
            if ((int) $a->remplacement > 0) {
                $articles[$cle] ??= ['destinataire_id' => (int) $a->destinataire_id, 'materiel_id' => (int) $a->materiel_id, 'categorie' => (string) $a->categorie, 'dotation' => 0, 'remplacement' => 0, 'annulee' => 0];
                $articles[$cle]['remplacement'] += (int) $a->remplacement;
            }
        }
        foreach ($ajustements as $a) {
            $cle = $a->destinataire_id.'|'.$a->materiel_id;
            if ((int) $a->annulee > 0 && isset($articles[$cle])) {
                $articles[$cle]['annulee'] += (int) $a->annulee;
            }
        }
        if ($articles === []) {
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
        foreach ($articles as $cleArticle => $a) {
            $cle = $a['destinataire_id'].'|'.$a['categorie'];
            $lignes[$cle] ??= ['destinataire_id' => $a['destinataire_id'], 'categorie' => $a['categorie'], 'dotation' => 0, 'besoin' => 0, 'engage' => 0, 'reste' => 0];
            $besoin = max(0, $a['dotation'] + $a['remplacement'] - $a['annulee']);
            $engage = $engages[$cleArticle] ?? 0;
            $lignes[$cle]['dotation'] += $a['dotation'];
            $lignes[$cle]['besoin'] += $besoin;
            $lignes[$cle]['engage'] += $engage;
            $lignes[$cle]['reste'] += max(0, $besoin - $engage);
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
