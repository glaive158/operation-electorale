<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\ReservationStock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs de suivi, par (destinataire, article) et par article : besoin, objectif de réception, expédié, en
 * transit, chez l'intermédiaire, reçu utilisable, reste à réceptionner et état. Lecture seule.
 *
 * - Le besoin d'expédition (dotation + remplacements − expéditions annulées) et l'objectif de réception (dotation −
 *   abandons) diffèrent : un remplacement augmente le besoin, jamais l'objectif.
 * - Seules les déclarations retenues comptent, au destinataire final de la décharge ; une déclaration divergente,
 *   écartée, remplacée ou à vérifier ne compte jamais.
 * - Expédié par destinataire : somme des lignes des remises de chargement de ses décharges. Par article, la somme des
 *   mouvements de sortie, nette des compensations (celle de BesoinService::expedie) ; les deux coïncident sans
 *   compensation.
 *
 * Le nombre de requêtes ne dépend pas du nombre de destinataires.
 */
class IndicateurService
{
    public function __construct(private readonly EngagementService $engagement) {}

    /**
     * Une entrée par (destinataire, article) présent au plan approuvé ou dans un besoin de remplacement, triée par
     * destinataire puis par article.
     *
     * @param  list<int>|null  $destinataireIds  restreint aux destinataires donnés (null : tous)
     * @return Collection<int, array{destinataire: Destinataire, materiel: Materiel, dotation: int, besoin_expedition: int, objectif_reception: int, engage: int, expedie: int, reste_a_expedier: int, surplus_expedie: int, en_transit: int, chez_intermediaire: int, recu: int, recu_utilisable: int, reste_a_receptionner: int, reclamations_actives: int, etat: string}>
     */
    public function parDestinataire(Cycle $cycle, ?array $destinataireIds = null): Collection
    {
        $ids = $destinataireIds === null ? null : array_values(array_unique(array_map('intval', $destinataireIds)));
        if ($ids === []) {
            return collect();
        }

        $articles = $this->engagement->articles($cycle, null, $ids);
        if ($articles === []) {
            return collect();
        }

        $engages = $this->engages($cycle, $ids);
        $expedies = $this->expedies($cycle, $ids);
        $recus = $this->recus($cycle, $ids);
        $chez = $this->chezIntermediaire($cycle, $ids);
        $reclamations = $this->reclamationsActives($cycle, $ids);

        $destinataires = Destinataire::query()->whereKey(array_unique(array_column($articles, 'destinataire_id')))->get()->keyBy('id');
        $materiels = Materiel::query()->whereKey(array_unique(array_column($articles, 'materiel_id')))->get()->keyBy('id');

        $lignes = [];
        foreach ($articles as $cle => $a) {
            $besoin = max(0, $a['dotation'] + $a['remplacement'] - $a['annulee']);
            $objectif = max(0, $a['dotation'] - $a['abandon']);
            $expedie = $expedies[$cle] ?? 0;
            $recu = $recus[$cle]['recu'] ?? 0;
            $utilisable = $recus[$cle]['utilisable'] ?? 0;
            $intermediaire = $chez[$cle]['utilisable'] ?? 0;
            $detenu = $chez[$cle]['detenu'] ?? 0;
            $actives = $reclamations[$cle] ?? 0;

            $lignes[] = [
                'destinataire' => $destinataires[$a['destinataire_id']],
                'materiel' => $materiels[$a['materiel_id']],
                'dotation' => $a['dotation'],
                'besoin_expedition' => $besoin,
                'objectif_reception' => $objectif,
                'engage' => $engages[$cle] ?? 0,
                'expedie' => $expedie,
                'reste_a_expedier' => max(0, $besoin - $expedie),
                'surplus_expedie' => max(0, $expedie - $besoin),
                'en_transit' => max(0, $expedie - $recu - $detenu),
                'chez_intermediaire' => $intermediaire,
                'recu' => $recu,
                'recu_utilisable' => $utilisable,
                'reste_a_receptionner' => max(0, $objectif - $utilisable),
                'reclamations_actives' => $actives,
                'etat' => match (true) {
                    $a['clotures'] > 0 => 'cloture_avec_ecart',
                    $objectif > 0 && $utilisable >= $objectif && $actives === 0 => 'solde_complet',
                    default => 'en_cours',
                },
            ];
        }

        return collect($lignes)
            ->sortBy([
                fn ($x, $y) => TriLibelles::comparer($x['destinataire']->libelle, $y['destinataire']->libelle),
                fn ($x, $y) => $x['materiel']->ordre <=> $y['materiel']->ordre,
                fn ($x, $y) => $x['materiel']->id <=> $y['materiel']->id,
                fn ($x, $y) => $x['destinataire']->id <=> $y['destinataire']->id,
            ])
            ->values();
    }

