<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\ReservationStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Décharges d'un cycle : préparation en brouillon d'après le plan de dotation approuvé et
 * ce qui reste à expédier, validation sous plafond du besoin, annulation avant départ et
 * remplacement. L'acheminement vient ensuite.
 *
 * Ordre des verrous : cycle, puis décharge.
 */
class DechargeService
{
    private const MAX_QUANTITE = 10_000_000;

    private const MAX_TEXTE = 500;

    private const AUCUN_PLAN = 'Aucun plan de dotation approuvé.';

    private const BROUILLON_EXISTANT = 'Une décharge brouillon existe déjà pour ce destinataire et cette catégorie.';

    private const RIEN_A_EXPEDIER = 'Rien à expédier pour cette catégorie.';

    public function __construct(
        private readonly EngagementService $engagements,
        private readonly CircuitService $circuits,
        private readonly StockService $stock,
    ) {}

    /** Prépare le brouillon d'un destinataire : une ligne par article de la catégorie dont il reste à expédier. */
    public function preparer(Cycle $cycle, Destinataire $destinataire, string $categorie): Decharge
    {
        $this->exigerAuteur();
        $this->exigerDroit();
        $this->exigerCategorie($categorie);

        return DB::transaction(function () use ($cycle, $destinataire, $categorie) {
            $actuel = $this->verrouillerCycleOuvert($cycle->id, 'Cycle clôturé : préparation de décharge impossible.');

            $plan = PlanDotation::courantApprouve($actuel);
            if ($plan === null) {
                throw new RegleLogistique(self::AUCUN_PLAN);
            }

            $resultat = $this->preparerPour($actuel, $plan, $destinataire, $categorie);
            if (is_string($resultat)) {
                throw new RegleLogistique($resultat);
            }

            return $resultat;
        }, attempts: 3);
    }

    public function modifierLigne(DechargeLigne $ligne, int $quantite, ?string $observations): void
    {
        $this->exigerAuteur();
        $this->exigerDroit();
        if ($quantite < 0 || $quantite > self::MAX_QUANTITE) {
            throw new InvalidArgumentException('La quantité doit être comprise entre 0 et '.number_format(self::MAX_QUANTITE, 0, ',', ' ').'.');
        }
        $observations = $observations === null ? null : trim($observations);
        $observations = $observations === '' ? null : $observations;
        if ($observations !== null && mb_strlen($observations) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Les observations ne doivent pas dépasser 500 caractères.');
        }

        DB::transaction(function () use ($ligne, $quantite, $observations) {
            $decharge = $this->decharge((int) $ligne->decharge_id);
            $this->verrouillerCycleOuvert((int) $decharge->cycle_id, 'Cycle clôturé : décharge non modifiable.');
            $decharge = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            $this->exigerBrouillon($decharge, 'Seul un brouillon est modifiable.');

            $actuelle = DechargeLigne::whereKey($ligne->id)->lockForUpdate()->firstOrFail();
            $avant = ['quantite' => $actuelle->quantite, 'observations' => $actuelle->observations];
            $actuelle->forceFill(['quantite' => $quantite, 'observations' => $observations])->save();

            Journal::noter('decharge', $decharge->id, 'modification_ligne_decharge', $avant + [
                'materiel_id' => $actuelle->materiel_id,
            ], [
                'quantite' => $quantite, 'observations' => $observations, 'materiel_id' => $actuelle->materiel_id,
            ]);
        }, attempts: 3);

        $ligne->refresh();
    }

    /** Supprime un brouillon et ses lignes ; le contenu supprimé reste au journal. */
    public function supprimerBrouillon(Decharge $decharge, string $motif): void
    {
        $this->exigerAuteur();
        $this->exigerDroit();
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        DB::transaction(function () use ($decharge, $motif) {
            $this->verrouillerCycleOuvert((int) $decharge->cycle_id, 'Cycle clôturé : décharge non modifiable.');
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            $this->exigerBrouillon($actuelle, 'Seul un brouillon peut être supprimé.');

            $lignes = DechargeLigne::where('decharge_id', $actuelle->id)->orderBy('id')->get();
            $avant = [
                'cycle_id' => $actuelle->cycle_id,
                'destinataire_id' => $actuelle->destinataire_id,
                'categorie' => $actuelle->categorie,
                'nature' => $actuelle->nature,
                'identifiant' => $actuelle->identifiant,
                'plan_dotation_id' => $actuelle->plan_dotation_id,
                'lignes' => $lignes->map(fn (DechargeLigne $l) => [
                    'materiel_id' => $l->materiel_id, 'dotation_approuvee' => $l->dotation_approuvee,
                    'quantite' => $l->quantite, 'cle_affichee' => $l->cle_affichee, 'observations' => $l->observations,
                ])->all(),
            ];

            DechargeLigne::where('decharge_id', $actuelle->id)->delete();
            $actuelle->delete();

            Journal::noter('decharge', $actuelle->id, 'suppression_decharge_brouillon', $avant, null, $motif);
        }, attempts: 3);
    }

