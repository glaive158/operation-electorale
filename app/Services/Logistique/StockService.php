<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\AttributionStock;
use App\Models\Logistique\CommandeLigne;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\ReservationStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Grand livre de stock : le stock n'est jamais une colonne, c'est la somme
 * des mouvements immuables. Toute écriture verrouille la ligne du matériel
 * et vérifie le solde résultant dans la même transaction.
 *
 * Au-dessus du physique, deux engagements par cycle :
 *  - réservé : stock promis à des expéditions (réservations actives) ;
 *  - attribué : stock libre mis de côté pour un cycle ouvert.
 * Invariant tenu à chaque écriture : réservé + attribué (tous cycles) <= physique (état bon),
 * soit libre >= attribué. Une réservation ou une attribution ne dépasse jamais le libre, et un
 * mouvement négatif (sortie, compensation...) ne fait jamais passer le physique sous l'engagé.
 *
 * Ordre des verrous, identique partout pour éviter les interblocages :
 * cycle (partagé, exclusif pour la clôture) puis matériel(s), par id croissant.
 */
class StockService
{
    public function physique(Materiel $materiel, string $etat = 'bon'): int
    {
        return (int) MouvementStock::where('materiel_id', $materiel->id)
            ->where('etat', $etat)
            ->sum('quantite');
    }

    /**
     * Σ des réservations actives (d'un cycle, ou de tous). Lecture simple, pour l'affichage.
     */
    public function reserve(Materiel $materiel, ?Cycle $cycle = null): int
    {
        return (int) ReservationStock::where('materiel_id', $materiel->id)
            ->where('statut', 'active')
            ->when($cycle, fn ($q) => $q->where('cycle_id', $cycle->id))
            ->sum('quantite');
    }

    /**
     * Σ des attributions (d'un cycle, ou de tous les cycles ouverts). Lecture simple, pour l'affichage.
     */
    public function attribue(Materiel $materiel, ?Cycle $cycle = null): int
    {
        $requete = AttributionStock::where('materiel_id', $materiel->id);

        if ($cycle) {
            $requete->where('cycle_id', $cycle->id);
        } else {
            $requete->whereIn('cycle_id', Cycle::ouverts()->select('id'));
        }

        return (int) $requete->sum('quantite');
    }

    /**
     * Σ des restes à recevoir des commandes en cours (confirmées ou partiellement reçues),
     * pour ce matériel, éventuellement limitées aux lignes visant un cycle. Lecture simple.
     */
    public function commandeARecevoir(Materiel $materiel, ?Cycle $cycle = null): int
    {
        return CommandeLigne::where('materiel_id', $materiel->id)
            ->when($cycle, fn ($q) => $q->where('cycle_id', $cycle->id))
            ->whereHas('commande', fn ($q) => $q->whereIn('statut', ['confirmee', 'partiellement_recue']))
            ->get()
            ->sum(fn (CommandeLigne $ligne) => $ligne->resteARecevoir());
    }

    public function libre(Materiel $materiel): int
    {
        return $this->physique($materiel) - $this->reserve($materiel);
    }

    public function libreNonAttribue(Materiel $materiel): int
    {
        return $this->libre($materiel) - $this->attribue($materiel);
    }