    /**
     * Les mêmes grandeurs sommées par article (sans état), triées par ordre du catalogue. L'expédié de l'ensemble du
     * cycle vient des mouvements de stock ; le besoin, l'expédié et le reste à expédier se comparent donc à
     * BesoinService. Le reste à réceptionner est la somme de celui de chaque destinataire (un surplus reçu par l'un
     * ne comble pas le manque d'un autre). Restreint à des destinataires, l'expédié est la somme de leurs remises.
     *
     * @param  list<int>|null  $destinataireIds
     * @return Collection<int, array{materiel: Materiel, dotation: int, besoin_expedition: int, objectif_reception: int, engage: int, expedie: int, reste_a_expedier: int, surplus_expedie: int, en_transit: int, chez_intermediaire: int, recu: int, recu_utilisable: int, reste_a_receptionner: int, reclamations_actives: int}>
     */
    public function parArticle(Cycle $cycle, ?array $destinataireIds = null): Collection
    {
        $lignes = $this->parDestinataire($cycle, $destinataireIds);
        if ($lignes->isEmpty()) {
            return collect();
        }

        $mouvements = $destinataireIds === null ? $this->expedieParMouvements($cycle) : null;
        $somme = ['dotation', 'besoin_expedition', 'objectif_reception', 'engage', 'expedie', 'en_transit', 'chez_intermediaire', 'recu', 'recu_utilisable', 'reste_a_receptionner', 'reclamations_actives'];

        return $lignes->groupBy(fn (array $l) => $l['materiel']->id)
            ->map(function (Collection $groupe, int $materielId) use ($mouvements, $somme) {
                $article = ['materiel' => $groupe->first()['materiel']];
                foreach ($somme as $cle) {
                    $article[$cle] = (int) $groupe->sum($cle);
                }
                if ($mouvements !== null) {
                    $article['expedie'] = $mouvements[$materielId] ?? 0;
                }
                $article['reste_a_expedier'] = max(0, $article['besoin_expedition'] - $article['expedie']);
                $article['surplus_expedie'] = max(0, $article['expedie'] - $article['besoin_expedition']);

                return array_replace(array_flip(self::CLES_ARTICLE), $article);
            })
            ->sortBy([
                fn ($x, $y) => $x['materiel']->ordre <=> $y['materiel']->ordre,
                fn ($x, $y) => $x['materiel']->id <=> $y['materiel']->id,
            ])
            ->values();
    }

    private const CLES_ARTICLE = [
        'materiel', 'dotation', 'besoin_expedition', 'objectif_reception', 'engage', 'expedie', 'reste_a_expedier',
        'surplus_expedie', 'en_transit', 'chez_intermediaire', 'recu', 'recu_utilisable', 'reste_a_receptionner',
        'reclamations_actives',
    ];

    /**
     * Reçu utilisable (reçu moins défectueux) retenu au destinataire final : déclarations retenues des remises
     * non annulées adressées au destinataire, pour les décharges du cycle qui lui sont destinées (ce que le préfet
     * détient pour un sous-préfet ne compte pas pour le préfet).
     */
    public function recuUtilisable(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        return (int) $this->recusRetenus($cycle, [$destinataire->id])
            ->where('declaration_lignes.materiel_id', $materiel->id)
            ->sum(DB::raw('declaration_lignes.recu - declaration_lignes.defectueux'));
    }

