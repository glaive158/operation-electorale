<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\Remise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Arbitrage du responsable sur une déclaration à vérifier : il la retient (les autres déclarations actives de la
 * remise sont écartées) ou la rejette (la remise attend alors une nouvelle déclaration, ou le rapprochement des
 * déclarations restantes). Une déclaration n'est jamais modifiée dans ses quantités ni supprimée : seul son
 * statut change, et ses anomalies restent comme historique.
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation.
 */
class ArbitrageService
{
    private const MAX_TEXTE = 500;

    public function __construct(
        private readonly DeclarationService $declarations,
        private readonly EngagementService $engagement,
    ) {}

    public function retenir(Declaration $declaration, string $motif): void
    {
        $this->arbitrer($declaration, $motif, true);
    }

    public function rejeter(Declaration $declaration, string $motif): void
    {
        $this->arbitrer($declaration, $motif, false);
    }

    /**
     * Le responsable accepte un écart pour (cycle, destinataire, article) : une part est abandonnée à la
     * réception (le destinataire n'aura jamais reçu l'objectif), une part de l'expédition est annulée (le besoin
     * baisse, la proposition de commande aussi).
     *
     * - L'expédition annulée ne porte que sur le non engagé (besoin moins engagé validé) : une décharge validée
     *   non partie qui porte la quantité est annulée d'abord.
     * - L'abandon est plafonné au reste à réceptionner : objectif de réception (dotation moins les abandons déjà
     *   acceptés, sans les remplacements) moins le reçu utilisable retenu au destinataire final.
     */
    public function cloturerAvecEcart(Cycle $cycle, Destinataire $destinataire, Materiel $materiel, int $abandonReception, int $expeditionAnnulee, string $motif): Arbitrage
    {
        if (auth()->id() === null) {
            throw new LogicException('Clôture d\'écart sans auteur.');
        }
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut clôturer un écart.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }
        if ($abandonReception < 0 || $expeditionAnnulee < 0 || ($abandonReception === 0 && $expeditionAnnulee === 0)) {
            throw new InvalidArgumentException('Les quantités doivent être positives et au moins l\'une d\'elles non nulle.');
        }