    /**
     * Valide un brouillon : chaque ligne est contrôlée sous le plafond du besoin puis réservée en stock ;
     * le circuit, le destinataire de la remise, le numéro et les données du document sont figés.
     * Toute exception annule tout : aucune réservation, la décharge reste en brouillon.
     */
    public function valider(Decharge $decharge): void
    {
        $this->exigerAuteur();
        $this->exigerDroit();

        DB::transaction(function () use ($decharge) {
            $cycle = $this->verrouillerCycleOuvert((int) $decharge->cycle_id, 'Cycle clôturé : validation de décharge impossible.');
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            $this->exigerBrouillon($actuelle, 'Seul un brouillon peut être validé.');

            $plan = PlanDotation::courantApprouve($cycle);
            if ($plan === null) {
                throw new RegleLogistique(self::AUCUN_PLAN);
            }
            if ((int) $actuelle->plan_dotation_id !== (int) $plan->id) {
                throw new RegleLogistique('Le plan de dotation a changé : actualisez la décharge.');
            }

            $retirees = DechargeLigne::where('decharge_id', $actuelle->id)->where('quantite', 0)
                ->orderBy('materiel_id')->get(['materiel_id', 'dotation_approuvee'])
                ->map(fn (DechargeLigne $l) => ['materiel_id' => $l->materiel_id, 'dotation_approuvee' => $l->dotation_approuvee])->all();
            DechargeLigne::where('decharge_id', $actuelle->id)->where('quantite', 0)->delete();

            $lignes = DechargeLigne::where('decharge_id', $actuelle->id)->with('materiel')->orderBy('materiel_id')->get();
            if ($lignes->isEmpty()) {
                throw new RegleLogistique('Décharge sans quantité à expédier.');
            }

            $destinataire = Destinataire::findOrFail($actuelle->destinataire_id);
            $besoins = $this->engagements->etat($cycle, $destinataire, $actuelle->categorie);

            $validees = [];
            foreach ($lignes as $ligne) {
                $engage = $this->engagements->engage($cycle, $destinataire, $ligne->materiel);
                $besoin = $besoins->get($ligne->materiel_id)['dotation'] ?? 0;
                if ($engage + $ligne->quantite > $besoin) {
                    throw new RegleLogistique(
                        "Plafond du besoin dépassé pour {$ligne->materiel->libelle} : besoin {$besoin}, déjà engagé {$engage}, demandé {$ligne->quantite}."
                    );
                }

                $reservation = $this->stock->reserver($cycle, $ligne->materiel, $ligne->quantite, $ligne);
                $ligne->forceFill(['reservation_id' => $reservation->id, 'deja_engage' => $engage])->save();
                $validees[] = [
                    'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite,
                    'deja_engage' => $engage, 'dotation_approuvee' => $besoin, 'reservation_id' => $reservation->id,
                ];
            }

            $actuelle->forceFill([
                'circuit' => $this->circuits->circuitPour($cycle, $destinataire),
                'remis_a_id' => $this->circuits->remisA($cycle, $destinataire)->id,
                'numero' => $this->prochainNumero($cycle),
                'statut_document' => 'validee',
                'valide_par' => auth()->id(),
                'valide_le' => now(),
            ]);
            $actuelle->forceFill(['donnees_gelees' => DonneesDecharge::construire($actuelle)])->save();

            Journal::noter('decharge', $actuelle->id, 'validation_decharge', ['statut_document' => 'brouillon'], [
                'statut_document' => 'validee', 'numero' => $actuelle->numero, 'circuit' => $actuelle->circuit,
                'remis_a_id' => $actuelle->remis_a_id, 'plan_dotation_id' => $plan->id,
                'lignes' => $validees, 'lignes_retirees' => $retirees,
            ]);
        }, attempts: 3);

        $decharge->refresh();
    }