    /**
     * Fixe la quantité totale attribuée au cycle pour ce matériel (remplace la précédente).
     */
    public function attribuer(Cycle $cycle, Materiel $materiel, int $quantite, string $motif): AttributionStock
    {
        $this->exigerAuteur();
        if ($quantite < 0) {
            throw new InvalidArgumentException('La quantité attribuée à un cycle ne peut pas être négative.');
        }

        // attempts: 3 — sous REPEATABLE READ (MySQL), les verrous d'intervalle des lectures
        // verrouillantes peuvent provoquer un interblocage (1213) ; Laravel rejoue alors la
        // transaction, uniquement lorsqu'elle est la plus externe.
        return DB::transaction(function () use ($cycle, $materiel, $quantite, $motif) {
            if (! $this->cycleVerrouille($cycle->id)->estOuvert()) {
                throw new RegleLogistique('Cycle clos : attribution impossible.');
            }
            Materiel::whereKey($materiel->id)->lockForUpdate()->firstOrFail();

            $attribution = $this->attributionVerrouillee($cycle->id, $materiel->id);
            $actuelle = $attribution?->quantite ?? 0;

            // Une baisse ne dégrade jamais l'invariant : elle reste permise même si le stock a fondu.
            if ($quantite > $actuelle) {
                $autres = $this->attribueVerrouille($materiel) - $actuelle;
                $libre = $this->libreVerrouille($materiel);
                if ($autres + $quantite > $libre) {
                    throw new StockInsuffisant(sprintf(
                        'Attribution impossible pour %s : demandé %d, attribuable %d.',
                        $materiel->libelle, $quantite, max(0, $libre - $autres),
                    ));
                }
            }

            if ($attribution) {
                $attribution->update(['quantite' => $quantite]);
            } else {
                $attribution = AttributionStock::create([
                    'cycle_id'    => $cycle->id,
                    'materiel_id' => $materiel->id,
                    'quantite'    => $quantite,
                ]);
            }

            Journal::noter('attribution_stock', $attribution->id, 'attribution', ['quantite' => $actuelle], ['quantite' => $quantite], $motif);

            return $attribution;
        }, attempts: 3);
    }

    /**
     * Réserve du stock pour une expédition du cycle : d'abord sur l'attribution
     * du cycle, le complément sur le stock libre non attribué.
     */
    public function reserver(Cycle $cycle, Materiel $materiel, int $quantite, ?Model $reference = null): ReservationStock
    {
        if ($quantite <= 0) {
            throw new InvalidArgumentException('La quantité réservée doit être strictement positive.');
        }

        $auteur = auth()->id();
        if ($auteur === null) {
            throw new LogicException('Réservation de stock sans auteur.');
        }

        return DB::transaction(function () use ($cycle, $materiel, $quantite, $reference, $auteur) {
            if (! $this->cycleVerrouille($cycle->id)->estOuvert()) {
                throw new RegleLogistique('Cycle clos : réservation impossible.');
            }
            Materiel::whereKey($materiel->id)->lockForUpdate()->firstOrFail();

            $attribution = $this->attributionVerrouillee($cycle->id, $materiel->id);
            $attribueCycle = $attribution?->quantite ?? 0;

            $surAttribution = min($quantite, $attribueCycle);
            $horsAttribution = $quantite - $surAttribution;
            $libreNonAttribue = $this->libreVerrouille($materiel) - $this->attribueVerrouille($materiel);

            if ($horsAttribution > $libreNonAttribue) {
                throw new StockInsuffisant(
                    "Stock disponible insuffisant pour {$materiel->libelle} : demandé {$quantite}, disponible "
                    . ($attribueCycle + $libreNonAttribue)
                );
            }

            if ($surAttribution > 0) {
                $attribution->update(['quantite' => $attribueCycle - $surAttribution]);
            }

            return ReservationStock::create([
                'cycle_id'                 => $cycle->id,
                'materiel_id'              => $materiel->id,
                'quantite'                 => $quantite,
                'quantite_sur_attribution' => $surAttribution,
                'statut'                   => 'active',
                'reference_type'           => $reference ? $reference->getTable() : null,
                'reference_id'             => $reference?->getKey(),
                'user_id'                  => $auteur,
            ]);
        }, attempts: 3);
    }

    /**
     * Libère une réservation active. Sauf retour explicite au stock commun, la part
     * prise sur l'attribution revient à l'attribution du cycle s'il est encore ouvert.
     */
    public function liberer(ReservationStock $reservation, string $motif, bool $versStockCommun = false): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($reservation, $motif, $versStockCommun) {
            $cycle = $this->cycleVerrouille($reservation->cycle_id);
            Materiel::whereKey($reservation->materiel_id)->lockForUpdate()->firstOrFail();

            $actuelle = ReservationStock::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! $actuelle->estActive()) {
                throw new RegleLogistique('Seule une réservation active peut être libérée.');
            }

