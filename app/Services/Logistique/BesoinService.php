<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Commande;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\ReservationStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Ce qu'il faut expédier (plan de dotation approuvé), ce qui l'a été, la réserve centrale
 * visée et ce qu'il reste à approvisionner une fois le stock du cycle déduit.
 *
 * Convention de lecture de l'expédié : une sortie physique est un mouvement `sortie_expedition`
 * dont la référence est la réservation consommée (reference_type = table des réservations).
 * Toutes les quantités sont des entiers ; les décimaux (exact, réserve en %) passent par bcmath.
 */
class BesoinService
{
    private const ECHELLE = 6;

    private const MAX_TEXTE = 500;

    private const MAX_ENTIER = 4294967295;

    public function __construct(
        private readonly StockService $stock,
        private readonly CommandeService $commandes,
    ) {
    }

    /** Σ des dotations approuvées du plan en vigueur ; 0 sans plan approuvé. */
    public function besoinExpedition(Cycle $cycle, Materiel $materiel): int
    {
        $plan = PlanDotation::courantApprouve($cycle);

        return $plan ? $this->besoinDuPlan($plan, $materiel->id) : 0;
    }

    /**
     * Σ des sorties d'expédition imputées aux réservations du cycle pour cet article, nette des
     * compensations de ces sorties (la compensation n'a pas de référence : elle pointe la sortie
     * qu'elle annule).
     */
    public function expedie(Cycle $cycle, Materiel $materiel): int
    {
        $reservations = ReservationStock::where('cycle_id', $cycle->id)->where('materiel_id', $materiel->id)->select('id');
        $sorties = fn () => MouvementStock::where('materiel_id', $materiel->id)
            ->where('type', 'sortie_expedition')
            ->where('reference_type', (new ReservationStock)->getTable())
            ->whereIn('reference_id', $reservations);

        $net = (int) MouvementStock::where('materiel_id', $materiel->id)
            ->where(fn ($q) => $q
                ->whereIn('id', $sorties()->select('id'))
                ->orWhere(fn ($c) => $c->where('type', 'compensation')->whereIn('mouvement_compense_id', $sorties()->select('id'))))
            ->sum('quantite');

        return -$net;
    }

    public function resteAExpedier(Cycle $cycle, Materiel $materiel): int
    {
        return max(0, $this->besoinExpedition($cycle, $materiel) - $this->expedie($cycle, $materiel));
    }

    public function surplusExpedie(Cycle $cycle, Materiel $materiel): int
    {
        return max(0, $this->expedie($cycle, $materiel) - $this->besoinExpedition($cycle, $materiel));
    }

    /**
     * Réserve centrale visée : plafond de (Σ exact × réserve figée dans le plan / 100).
     * La base est l'exact, jamais la dotation majorée ; la réserve est celle du plan approuvé.
     */
    public function reserveCentraleCible(Cycle $cycle, Materiel $materiel): int
    {
        $plan = PlanDotation::courantApprouve($cycle);

        return $plan ? $this->reserveCibleDuPlan($plan, $materiel->id) : 0;
    }

    /** reste + réserve visée − réservé − attribué − à recevoir (spec 8.4), jamais négatif. */
    public function approvisionnementRestant(Cycle $cycle, Materiel $materiel): int
    {
        return $this->approvisionnement(
            $this->resteAExpedier($cycle, $materiel),
            $this->reserveCentraleCible($cycle, $materiel),
            $this->stock->reserve($materiel, $cycle),
            $this->stock->attribue($materiel, $cycle),
            $this->stock->commandeARecevoir($materiel, $cycle),
        );
    }

    /**
     * Une ligne par article du plan approuvé, dans l'ordre du catalogue.
     *
     * @return Collection<int, array{materiel: Materiel, besoin: int, expedie: int, reste: int, surplus: int, reserve_cible: int, reserve_stock: int, attribue: int, a_recevoir: int, approvisionnement: int}>
     */
    public function tableau(Cycle $cycle): Collection
    {
        $plan = PlanDotation::courantApprouve($cycle);
        if ($plan === null) {
            return collect();
        }

        $lignes = PlanDotationLigne::where('plan_dotation_id', $plan->id)->get()->groupBy('materiel_id');
        $materiels = Materiel::whereIn('id', $lignes->keys())->orderBy('ordre')->orderBy('libelle')->orderBy('id')->get();

        return $materiels->map(function (Materiel $materiel) use ($cycle, $plan, $lignes) {
            $besoin = (int) $lignes[$materiel->id]->sum('dotation_approuvee');
            $expedie = $this->expedie($cycle, $materiel);
            $reste = max(0, $besoin - $expedie);
            $reserveCible = $this->reserveCibleDesLignes($lignes[$materiel->id], $plan->parametres[$materiel->id]['reserve'] ?? null);
            $reserveStock = $this->stock->reserve($materiel, $cycle);
            $attribue = $this->stock->attribue($materiel, $cycle);
            $aRecevoir = $this->stock->commandeARecevoir($materiel, $cycle);

            return [
                'materiel'          => $materiel,
                'besoin'            => $besoin,
                'expedie'           => $expedie,
                'reste'             => $reste,
                'surplus'           => max(0, $expedie - $besoin),
                'reserve_cible'     => $reserveCible,
                'reserve_stock'     => $reserveStock,
                'attribue'          => $attribue,
                'a_recevoir'        => $aRecevoir,
                'approvisionnement' => $this->approvisionnement($reste, $reserveCible, $reserveStock, $attribue, $aRecevoir),
            ];
        })->values();
    }

