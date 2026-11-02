<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
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

    private const RECEPTION_UTILISEE = 'Réception utilisée en aval : annulez la redistribution (ou traitez l\'exemplaire ou le remplacement) avant d\'arbitrer.';

    public function __construct(
        private readonly DeclarationService $declarations,
        private readonly EngagementService $engagement,
        private readonly IndicateurService $indicateurs,
        private readonly RemiseService $remises,
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
     * Le responsable lève la contestation d'un signataire (vérification faite auprès de lui). La déclaration
     * garde son statut : elle reste à arbitrer par retenir() ou rejeter() si elle l'attendait.
     */
    public function leverContestation(Declaration $declaration, string $motif): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Levée de contestation sans auteur.');
        }
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut lever une contestation.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        $cycleId = Decharge::whereKey($declaration->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Déclaration sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($declaration, $motif, $cycleId) {
            // Pas de refus de cycle clos : aucun stock n'est touché.
            if (Cycle::whereKey($cycleId)->lockForUpdate()->first() === null) {
                throw new LogicException('Cycle introuvable : invariant rompu.');
            }
            Decharge::whereKey($declaration->decharge_id)->lockForUpdate()->firstOrFail();
            Remise::whereKey($declaration->remise_id)->lockForUpdate()->firstOrFail();
            $actuelle = Declaration::whereKey($declaration->id)->lockForUpdate()->firstOrFail();

            if ($actuelle->contestation !== 'contestee') {
                throw new RegleLogistique('Aucune contestation à lever pour cette réception.');
            }

            $actuelle->forceFill(['contestation' => 'levee'])->save();
            Journal::noter('declaration', $actuelle->id, 'levee_contestation', ['contestation' => 'contestee'], [
                'contestation' => 'levee', 'statut' => $actuelle->statut, 'remise_id' => $actuelle->remise_id,
            ], $motif);
        }, attempts: 3);

        $declaration->refresh();
    }

    /**
     * Le responsable tranche une redistribution conservée « à arbitrer » (envoyée par un appareil au-delà de sa
     * réserve, ou par un appareil non désigné ou révoqué) : aucune quantité n'en compte avant sa décision.
     *
     * - `valider` : la remise passe en attente (le sous-préfet peut la déclarer) et ses quantités comptent comme
     *   transférées ; le plafond est relu sur le mobilisable de ce moment, et la validation est refusée s'il ne
     *   suffit plus (ou si le cycle est clos).
     * - `rejeter` : la remise est annulée avec le motif ; rien n'a été compté.
     */
    public function arbitrerRemise(Remise $remise, string $decision, string $motif): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Arbitrage sans auteur.');
        }
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut arbitrer une remise.');
        }
        if (! in_array($decision, ['valider', 'rejeter'], true)) {
            throw new InvalidArgumentException('La décision doit être « valider » ou « rejeter ».');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        $cycleId = Decharge::whereKey($remise->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Remise sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($remise, $decision, $motif, $cycleId) {
            $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();
            if ($cycle === null) {
                throw new LogicException('Cycle introuvable : invariant rompu.');
            }
            $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
            // La livraison précède toujours ses redistributions : verrouillée avant elles, dans l'ordre des identifiants.
            $livraison = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')
                ->where('statut', '!=', 'annulee')->orderBy('id')->lockForUpdate()->first();
            $actuelle = Remise::whereKey($remise->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->etape !== 'redistribution' || $actuelle->statut !== 'a_arbitrer') {
                throw new RegleLogistique('Rien à arbitrer pour cette remise.');
            }

            if ($decision === 'valider') {
                // Mêmes conditions qu'une redistribution en ligne, relues maintenant.
                if (! $cycle->estOuvert()) {
                    throw new RegleLogistique('Cycle clôturé : validation impossible.');
                }
                if ($decharge->statut_document !== 'validee') {
                    throw new RegleLogistique('Seule une décharge validée peut être redistribuée.');
                }
                if ($livraison?->statut === 'a_verifier') {
                    throw new RegleLogistique('Réception du préfet à vérifier : validation impossible.');
                }
                if ($livraison === null || ! Declaration::where('remise_id', $livraison->id)->where('statut', 'retenue')->exists()) {
                    throw new RegleLogistique("Le préfet n'a pas encore de réception retenue pour cette décharge.");
                }

                $solde = $this->remises->soldePrefet($decharge);
                foreach (RemiseLigne::where('remise_id', $actuelle->id)->orderBy('materiel_id')->get() as $ligne) {
                    $mobilisable = (int) ($solde->get($ligne->materiel_id)['mobilisable'] ?? 0);
                    if ($ligne->quantite > $mobilisable) {
                        $libelle = Materiel::find($ligne->materiel_id)?->libelle ?? "article {$ligne->materiel_id}";
                        throw new RegleLogistique("Redistribution supérieure au mobilisable du préfet pour {$libelle} : mobilisable {$mobilisable}, demandé {$ligne->quantite}.");
                    }
                }

                $actuelle->forceFill(['statut' => 'en_attente'])->save();
            } else {
                $actuelle->forceFill(['statut' => 'annulee', 'motif_annulation' => $motif])->save();
            }

            Journal::noter('remise', $actuelle->id, 'arbitrage_remise', ['statut' => 'a_arbitrer'], [
                'statut' => $actuelle->statut, 'decision' => $decision, 'decharge_id' => $decharge->id,
                'raison' => $actuelle->raison_arbitrage, 'appareil_declare' => $actuelle->appareil_declare,
            ], $motif);
        }, attempts: 3);

        $remise->refresh();
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
            // Un abandon seul ne déplace aucun stock et ne libère aucune réservation : il reste possible après la
            // clôture du cycle (les réclamations de terrain arrivent après l'élection).
            if ($expeditionAnnulee > 0 && ! $actuel->estOuvert()) {
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
     * Reste à réceptionner : objectif de réception moins le reçu utilisable retenu au destinataire final
     * (voir IndicateurService). À lire sous le verrou du cycle.
     */
    public function resteAReceptionner(Cycle $cycle, Destinataire $destinataire, Materiel $materiel): int
    {
        return $this->indicateurs->resteAReceptionner($cycle, $destinataire, $materiel);
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

            // Une retenue contestée dont la réception sert en aval est figée par le rapprochement : elle s'arbitre
            // même si la remise est reçue, mais pas au détriment de ce qui en dépend.
            $figee = $actuelle !== null && $actuelle->statut === 'retenue' && $actuelle->contestation === 'contestee';
            if ($actuelle === null || (! $figee && ($remise->statut !== 'a_verifier' || ! in_array($actuelle->statut, ['a_verifier', 'valide'], true)))) {
                throw new RegleLogistique('Rien à arbitrer pour cette déclaration.');
            }

            // Écarter une retenue contestée (la rejeter, ou en retenir une autre) défait la réception sur laquelle
            // reposent la redistribution, l'exemplaire de réception ou le remplacement : refusé tant qu'elle sert.
            $ecarteraUneFigee = $retenir
                ? $actives->contains(fn (Declaration $autre) => $autre->id !== $actuelle->id && $autre->statut === 'retenue' && $autre->contestation === 'contestee')
                : $figee;
            if ($ecarteraUneFigee && app(AvisReceptionService::class)->receptionUtilisee($remise, $decharge)) {
                throw new RegleLogistique(self::RECEPTION_UTILISEE);
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
            if ($figee && $retenir) {
                // Confirmer la retenue contestée : elle reste retenue et la contestation est levée.
                $actuelle->forceFill(['contestation' => 'levee'])->save();
                Journal::noter('declaration', $actuelle->id, 'levee_contestation', ['contestation' => 'contestee'], [
                    'contestation' => 'levee', 'statut' => 'retenue', 'remise_id' => $remise->id,
                ], $motif);
            } else {
                $this->changerStatut($actuelle, $retenir ? 'retenue' : 'ecartee', $remise);
            }

            if ($retenir) {
                if ($remise->statut !== 'recue') {
                    $this->changerStatutRemise($remise, 'recue');
                }
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