            $actuelle->update(['statut' => 'liberee']);

            $rendu = 0;
            if (! $versStockCommun && $cycle->estOuvert() && $actuelle->quantite_sur_attribution > 0) {
                $rendu = $actuelle->quantite_sur_attribution;
                $attribution = $this->attributionVerrouillee($actuelle->cycle_id, $actuelle->materiel_id)
                    ?? new AttributionStock(['cycle_id' => $actuelle->cycle_id, 'materiel_id' => $actuelle->materiel_id, 'quantite' => 0]);
                $attribution->quantite += $rendu;
                $attribution->save();
            }

            Journal::noter(
                'reservation_stock', $actuelle->id, 'liberation',
                ['statut' => 'active'],
                ['statut' => 'liberee', 'rendu_a_l_attribution' => $rendu],
                $motif,
            );

            $reservation->setRawAttributes($actuelle->getAttributes(), true);
        }, attempts: 3);
    }

    /**
     * Consomme une réservation active : le stock réservé sort physiquement (sortie d'expédition, état bon,
     * référencée à la réservation, que BesoinService::expedie relit). La réservation est marquée consommée
     * AVANT le mouvement : le contrôle « physique >= engagé » ne compte plus le stock qui sort.
     */
    public function consommer(ReservationStock $reservation): MouvementStock
    {
        $this->exigerAuteur();

        return DB::transaction(function () use ($reservation) {
            if (! $this->cycleVerrouille($reservation->cycle_id)->estOuvert()) {
                throw new RegleLogistique('Cycle clos : consommation impossible.');
            }
            $materiel = Materiel::whereKey($reservation->materiel_id)->lockForUpdate()->firstOrFail();

            $actuelle = ReservationStock::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if (! $actuelle->estActive()) {
                throw new RegleLogistique('Seule une réservation active peut être consommée.');
            }

            $actuelle->update(['statut' => 'consommee']);

            $mouvement = $this->ecrire($materiel, 'sortie_expedition', -$actuelle->quantite, $actuelle, null, 'bon', null);

            Journal::noter(
                'reservation_stock', $actuelle->id, 'consommation',
                ['statut' => 'active'],
                ['statut' => 'consommee', 'mouvement_id' => $mouvement->id, 'quantite' => $actuelle->quantite],
            );

            $reservation->setRawAttributes($actuelle->getAttributes(), true);

            return $mouvement;
        }, attempts: 3);
    }

    /**
     * Clôt le cycle : ses réservations actives retournent au stock commun et ses
     * attributions tombent à 0. Aucun mouvement de stock : le physique est inchangé.
     */
    public function cloturerCycle(Cycle $cycle, string $motif): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($cycle, $motif) {
            $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if (! $actuel->estOuvert()) {
                throw new RegleLogistique('Ce cycle est déjà clos.');
            }

            // Une décharge validée qui n'est pas partie garde des réservations que la clôture libérerait
            // sous elle : on l'annule (ou on la charge) d'abord. Un brouillon ne réserve rien.
            $nonParties = Decharge::where('cycle_id', $actuel->id)
                ->where('statut_document', 'validee')->where('statut_acheminement', 'a_preparer')->count();
            if ($nonParties > 0) {
                throw new RegleLogistique("Clôture impossible : {$nonParties} décharge(s) validée(s) non partie(s) — annulez-les ou chargez-les d'abord.");
            }

            // Le cycle tenu en exclusif bloque toute écriture sur ses réservations et
            // attributions (elles passent par son verrou partagé) : la liste des matériels
            // concernés est stable. On les verrouille, par id croissant, avant d'écrire.
            $materielIds = ReservationStock::where('cycle_id', $actuel->id)->where('statut', 'active')->pluck('materiel_id')
                ->merge(AttributionStock::where('cycle_id', $actuel->id)->where('quantite', '>', 0)->pluck('materiel_id'))
                ->unique()->sort()->values();
            if ($materielIds->isNotEmpty()) {
                Materiel::whereIn('id', $materielIds)->orderBy('id')->lockForUpdate()->get();
            }

            $liberees = ReservationStock::where('cycle_id', $actuel->id)->where('statut', 'active')->update(['statut' => 'liberee']);
            $attributions = AttributionStock::where('cycle_id', $actuel->id)->where('quantite', '>', 0)->update(['quantite' => 0]);

            // statut et clos_le ne sont pas assignables en masse : la clôture n'a que ce chemin.
            $actuel->forceFill(['statut' => 'clos', 'clos_le' => now()])->save();

            Journal::noter(
                'cycle', $actuel->id, 'cloture',
                ['statut' => 'ouvert'],
                ['statut' => 'clos', 'reservations_liberees' => $liberees, 'attributions_remises_a_zero' => $attributions],
                $motif,
            );

            $cycle->setRawAttributes($actuel->getAttributes(), true);
        }, attempts: 3);
    }

    /**
     * Usage interne des services métier.
     */
    public function enregistrer(
        Materiel $materiel,
        string $type,
        int $quantite,
        ?Model $reference = null,
        ?string $motif = null,
        string $etat = 'bon',
    ): MouvementStock {
        if ($type === 'compensation') {
            throw new InvalidArgumentException('Une compensation se crée avec compenser().');
        }

        return $this->ecrire($materiel, $type, $quantite, $reference, $motif, $etat, null);
    }

    public function inventaireInitial(Materiel $materiel, int $quantite, string $motif): MouvementStock
    {
        if (trim($motif) === '') {
            throw new InvalidArgumentException("Un motif est obligatoire pour un inventaire initial.");
        }
        if ($quantite <= 0) {
            throw new InvalidArgumentException("La quantité d'un inventaire initial doit être strictement positive.");
        }

        return $this->ecrire($materiel, 'inventaire_initial', $quantite, null, $motif, 'bon', null);
    }

    public function compenser(MouvementStock $mouvement, string $motif): MouvementStock
    {
        if ($mouvement->type === 'compensation') {
            throw new RegleLogistique('Une compensation ne peut pas être elle-même compensée.');
        }
        if ($mouvement->reference_type === 'receptions_fournisseur') {
            throw new RegleLogistique('Mouvement issu d\'une réception fournisseur : corriger la réception, pas le stock.');
        }

        return DB::transaction(function () use ($mouvement, $motif) {
            $materiel = Materiel::whereKey($mouvement->materiel_id)->lockForUpdate()->firstOrFail();

            // Lecture verrouillante : voit les dernières lignes validées, même dans une transaction englobante.
            if (MouvementStock::where('mouvement_compense_id', $mouvement->id)->lockForUpdate()->exists()) {
                throw new RegleLogistique('Ce mouvement a déjà été compensé.');
            }

            $compensation = $this->ecrire(
                $materiel, 'compensation', -$mouvement->quantite, null, $motif, $mouvement->etat, $mouvement->id,
            );

            Journal::noter('mouvement_stock', $mouvement->id, 'compensation', null, null, $motif);

            return $compensation;
        }, attempts: 3);
    }

    private function ecrire(
        Materiel $materiel,
        string $type,
        int $quantite,
        ?Model $reference,
        ?string $motif,
        string $etat,
        ?int $compenseId,
    ): MouvementStock {
        if ($quantite === 0) {
            throw new InvalidArgumentException('La quantité d\'un mouvement de stock ne peut pas être nulle.');
        }
        if (! in_array($type, MouvementStock::TYPES, true)) {
            throw new InvalidArgumentException("Type de mouvement inconnu : {$type}.");
        }
        if (! in_array($etat, MouvementStock::ETATS, true)) {
            throw new InvalidArgumentException("État de stock inconnu : {$etat}.");
        }

        $auteur = auth()->id();
        if ($auteur === null) {
            throw new LogicException('Mouvement de stock sans auteur.');
        }

        return DB::transaction(function () use ($materiel, $type, $quantite, $reference, $motif, $etat, $compenseId, $auteur) {
            Materiel::whereKey($materiel->id)->lockForUpdate()->firstOrFail();

            $solde = $this->soldeVerrouille($materiel, $etat) + $quantite;
            if ($solde < 0) {
                throw new StockInsuffisant(sprintf(
                    'Stock insuffisant pour « %s » (état %s) : %d en stock, mouvement de %d.',
                    $materiel->libelle, $etat === 'bon' ? 'bon' : 'à réparer', $solde - $quantite, $quantite,
                ));
            }

            // Le physique bon ne descend jamais sous le stock engagé (réservé + attribué).
            if ($etat === 'bon' && $quantite < 0) {
                $reserve = $this->reserveVerrouille($materiel);
                $attribue = $this->attribueVerrouille($materiel);
                if ($solde < $reserve + $attribue) {
                    throw new StockInsuffisant(
                        "Stock engagé pour {$materiel->libelle} : réservé {$reserve}, attribué {$attribue}, "
                        . "physique après mouvement {$solde} — mouvement impossible."
                    );
                }
            }

            return MouvementStock::create([
                'materiel_id'           => $materiel->id,
                'type'                  => $type,
                'etat'                  => $etat,
                'quantite'              => $quantite,
                'reference_type'        => $reference ? $reference->getTable() : null,
                'reference_id'          => $reference?->getKey(),
                'mouvement_compense_id' => $compenseId,
                'motif'                 => $motif,
                'user_id'               => $auteur,
            ]);
        }, attempts: 3);
    }

    /**
     * Solde lu avec verrou : voit les dernières lignes validées, même si une
     * transaction englobante a déjà ouvert son instantané. Réservé au chemin d'écriture.
     */
    private function soldeVerrouille(Materiel $materiel, string $etat): int
    {
        return (int) MouvementStock::where('materiel_id', $materiel->id)
            ->where('etat', $etat)
            ->lockForUpdate()
            ->sum('quantite');
    }

    /**
     * Toute écriture d'engagement est imputable : sans utilisateur connecté, le journal serait anonyme.
     */
    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération de stock sans auteur.');
        }
    }

    /*
     * Lectures verrouillantes des engagements, à n'appeler qu'après le verrou de la
     * ligne du matériel : elles voient les dernières lignes validées même dans une
     * transaction englobante. Les méthodes publiques restent des lectures simples.
     */

    /**
     * Relit l'état du cycle en base sous verrou partagé : une clôture concurrente
     * (verrou exclusif) attend, et un modèle périmé en mémoire ne fait pas foi.
     */
    private function cycleVerrouille(int $cycleId): Cycle
    {
        return Cycle::whereKey($cycleId)->sharedLock()->firstOrFail();
    }

    private function attributionVerrouillee(int $cycleId, int $materielId): ?AttributionStock
    {
        return AttributionStock::where('cycle_id', $cycleId)
            ->where('materiel_id', $materielId)
            ->lockForUpdate()
            ->first();
    }

    private function reserveVerrouille(Materiel $materiel): int
    {
        return (int) ReservationStock::where('materiel_id', $materiel->id)
            ->where('statut', 'active')
            ->lockForUpdate()
            ->sum('quantite');
    }

    /**
     * Σ des attributions de tous les cycles. Un cycle clos a ses attributions à 0
     * (cloturerCycle) et n'en reçoit plus (attribuer) : le total égale celui des cycles
     * ouverts, sans relire les cycles sous verrou (ce qui inverserait l'ordre des verrous).
     * En cas d'écart, il ne peut que surestimer l'engagé, donc durcir le contrôle.
     */
    private function attribueVerrouille(Materiel $materiel): int
    {
        return (int) AttributionStock::where('materiel_id', $materiel->id)
            ->lockForUpdate()
            ->sum('quantite');
    }

    private function libreVerrouille(Materiel $materiel): int
    {
        return $this->soldeVerrouille($materiel, 'bon') - $this->reserveVerrouille($materiel);
    }
}