    /**
     * Transforme la proposition en commande brouillon affectée au cycle.
     *
     * @param  array<string, mixed>  $entete  reference, fournisseur, date_commande
     * @param  array<int|string, mixed>  $quantites  materiel_id => quantité retenue
     */
    public function convertirEnCommande(Cycle $cycle, array $entete, array $quantites, ?string $motif): Commande
    {
        if (auth()->id() === null) {
            throw new LogicException('Conversion en commande sans auteur.');
        }
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut affecter une commande à un cycle.');
        }

        $retenues = $this->quantitesRetenues($quantites);
        if ($retenues === []) {
            throw new RegleLogistique('Rien à commander.');
        }

        $proposition = $this->tableau($cycle)
            ->filter(fn (array $ligne) => $ligne['approvisionnement'] > 0)
            ->mapWithKeys(fn (array $ligne) => [$ligne['materiel']->id => $ligne['approvisionnement']])
            ->all();

        $motif = $motif === null ? '' : trim($motif);
        if (mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est limité à 500 caractères.');
        }
        if ($motif === '' && $this->differe($proposition, $retenues)) {
            throw new InvalidArgumentException('Motif obligatoire : quantités différentes de la proposition.');
        }

        $lignes = [];
        foreach ($retenues as $materielId => $quantite) {
            $lignes[] = ['materiel_id' => $materielId, 'quantite_commandee' => $quantite, 'cycle_id' => $cycle->id];
        }

        return DB::transaction(function () use ($entete, $lignes, $proposition, $retenues, $motif) {
            $commande = $this->commandes->creer($entete, $lignes);

            Journal::noter('commande', $commande->id, 'proposition_commande', $proposition, $retenues, $motif === '' ? null : $motif);

            return $commande;
        }, attempts: 3);
    }

    private function besoinDuPlan(PlanDotation $plan, int $materielId): int
    {
        return (int) PlanDotationLigne::where('plan_dotation_id', $plan->id)
            ->where('materiel_id', $materielId)
            ->sum('dotation_approuvee');
    }

    private function reserveCibleDuPlan(PlanDotation $plan, int $materielId): int
    {
        $lignes = PlanDotationLigne::where('plan_dotation_id', $plan->id)->where('materiel_id', $materielId)->get();

        return $this->reserveCibleDesLignes($lignes, $plan->parametres[$materielId]['reserve'] ?? null);
    }

    /**
     * @param  Collection<int, PlanDotationLigne>  $lignes
     */
    private function reserveCibleDesLignes(Collection $lignes, mixed $reserve): int
    {
        $reserve = is_string($reserve) || is_int($reserve) ? trim((string) $reserve) : '';
        if (preg_match('/^\d+(\.\d+)?$/', $reserve) !== 1) {
            return 0;
        }

        $exact = '0';
        foreach ($lignes as $ligne) {
            $exact = bcadd($exact, (string) $ligne->exact, self::ECHELLE);
        }
        $cible = bcdiv(bcmul($exact, $reserve, self::ECHELLE), '100', self::ECHELLE);

        // Plafond : partie entière, plus un dès qu'il reste une fraction.
        $entier = bcadd($cible, '0', 0);

        return (int) (bccomp($cible, $entier, self::ECHELLE) > 0 ? bcadd($entier, '1', 0) : $entier);
    }

    private function approvisionnement(int $reste, int $reserveCible, int $reserve, int $attribue, int $aRecevoir): int
    {
        return max(0, $reste + $reserveCible - $reserve - $attribue - $aRecevoir);
    }

    /**
     * Quantités saisies, validées ; les quantités nulles sont ignorées.
     *
     * @param  array<int|string, mixed>  $quantites
     * @return array<int, int>  materiel_id => quantité strictement positive
     */
    private function quantitesRetenues(array $quantites): array
    {
        $retenues = [];
        foreach ($quantites as $materielId => $quantite) {
            if (! is_int($materielId) && ! (is_string($materielId) && ctype_digit($materielId))) {
                throw new InvalidArgumentException('Chaque quantité doit viser un article par son numéro (entier).');
            }
            $quantite = match (true) {
                is_int($quantite) => $quantite,
                is_string($quantite) && preg_match('/^-?\d+$/', trim($quantite)) === 1 => (int) trim($quantite),
                default => throw new InvalidArgumentException("La quantité de l'article {$materielId} doit être un entier."),
            };
            if ($quantite < 0 || $quantite > self::MAX_ENTIER) {
                throw new InvalidArgumentException("La quantité de l'article {$materielId} doit être un entier positif ou nul.");
            }
            if ($quantite > 0) {
                $retenues[(int) $materielId] = $quantite;
            }
        }
        ksort($retenues);

        return $retenues;
    }

    /**
     * Les deux jeux d'articles sont comparés sur leur union : une entrée absente vaut 0.
     *
     * @param  array<int, int>  $proposition
     * @param  array<int, int>  $retenues
     */
    private function differe(array $proposition, array $retenues): bool
    {
        foreach (array_unique([...array_keys($proposition), ...array_keys($retenues)]) as $materielId) {
            if (($proposition[$materielId] ?? 0) !== ($retenues[$materielId] ?? 0)) {
                return true;
            }
        }

        return false;
    }
}