    /** Objectif de réception : dotation du plan approuvé moins les abandons acceptés. Jamais négatif. */
    public function objectifReception(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        $plan = PlanDotation::courantApprouve($cycle);
        $dotation = $plan === null ? 0 : (int) PlanDotationLigne::where('plan_dotation_id', $plan->id)
            ->where('destinataire_id', $destinataire->id)->where('materiel_id', $materiel->id)
            ->sum('dotation_approuvee');
        $abandons = (int) Arbitrage::where('cycle_id', $cycle->id)->where('objet', 'cloture_ecart')
            ->where('destinataire_id', $destinataire->id)->where('materiel_id', $materiel->id)
            ->sum('quantite_abandon_reception');

        return max(0, $dotation - $abandons);
    }

    /** Objectif de réception moins le reçu utilisable retenu au destinataire final. À lire sous le verrou du cycle pour une décision. */
    public function resteAReceptionner(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        return max(0, $this->objectifReception($cycle, $destinataire, $materiel) - $this->recuUtilisable($cycle, $destinataire, $materiel));
    }

    /**
     * Déclarations retenues des remises non annulées adressées au destinataire final de leur décharge.
     *
     * @param  list<int>|null  $ids
     */
    private function recusRetenus(Cycle $cycle, ?array $ids): Builder
    {
        return DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('decharges.destinataire_id', $ids))
            ->where('remises.a_type', 'destinataire')
            ->whereColumn('remises.a_id', 'decharges.destinataire_id')
            ->where('remises.statut', '!=', 'annulee')
            ->where('declarations.statut', 'retenue');
    }

    /**
     * @param  list<int>|null  $ids
     * @return array<string, array{recu: int, utilisable: int}> clé « destinataire_id|materiel_id »
     */
    private function recus(Cycle $cycle, ?array $ids): array
    {
        $recus = [];
        $lignes = $this->recusRetenus($cycle, $ids)
            ->groupBy('decharges.destinataire_id', 'declaration_lignes.materiel_id')
            ->selectRaw('decharges.destinataire_id as destinataire_id, declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu) as recu, SUM(declaration_lignes.recu - declaration_lignes.defectueux) as utilisable')
            ->get();
        foreach ($lignes as $l) {
            $recus[$l->destinataire_id.'|'.$l->materiel_id] = ['recu' => (int) $l->recu, 'utilisable' => (int) $l->utilisable];
        }

        return $recus;
    }

    /**
     * Quantité engagée par les décharges validées.
     *
     * @param  list<int>|null  $ids
     * @return array<string, int>
     */
    private function engages(Cycle $cycle, ?array $ids): array
    {
        $engages = [];
        $lignes = DB::table('decharge_lignes')
            ->join('decharges', 'decharges.id', '=', 'decharge_lignes.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->where('decharges.statut_document', 'validee')
            ->when($ids !== null, fn ($q) => $q->whereIn('decharges.destinataire_id', $ids))
            ->groupBy('decharges.destinataire_id', 'decharge_lignes.materiel_id')
            ->selectRaw('decharges.destinataire_id as destinataire_id, decharge_lignes.materiel_id as materiel_id, SUM(decharge_lignes.quantite) as total')
            ->get();
        foreach ($lignes as $l) {
            $engages[$l->destinataire_id.'|'.$l->materiel_id] = (int) $l->total;
        }

        return $engages;
    }

    /**
     * Sorti du magasin : lignes des remises de chargement des décharges du destinataire.
     *
     * @param  list<int>|null  $ids
     * @return array<string, int>
     */
    private function expedies(Cycle $cycle, ?array $ids): array
    {
        $expedies = [];
        $lignes = DB::table('remise_lignes')
            ->join('remises', 'remises.id', '=', 'remise_lignes.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('decharges.destinataire_id', $ids))
            ->where('remises.etape', 'chargement')
            ->where('remises.statut', '!=', 'annulee')
            ->groupBy('decharges.destinataire_id', 'remise_lignes.materiel_id')
            ->selectRaw('decharges.destinataire_id as destinataire_id, remise_lignes.materiel_id as materiel_id, SUM(remise_lignes.quantite) as total')
            ->get();
        foreach ($lignes as $l) {
            $expedies[$l->destinataire_id.'|'.$l->materiel_id] = (int) $l->total;
        }

        return $expedies;
    }

    /**
     * Reliquat chez le préfet, par destinataire final : pour chaque décharge livrée à un autre destinataire que le
     * sien, reçu utilisable retenu à la livraison moins ce qui est redistribué (remises non annulées), jamais
     * négatif. Même règle que RemiseService::soldePrefet, pour toutes les décharges à la fois. « detenu » compte aussi
     * les défectueux déclarés au préfet (physiquement chez lui, donc pas en transit) ; « utilisable » seul est exposé
     * comme chez_intermediaire.
     *
     * @param  list<int>|null  $ids
     * @return array<string, array{utilisable: int, detenu: int}>
     */
    private function chezIntermediaire(Cycle $cycle, ?array $ids): array
    {
        $recus = DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('decharges.destinataire_id', $ids))
            ->where('remises.etape', 'livraison')
            ->where('remises.a_type', 'destinataire')
            ->whereColumn('remises.a_id', '!=', 'decharges.destinataire_id')
            ->where('remises.statut', '!=', 'annulee')
            ->where('declarations.statut', 'retenue')
            ->groupBy('decharges.id', 'decharges.destinataire_id', 'declaration_lignes.materiel_id')
            ->selectRaw('decharges.id as decharge_id, decharges.destinataire_id as destinataire_id, declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu - declaration_lignes.defectueux) as utilisable, SUM(declaration_lignes.recu) as brut')
            ->get();
        $transferts = DB::table('remise_lignes')
            ->join('remises', 'remises.id', '=', 'remise_lignes.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('decharges.destinataire_id', $ids))
            ->where('remises.etape', 'redistribution')
            ->where('remises.statut', '!=', 'annulee')
            ->groupBy('decharges.id', 'remise_lignes.materiel_id')
            ->selectRaw('decharges.id as decharge_id, remise_lignes.materiel_id as materiel_id, SUM(remise_lignes.quantite) as transfere')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->decharge_id.'|'.$l->materiel_id => (int) $l->transfere]);

        $chez = [];
        foreach ($recus as $l) {
            $transfere = $transferts[$l->decharge_id.'|'.$l->materiel_id] ?? 0;
            $cle = $l->destinataire_id.'|'.$l->materiel_id;
            $chez[$cle]['utilisable'] = ($chez[$cle]['utilisable'] ?? 0) + max(0, (int) $l->utilisable - $transfere);
            $chez[$cle]['detenu'] = ($chez[$cle]['detenu'] ?? 0) + max(0, (int) $l->brut - $transfere);
        }

        return $chez;
    }

    /**
     * @param  list<int>|null  $ids
     * @return array<string, int>
     */
    private function reclamationsActives(Cycle $cycle, ?array $ids): array
    {
        $actives = [];
        $lignes = DB::table('reclamations')
            ->join('decharges', 'decharges.id', '=', 'reclamations.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('decharges.destinataire_id', $ids))
            ->whereIn('reclamations.statut', ['ouverte', 'en_traitement'])
            ->groupBy('decharges.destinataire_id', 'reclamations.materiel_id')
            ->selectRaw('decharges.destinataire_id as destinataire_id, reclamations.materiel_id as materiel_id, COUNT(*) as total')
            ->get();
        foreach ($lignes as $l) {
            $actives[$l->destinataire_id.'|'.$l->materiel_id] = (int) $l->total;
        }

        return $actives;
    }

    /**
     * Expédié par article pour le cycle : sorties d'expédition imputées aux réservations du cycle, nettes de leurs
     * compensations (même règle que BesoinService::expedie, pour tous les articles à la fois).
     *
     * @return array<int, int>
     */
    private function expedieParMouvements(Cycle $cycle): array
    {
        $sorties = fn () => MouvementStock::query()
            ->where('type', 'sortie_expedition')
            ->where('reference_type', (new ReservationStock)->getTable())
            ->whereIn('reference_id', ReservationStock::where('cycle_id', $cycle->id)->select('id'));

        $nets = MouvementStock::query()
            ->where(fn ($q) => $q
                ->whereIn('id', $sorties()->select('id'))
                ->orWhere(fn ($c) => $c->where('type', 'compensation')->whereIn('mouvement_compense_id', $sorties()->select('id'))))
            ->groupBy('materiel_id')
            ->selectRaw('materiel_id, SUM(quantite) as total')
            ->pluck('total', 'materiel_id');

        $expedies = [];
        foreach ($nets as $materielId => $total) {
            $expedies[(int) $materielId] = -(int) $total;
        }

        return $expedies;
    }
}