    /**
     * Annule une décharge validée qui n'est pas partie : ses réservations sont libérées (la part prise
     * sur l'attribution y retourne, sauf demande de retour au stock commun) et elle reste consultable.
     * Une décharge déjà partie ne s'annule pas : seule une réintégration physique remet du matériel en stock.
     */
    public function annuler(Decharge $decharge, string $motif, bool $versStockCommun = false): void
    {
        $this->exigerAuteur();
        $this->exigerDroitAdministrer();
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        DB::transaction(function () use ($decharge, $motif, $versStockCommun) {
            $this->verrouillerCycleOuvert((int) $decharge->cycle_id, 'Cycle clôturé : annulation de décharge impossible.');
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut_document !== 'validee') {
                throw new RegleLogistique('Seule une décharge validée peut être annulée.');
            }
            if ($actuelle->estPartie()) {
                throw new RegleLogistique('Décharge déjà partie : annulation impossible ; seule une réintégration physique remet du matériel en stock.');
            }

            // Même ordre que valider() (matériel croissant) pour les verrous de stock ; l'invariant est
            // vérifié sur toutes les lignes avant de libérer quoi que ce soit.
            $lignes = DechargeLigne::where('decharge_id', $actuelle->id)->orderBy('materiel_id')->get();
            if ($lignes->contains(fn (DechargeLigne $l) => $l->reservation_id === null)) {
                throw new LogicException('Ligne validée sans réservation : invariant rompu.');
            }

            $liberees = [];
            foreach ($lignes as $ligne) {
                $this->stock->liberer(ReservationStock::findOrFail($ligne->reservation_id), $motif, $versStockCommun);
                $liberees[] = [
                    'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite, 'reservation_id' => $ligne->reservation_id,
                ];
            }

            $actuelle->forceFill([
                'statut_document' => 'annulee',
                'annule_par' => auth()->id(),
                'annule_le' => now(),
                'motif_annulation' => $motif,
            ])->save();

            Journal::noter('decharge', $actuelle->id, 'annulation_decharge', ['statut_document' => 'validee'], [
                'statut_document' => 'annulee', 'numero' => $actuelle->numero,
                'vers_stock_commun' => $versStockCommun, 'lignes' => $liberees,
            ], $motif);
        }, attempts: 3);