        return DB::transaction(function () use ($cycle, $destinataire, $materiel, $abandonReception, $expeditionAnnulee, $motif) {
            $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if ($actuel === null) {
                throw new LogicException('Cycle introuvable : invariant rompu.');
            }
            if (! $actuel->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : clôture d\'écart impossible.');
            }

            // Chiffres relus sous le verrou du cycle : aucune écriture concurrente ne les déplace.
            $nonEngage = max(0, $this->engagement->besoin($actuel, $destinataire, $materiel) - $this->engagement->engage($actuel, $destinataire, $materiel));
            if ($expeditionAnnulee > $nonEngage) {
                throw new RegleLogistique("Expédition annulée supérieure au non engagé pour {$materiel->libelle} : non engagé {$nonEngage}. Annulez d'abord la décharge validée non partie.");
            }

            if ($abandonReception > 0 && $this->receptionAVerifier($actuel, $destinataire, $materiel)) {
                throw new RegleLogistique("Une réception est à vérifier pour {$materiel->libelle} : arbitrez-la avant d'accepter un écart.");
            }

            $reste = $this->resteAReceptionner($actuel, $destinataire, $materiel);
            if ($abandonReception > $reste) {
                throw new RegleLogistique("Abandon supérieur au reste à réceptionner pour {$materiel->libelle} : reste {$reste}.");
            }

            $arbitrage = Arbitrage::create([
                'cycle_id' => $actuel->id, 'objet' => 'cloture_ecart', 'destinataire_id' => $destinataire->id,
                'materiel_id' => $materiel->id, 'decision' => 'ecart_accepte',
                'quantite_abandon_reception' => $abandonReception, 'quantite_expedition_annulee' => $expeditionAnnulee,
                'motif' => $motif, 'user_id' => auth()->id(),
            ]);
            Journal::noter('arbitrage', $arbitrage->id, 'cloture_avec_ecart', null, [
                'cycle_id' => $actuel->id, 'destinataire_id' => $destinataire->id, 'materiel_id' => $materiel->id,
                'quantite_abandon_reception' => $abandonReception, 'quantite_expedition_annulee' => $expeditionAnnulee,
                'non_engage' => $nonEngage, 'reste_a_receptionner' => $reste,
            ], $motif);

            return $arbitrage;
        }, attempts: 3);
    }

    /**
     * Reste à réceptionner : objectif de réception moins le reçu utilisable retenu au destinataire final.
     * À lire sous le verrou du cycle.
     */
    public function resteAReceptionner(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        return max(0, $this->objectifReception($cycle, $destinataire, $materiel) - $this->recuUtilisableRetenu($cycle, $destinataire, $materiel));
    }

    /**
     * Une remise (livraison ou redistribution) d'une décharge du destinataire, portant l'article, attend un
     * arbitrage : le reçu n'est pas établi, et un litige au préfet peut devenir une redistribution au destinataire.
     * Le statut `a_verifier` exclut déjà les remises annulées.
     */
    private function receptionAVerifier(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): bool
    {
        return Remise::query()
            ->whereIn('decharge_id', Decharge::where('cycle_id', $cycle->id)->where('destinataire_id', $destinataire->id)->select('id'))
            ->where('statut', 'a_verifier')
            ->whereHas('lignes', fn ($q) => $q->where('materiel_id', $materiel->id))
            ->exists();
    }

    /**
     * Objectif de réception : dotation du plan approuvé moins les abandons déjà acceptés. Un remplacement
     * augmente le besoin d'expédition mais pas cet objectif.
     */
    private function objectifReception(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
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

    /**
     * Reçu utilisable (reçu moins défectueux) retenu au destinataire final : déclarations retenues des remises
     * non annulées adressées au destinataire, pour les décharges du cycle qui lui sont destinées (ce que le préfet
     * détient pour un sous-préfet ne compte pas pour le préfet). La tâche des indicateurs reprendra
     * ce calcul.
     */
    private function recuUtilisableRetenu(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        return (int) DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->join('decharges', 'decharges.id', '=', 'remises.decharge_id')
            ->where('decharges.cycle_id', $cycle->id)->where('decharges.destinataire_id', $destinataire->id)
            ->where('remises.a_type', 'destinataire')->where('remises.a_id', $destinataire->id)
            ->where('remises.statut', '!=', 'annulee')
            ->where('declarations.statut', 'retenue')
            ->where('declaration_lignes.materiel_id', $materiel->id)
            ->sum(DB::raw('declaration_lignes.recu - declaration_lignes.defectueux'));
    }

    private function arbitrer(Declaration $declaration, string $motif, bool $retenir): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Arbitrage sans auteur.');
        }
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut arbitrer une déclaration.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        $cycleId = Decharge::whereKey($declaration->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Déclaration sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($declaration, $motif, $retenir, $cycleId) {
            $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();
            // Pas de refus de cycle clos : l'arbitrage ne touche à aucun stock central et une réception non
            // arbitrée doit pouvoir l'être après la clôture.
            if ($cycle === null) {
                throw new LogicException('Cycle introuvable : invariant rompu.');
            }
            $decharge = Decharge::whereKey($declaration->decharge_id)->lockForUpdate()->firstOrFail();
            $remise = Remise::whereKey($declaration->remise_id)->lockForUpdate()->firstOrFail();
            $actives = Declaration::where('remise_id', $remise->id)->orderBy('id')->lockForUpdate()->get();
            $actuelle = $actives->firstWhere('id', $declaration->id);

            if ($actuelle === null || $remise->statut !== 'a_verifier' || ! in_array($actuelle->statut, ['a_verifier', 'valide'], true)) {
                throw new RegleLogistique('Rien à arbitrer pour cette déclaration.');
            }

            $avant = $actuelle->statut;
            $ecartees = [];
            if ($retenir) {
                foreach ($actives as $autre) {
                    if ($autre->id !== $actuelle->id && $autre->estActive()) {
                        $this->changerStatut($autre, 'ecartee', $remise);
                        $ecartees[] = $autre->id;
                    }
                }
            }
            $this->changerStatut($actuelle, $retenir ? 'retenue' : 'ecartee', $remise);

            if ($retenir) {
                $this->changerStatutRemise($remise, 'recue');
            } else {
                $restantes = Declaration::where('remise_id', $remise->id)->whereNotIn('statut', ['ecartee', 'remplacee'])->exists();
                if ($restantes) {
                    // Les déclarations restantes sont rapprochées de nouveau, sans statut inventé.
                    $this->declarations->rapprocher($remise);
                } else {
                    $this->changerStatutRemise($remise, 'en_attente');
                }
            }

            $decision = $retenir ? 'retenue' : 'ecartee';
            Arbitrage::create([
                'cycle_id' => $cycleId, 'objet' => 'declaration', 'remise_id' => $remise->id,
                'declaration_id' => $actuelle->id,
                'destinataire_id' => $remise->a_type === 'destinataire' && $remise->a_id !== null ? $remise->a_id : $decharge->destinataire_id,
                'decision' => $decision, 'motif' => $motif, 'user_id' => auth()->id(),
            ]);
            Journal::noter('declaration', $actuelle->id, 'arbitrage_declaration', ['statut' => $avant], [
                'statut' => $decision, 'decision' => $decision,
                'remise_id' => $remise->id, 'decharge_id' => $decharge->id, 'ecartees' => $ecartees,
            ], $motif);

            $this->declarations->apresRetenue($remise->refresh());
        }, attempts: 3);

        $declaration->refresh();
    }

    private function changerStatut(Declaration $declaration, string $statut, Remise $remise): void
    {
        $avant = $declaration->statut;
        $declaration->forceFill(['statut' => $statut])->save();
        Journal::noter('declaration', $declaration->id, 'statut_declaration', ['statut' => $avant], [
            'statut' => $statut, 'remise_id' => $remise->id, 'anomalies' => $declaration->anomalies ?? [],
        ]);
    }

    private function changerStatutRemise(Remise $remise, string $statut): void
    {
        $avant = $remise->statut;
        $remise->forceFill(['statut' => $statut])->save();
        Journal::noter('remise', $remise->id, 'statut_remise', ['statut' => $avant], ['statut' => $statut]);
    }
}