        $decharge->refresh();
    }

    /**
     * Crée le brouillon qui remplace une décharge annulée : mêmes cycle, destinataire, catégorie et
     * nature, plan approuvé courant ; chaque quantité est plafonnée au reste à expédier (les lignes à 0
     * sont omises). L'annulée reste consultable et reliée à sa remplaçante.
     */
    public function remplacer(Decharge $annulee): Decharge
    {
        $this->exigerAuteur();
        $this->exigerDroit();

        return DB::transaction(function () use ($annulee) {
            $cycle = $this->verrouillerCycleOuvert((int) $annulee->cycle_id, 'Cycle clôturé : remplacement de décharge impossible.');
            $origine = Decharge::whereKey($annulee->id)->lockForUpdate()->firstOrFail();
            if ($origine->statut_document !== 'annulee') {
                throw new RegleLogistique('Seule une décharge annulée peut être remplacée.');
            }
            if (Decharge::where('decharge_remplacee_id', $origine->id)->where('statut_document', '!=', 'annulee')->exists()) {
                throw new RegleLogistique('Cette décharge a déjà une remplaçante.');
            }

            $plan = PlanDotation::courantApprouve($cycle);
            if ($plan === null) {
                throw new RegleLogistique(self::AUCUN_PLAN);
            }

            $destinataire = Destinataire::findOrFail($origine->destinataire_id);
            if ($this->brouillonExiste($cycle, $destinataire, $origine->categorie)) {
                throw new RegleLogistique(self::BROUILLON_EXISTANT);
            }

            $etat = $this->engagements->etat($cycle, $destinataire, $origine->categorie);
            $lignes = [];
            foreach (DechargeLigne::where('decharge_id', $origine->id)->orderBy('id')->get() as $ancienne) {
                $entree = $etat->get($ancienne->materiel_id);
                $quantite = min($ancienne->quantite, $entree['reste'] ?? 0);
                if ($quantite > 0) {
                    $lignes[] = ['entree' => $entree, 'materiel_id' => $ancienne->materiel_id, 'quantite' => $quantite, 'ancienne' => $ancienne->quantite];
                }
            }
            if ($lignes === []) {
                throw new RegleLogistique(self::RIEN_A_EXPEDIER);
            }

            $decharge = Decharge::create([
                'cycle_id' => $cycle->id,
                'destinataire_id' => $destinataire->id,
                'categorie' => $origine->categorie,
                'nature' => $origine->nature,
                'decharge_remplacee_id' => $origine->id,
                'plan_dotation_id' => $plan->id,
                'prepare_par' => auth()->id(),
            ]);

            $journal = [];
            foreach ($lignes as $l) {
                DechargeLigne::create([
                    'decharge_id' => $decharge->id,
                    'materiel_id' => $l['materiel_id'],
                    'dotation_approuvee' => $l['entree']['dotation'],
                    'quantite' => $l['quantite'],
                    'cle_affichee' => $this->cleAffichee($plan, $l['materiel_id']),
                ]);
                $journal[] = ['materiel_id' => $l['materiel_id'], 'quantite' => $l['quantite'], 'quantite_annulee' => $l['ancienne']];
            }

            Journal::noter('decharge', $decharge->id, 'remplacement_decharge', null, [
                'decharge_remplacee_id' => $origine->id, 'cycle_id' => $cycle->id, 'destinataire_id' => $destinataire->id,
                'categorie' => $decharge->categorie, 'nature' => $decharge->nature,
                'plan_dotation_id' => $plan->id, 'lignes' => $journal,
            ]);

            return $decharge;
        }, attempts: 3);
    }

    /**
     * Rattache un brouillon au plan approuvé courant : dotation et clé mises à jour, articles
     * disparus retirés, nouveaux articles ajoutés pour leur reste. Les quantités existantes sont conservées.
     */
    public function actualiser(Decharge $decharge): void
    {
        $this->exigerAuteur();
        $this->exigerDroit();

        DB::transaction(function () use ($decharge) {
            $cycle = $this->verrouillerCycleOuvert((int) $decharge->cycle_id, 'Cycle clôturé : décharge non modifiable.');
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            $this->exigerBrouillon($actuelle, 'Seul un brouillon peut être actualisé.');

            $plan = PlanDotation::courantApprouve($cycle);
            if ($plan === null) {
                throw new RegleLogistique(self::AUCUN_PLAN);
            }

            $destinataire = Destinataire::findOrFail($actuelle->destinataire_id);
            $etat = $this->engagements->etat($cycle, $destinataire, $actuelle->categorie);
            $existantes = DechargeLigne::where('decharge_id', $actuelle->id)->orderBy('id')->get();
            $planAvant = (int) $actuelle->plan_dotation_id;

            $retirees = [];
            $miseAJour = [];
            foreach ($existantes as $ligne) {
                $entree = $etat->get($ligne->materiel_id);
                if ($entree === null) {
                    $retirees[] = ['materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite];
                    $ligne->delete();

                    continue;
                }
                $cle = $this->cleAffichee($plan, $ligne->materiel_id);
                if ($ligne->dotation_approuvee !== $entree['dotation'] || $ligne->cle_affichee !== $cle) {
                    $miseAJour[] = [
                        'materiel_id' => $ligne->materiel_id,
                        'dotation_approuvee' => ['avant' => $ligne->dotation_approuvee, 'apres' => $entree['dotation']],
                    ];
                }
                $ligne->forceFill(['dotation_approuvee' => $entree['dotation'], 'cle_affichee' => $cle])->save();
            }

            $presentes = $existantes->pluck('materiel_id')->flip();
            $ajoutees = [];
            foreach ($etat as $materielId => $entree) {
                if ($presentes->has($materielId) || $entree['reste'] <= 0) {
                    continue;
                }
                DechargeLigne::create([
                    'decharge_id' => $actuelle->id, 'materiel_id' => $materielId, 'dotation_approuvee' => $entree['dotation'],
                    'quantite' => $entree['reste'], 'cle_affichee' => $this->cleAffichee($plan, $materielId),
                ]);
                $ajoutees[] = ['materiel_id' => $materielId, 'quantite' => $entree['reste']];
            }

            $actuelle->forceFill(['plan_dotation_id' => $plan->id])->save();

            Journal::noter('decharge', $actuelle->id, 'actualisation_decharge', [
                'plan_dotation_id' => $planAvant,
            ], [
                'plan_dotation_id' => $plan->id, 'lignes_ajoutees' => $ajoutees, 'lignes_retirees' => $retirees,
                'lignes_mises_a_jour' => $miseAJour,
            ]);
        }, attempts: 3);

        $decharge->refresh();
    }

    /**
     * Prépare un brouillon par destinataire du périmètre choisi qui a quelque chose à expédier.
     * Un destinataire qui a déjà un brouillon, ou rien à expédier, est compté comme ignoré.
     *
     * @param  array{departement_id?:int, region_id?:int, etranger?:bool}  $filtre  au plus une clé ; vide = tous les destinataires actifs
     * @return array{creees:int, ignorees:int}
     */
    public function preparerLot(Cycle $cycle, string $categorie, array $filtre): array
    {
        $this->exigerAuteur();
        $this->exigerDroit();
        $this->exigerCategorie($categorie);
        $filtre = $this->filtreValide($filtre);

        return DB::transaction(function () use ($cycle, $categorie, $filtre) {
            $actuel = $this->verrouillerCycleOuvert($cycle->id, 'Cycle clôturé : préparation de décharge impossible.');

            $plan = PlanDotation::courantApprouve($actuel);
            if ($plan === null) {
                throw new RegleLogistique(self::AUCUN_PLAN);
            }

            $creees = 0;
            $ignorees = 0;
            foreach ($this->destinatairesDuPerimetre($filtre) as $destinataire) {
                if (is_string($this->preparerPour($actuel, $plan, $destinataire, $categorie))) {
                    $ignorees++;
                } else {
                    $creees++;
                }
            }

            Journal::noter('cycle', $actuel->id, 'preparation_lot_decharges', null, [
                'categorie' => $categorie, 'filtre' => $filtre, 'creees' => $creees, 'ignorees' => $ignorees,
            ]);

            return ['creees' => $creees, 'ignorees' => $ignorees];
        }, attempts: 3);
    }

    /**
     * Crée le brouillon, ou renvoie le motif du refus (brouillon existant, rien à expédier).
     * À appeler dans une transaction qui tient le verrou du cycle.
     */
    private function preparerPour(Cycle $cycle, PlanDotation $plan, Destinataire $destinataire, string $categorie): Decharge|string
    {
        if ($this->brouillonExiste($cycle, $destinataire, $categorie)) {
            return self::BROUILLON_EXISTANT;
        }

        $aExpedier = $this->engagements->etat($cycle, $destinataire, $categorie)->filter(fn (array $e) => $e['reste'] > 0);
        if ($aExpedier->isEmpty()) {
            return self::RIEN_A_EXPEDIER;
        }

        $dejaValidee = Decharge::where('cycle_id', $cycle->id)->where('destinataire_id', $destinataire->id)
            ->where('categorie', $categorie)->where('statut_document', 'validee')->exists();

        $decharge = Decharge::create([
            'cycle_id' => $cycle->id,
            'destinataire_id' => $destinataire->id,
            'categorie' => $categorie,
            'nature' => $dejaValidee ? 'reliquat' : 'initiale',
            'plan_dotation_id' => $plan->id,
            'prepare_par' => auth()->id(),
        ]);

        foreach ($aExpedier as $materielId => $entree) {
            DechargeLigne::create([
                'decharge_id' => $decharge->id,
                'materiel_id' => $materielId,
                'dotation_approuvee' => $entree['dotation'],
                'quantite' => $entree['reste'],
                'cle_affichee' => $this->cleAffichee($plan, $materielId),
            ]);
        }

        Journal::noter('decharge', $decharge->id, 'preparation_decharge', null, [
            'cycle_id' => $cycle->id, 'destinataire_id' => $destinataire->id, 'categorie' => $categorie,
            'nature' => $decharge->nature, 'plan_dotation_id' => $plan->id, 'lignes' => $aExpedier->count(),
        ]);

        return $decharge;
    }

    private function brouillonExiste(Cycle $cycle, Destinataire $destinataire, string $categorie): bool
    {
        return Decharge::where('cycle_id', $cycle->id)->where('destinataire_id', $destinataire->id)
            ->where('categorie', $categorie)->where('statut_document', 'brouillon')->exists();
    }

    /**
     * Filtre géographique d'un lot, débarrassé de ses valeurs vides. Au plus un critère parmi
     * departement_id, region_id, etranger (true seulement) ; vide = tout le périmètre.
     *
     * @param  array<string, mixed>  $filtre
     * @return array{departement_id?:int, region_id?:int, etranger?:bool}
     */
    public function filtreValide(array $filtre): array
    {
        $filtre = array_filter($filtre, fn ($v) => $v !== null && $v !== '');
        if (count($filtre) > 1 || array_diff(array_keys($filtre), ['departement_id', 'region_id', 'etranger']) !== []) {
            throw new InvalidArgumentException('Filtre inconnu : un seul critère parmi departement_id, region_id, etranger.');
        }
        if (isset($filtre['etranger']) && $filtre['etranger'] !== true) {
            throw new InvalidArgumentException('Le filtre « etranger » ne vaut que pour true.');
        }

        return $filtre;
    }

    /**
     * Destinataires du périmètre choisi (filtre déjà validé par filtreValide). Une sous-préfecture n'a pas
     * de département propre : elle suit celui de sa préfecture. L'étranger se limite aux postes.
     * Les inactifs sont exclus par défaut (préparation d'un lot) ; le téléchargement en lot les inclut,
     * leurs documents existant déjà.
     *
     * @param  array{departement_id?:int, region_id?:int, etranger?:bool}  $filtre
     * @return Collection<int, Destinataire>
     */
    public function destinatairesDuPerimetre(array $filtre, bool $inclureInactifs = false): Collection
    {
        $requete = Destinataire::query()->orderBy('id');
        if (! $inclureInactifs) {
            $requete->actifs();
        }

        $departements = null;
        if (isset($filtre['departement_id'])) {
            $departements = [(int) $filtre['departement_id']];
        } elseif (isset($filtre['region_id'])) {
            $departements = app(LectureRecensement::class)->departements()
                ->where('region_id', (int) $filtre['region_id'])->pluck('id')->all();
        } elseif (isset($filtre['etranger'])) {
            $requete->where('type', 'poste');
        }

        if ($departements !== null) {
            $requete->where(function ($q) use ($departements) {
                $q->whereIn('departement_id', $departements)
                    ->orWhereIn('prefecture_id', Destinataire::whereIn('departement_id', $departements)->select('id'));
            });
        }

        return $requete->get();
    }

    /** À appeler sous le verrou du cycle : 1 + le plus grand rang déjà attribué (les annulées gardent le leur). */
    private function prochainNumero(Cycle $cycle): string
    {
        $dernier = Decharge::where('cycle_id', $cycle->id)->whereNotNull('numero')->pluck('numero')
            ->map(fn (string $numero) => (int) substr($numero, strrpos($numero, '-') + 1))->max() ?? 0;

        return sprintf('%d-%05d', $cycle->id, $dernier + 1);
    }

    private function cleAffichee(PlanDotation $plan, int $materielId): ?string
    {
        $cle = $plan->parametres[$materielId]['cle_affichee'] ?? null;

        return $cle === null ? null : mb_substr((string) $cle, 0, 150);
    }

    private function decharge(int $id): Decharge
    {
        return Decharge::findOrFail($id);
    }

    private function exigerBrouillon(Decharge $decharge, string $message): void
    {
        if (! $decharge->estBrouillon()) {
            throw new RegleLogistique($message);
        }
    }

    private function exigerCategorie(string $categorie): void
    {
        if (! array_key_exists($categorie, Decharge::CATEGORIES)) {
            throw new InvalidArgumentException('Catégorie inconnue.');
        }
    }

    /** À appeler dans une transaction : verrouille le cycle et refuse s'il est clos. */
    private function verrouillerCycleOuvert(int $cycleId, string $message): Cycle
    {
        $actuel = Cycle::whereKey($cycleId)->lockForUpdate()->first();
        if ($actuel === null || ! $actuel->estOuvert()) {
            throw new RegleLogistique($message);
        }

        return $actuel;
    }

    private function exigerDroit(): void
    {
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut gérer les décharges.');
        }
    }

    private function exigerDroitAdministrer(): void
    {
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut annuler une décharge.');
        }
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
