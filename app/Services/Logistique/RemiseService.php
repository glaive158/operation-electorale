<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
use App\Models\Logistique\ReservationStock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Remises d'une décharge : le chargement au magasin consomme les réservations (le stock sort) et crée la remise
 * de chargement (magasin vers l'agent ou le transporteur) et la remise de livraison (vers le destinataire).
 * Dans le circuit via le préfet, celui-ci redistribue au sous-préfet une part de ce qu'il a reçu en bon état.
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation.
 */
class RemiseService
{
    private const HORS_PERIMETRE = 'Redistribution hors de votre périmètre.';

    private const AFFECTER = 'Affectez un agent (ou, à l\'étranger, un transporteur) avant le chargement.';

    public function __construct(private readonly StockService $stock, private readonly PerimetreTerrain $perimetre) {}

    /**
     * Charge une décharge validée qui n'est pas partie. Hors circuit étranger, un agent doit être affecté ;
     * à l'étranger, un transporteur peut le remplacer : nom, qualité, société (150 caractères au plus) et
     * numéro de bordereau (50 au plus), tous requis. Renvoie la remise de chargement.
     *
     * @param  array{nom?: string, qualite?: string, societe?: string, bordereau?: string}  $transporteur
     */
    public function charger(Decharge $decharge, array $transporteur = []): Remise
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut charger une décharge.');
        }

        $remise = DB::transaction(function () use ($decharge, $transporteur) {
            $cycle = Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : chargement impossible.');
            }
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut_document !== 'validee' || $actuelle->statut_acheminement !== 'a_preparer') {
                throw new RegleLogistique('Seule une décharge validée non partie peut être chargée.');
            }

            $porteur = $this->porteur($actuelle, $transporteur);

            // Mêmes ordres que valider() et annuler() : articles par identifiant croissant.
            $lignes = DechargeLigne::where('decharge_id', $actuelle->id)->orderBy('materiel_id')->get();
            if ($lignes->isEmpty()) {
                throw new LogicException('Décharge validée sans ligne : invariant rompu.');
            }
            foreach ($lignes as $ligne) {
                $reservation = $ligne->reservation_id === null ? null : ReservationStock::find($ligne->reservation_id);
                if ($reservation === null || ! $reservation->estActive()
                    || (int) $reservation->quantite !== (int) $ligne->quantite
                    || (int) $reservation->materiel_id !== (int) $ligne->materiel_id) {
                    throw new LogicException("Ligne validée sans réservation active de même quantité (article {$ligne->materiel_id}) : invariant rompu.");
                }
            }

            $consommees = [];
            foreach ($lignes as $ligne) {
                $mouvement = $this->stock->consommer(ReservationStock::findOrFail($ligne->reservation_id));
                $consommees[] = [
                    'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite,
                    'reservation_id' => $ligne->reservation_id, 'mouvement_id' => $mouvement->id,
                ];
            }

            $chargement = $this->creer($actuelle, 'chargement', 'magasin', null, $porteur['type'], $porteur['id'], 'recue', $porteur['transporteur'], $lignes);
            $this->creer($actuelle, 'livraison', $porteur['type'], $porteur['id'], 'destinataire', (int) $actuelle->remis_a_id, 'en_attente', $porteur['transporteur'], $lignes);

            $actuelle->forceFill(['statut_acheminement' => 'chargee'])->save();

            Journal::noter('decharge', $actuelle->id, 'chargement_decharge', ['statut_acheminement' => 'a_preparer'], [
                'statut_acheminement' => 'chargee', 'numero' => $actuelle->numero,
                'porteur' => $porteur['type'], 'agent_id' => $porteur['type'] === 'agent' ? $porteur['id'] : null,
                'transporteur' => $porteur['transporteur'], 'remise_chargement_id' => $chargement->id,
                'lignes' => $consommees,
            ]);

            return $chargement;
        }, attempts: 3);

        $decharge->refresh();

        return $remise->load('lignes');
    }

    /**
     * Qui prend le matériel : le transporteur (circuit étranger, s'il est renseigné) ou l'agent affecté.
     *
     * @return array{type: string, id: ?int, transporteur: array<string, string>}
     */
    private function porteur(Decharge $decharge, array $transporteur): array
    {
        // Un champ blanc compte comme absent.
        $transporteur = array_filter(
            array_map(fn ($valeur) => is_string($valeur) ? trim($valeur) : $valeur, $transporteur),
            fn ($valeur) => $valeur !== null && $valeur !== '',
        );

        if ($transporteur !== []) {
            if ($decharge->circuit !== 'etranger') {
                throw new InvalidArgumentException('Un transporteur ne se renseigne que pour une décharge du circuit étranger.');
            }

            $champs = ['nom' => 150, 'qualite' => 150, 'societe' => 150, 'bordereau' => 50];
            $retenu = [];
            foreach ($champs as $cle => $max) {
                $valeur = $transporteur[$cle] ?? null;
                $valeur = is_string($valeur) ? trim($valeur) : '';
                if ($valeur === '' || mb_strlen($valeur) > $max) {
                    throw new RegleLogistique(self::AFFECTER);
                }
                $retenu[$cle] = $valeur;
            }

            return ['type' => 'transporteur', 'id' => null, 'transporteur' => $retenu];
        }

        $agent = $decharge->agent_id === null ? null : User::find($decharge->agent_id);
        if ($agent === null || $agent->role !== 'agent_transport' || ! $agent->actif) {
            throw new RegleLogistique(self::AFFECTER);
        }

        return ['type' => 'agent', 'id' => (int) $agent->id, 'transporteur' => []];
    }

    /**
     * @param  array<string, string>  $transporteur
     * @param  iterable<DechargeLigne>  $lignes
     */
    private function creer(Decharge $decharge, string $etape, string $deType, ?int $deId, string $aType, ?int $aId, string $statut, array $transporteur, iterable $lignes): Remise
    {
        $remise = new Remise([
            'decharge_id' => $decharge->id, 'etape' => $etape,
            'de_type' => $deType, 'de_id' => $deId, 'a_type' => $aType, 'a_id' => $aId,
            'transporteur_nom' => $transporteur['nom'] ?? null,
            'transporteur_qualite' => $transporteur['qualite'] ?? null,
            'transporteur_societe' => $transporteur['societe'] ?? null,
            'bordereau_numero' => $transporteur['bordereau'] ?? null,
            'preparee_par' => auth()->id(),
        ]);
        $remise->forceFill(['statut' => $statut])->save();

        foreach ($lignes as $ligne) {
            RemiseLigne::create(['remise_id' => $remise->id, 'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite]);
        }

        return $remise;
    }

    /**
     * Solde du préfet par article de la décharge : reçu utilisable (reçu moins défectueux de la déclaration
     * retenue de la livraison), transféré (redistributions non annulées) et reliquat. Trois requêtes, quel que
     * soit le nombre d'articles. Pour un chiffre qui sert à une décision, l'appelant tient les verrous de la
     * décharge et de la livraison.
     *
     * @return Collection<int, array{recu_utilisable: int, transfere: int, reliquat: int}> par identifiant d'article
     */
    public function soldePrefet(Decharge $decharge): Collection
    {
        $articles = DechargeLigne::where('decharge_id', $decharge->id)->orderBy('materiel_id')->pluck('materiel_id');

        $recus = DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->where('remises.decharge_id', $decharge->id)->where('remises.etape', 'livraison')
            ->where('remises.statut', '!=', 'annulee')->where('declarations.statut', 'retenue')
            ->selectRaw('declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu - declaration_lignes.defectueux) as utilisable')
            ->groupBy('declaration_lignes.materiel_id')->pluck('utilisable', 'materiel_id');

        $transferts = DB::table('remise_lignes')
            ->join('remises', 'remises.id', '=', 'remise_lignes.remise_id')
            ->where('remises.decharge_id', $decharge->id)->where('remises.etape', 'redistribution')
            ->where('remises.statut', '!=', 'annulee')
            ->selectRaw('remise_lignes.materiel_id as materiel_id, SUM(remise_lignes.quantite) as transfere')
            ->groupBy('remise_lignes.materiel_id')->pluck('transfere', 'materiel_id');

        return $articles->mapWithKeys(function ($materielId) use ($recus, $transferts) {
            $utilisable = (int) ($recus[$materielId] ?? 0);
            $transfere = (int) ($transferts[$materielId] ?? 0);

            return [(int) $materielId => ['recu_utilisable' => $utilisable, 'transfere' => $transfere, 'reliquat' => $utilisable - $transfere]];
        });
    }

    /**
     * Le préfet remet au sous-préfet une part de ce qu'il a reçu en bon état (circuit via le préfet). La
     * quantité est plafonnée, par article, au reçu utilisable de la déclaration retenue moins ce qui est déjà
     * transféré. Aucun mouvement de stock : le matériel est déjà sorti du magasin. Renvoie la remise.
     *
     * @param  array<int, int|string>  $lignes  quantité par identifiant d'article ; les zéros sont omis
     */
    public function preparerRedistribution(Decharge $decharge, array $lignes): Remise
    {
        $this->exigerAuteur();
        $auteur = auth()->user();
        $lignes = $this->quantitesRedistribuees($lignes);

        $remise = DB::transaction(function () use ($decharge, $lignes, $auteur) {
            $cycle = Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            $prefecture = $this->prefectureQuiRedistribue($actuelle, $auteur);

            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : redistribution impossible.');
            }
            if ($actuelle->statut_document !== 'validee') {
                throw new RegleLogistique('Seule une décharge validée peut être redistribuée.');
            }

            $livraison = Remise::where('decharge_id', $actuelle->id)->where('etape', 'livraison')
                ->where('statut', '!=', 'annulee')->lockForUpdate()->first();
            if ($livraison?->statut === 'a_verifier') {
                throw new RegleLogistique('Réception du préfet à vérifier : redistribution impossible.');
            }
            $retenue = $livraison !== null && Declaration::where('remise_id', $livraison->id)->where('statut', 'retenue')->exists();
            if (! $retenue) {
                throw new RegleLogistique("Le préfet n'a pas encore de réception retenue pour cette décharge.");
            }

            $solde = $this->soldePrefet($actuelle);
            $demandes = [];
            foreach ($lignes as $materielId => $quantite) {
                if ($quantite > 0) {
                    $demandes[$materielId] = $quantite;
                }
            }
            foreach (array_keys($demandes) as $materielId) {
                if (! $solde->has($materielId)) {
                    throw new InvalidArgumentException("Article {$materielId} absent de la décharge.");
                }
            }
            if ($demandes === []) {
                throw new RegleLogistique('Rien à redistribuer.');
            }

            foreach ($demandes as $materielId => $quantite) {
                ['recu_utilisable' => $utilisable, 'transfere' => $transfere] = $solde[$materielId];
                if ($transfere + $quantite > $utilisable) {
                    $libelle = Materiel::find($materielId)?->libelle ?? "article {$materielId}";
                    throw new RegleLogistique("Redistribution supérieure au reçu utilisable du préfet pour {$libelle} : reçu utilisable {$utilisable}, déjà transféré {$transfere}, demandé {$quantite}.");
                }
            }

            $redistribution = new Remise([
                'decharge_id' => $actuelle->id, 'etape' => 'redistribution',
                'de_type' => 'destinataire', 'de_id' => $prefecture->id,
                'a_type' => 'destinataire', 'a_id' => (int) $actuelle->destinataire_id,
                'preparee_par' => $auteur->id,
            ]);
            $redistribution->forceFill(['statut' => 'en_attente'])->save();
            foreach ($demandes as $materielId => $quantite) {
                RemiseLigne::create(['remise_id' => $redistribution->id, 'materiel_id' => $materielId, 'quantite' => $quantite]);
            }

            Journal::noter('remise', $redistribution->id, 'preparation_redistribution', null, [
                'decharge_id' => $actuelle->id, 'de' => $prefecture->id, 'a' => (int) $actuelle->destinataire_id,
                'lignes' => $demandes,
                'soldes_avant' => $solde->only(array_keys($demandes))->all(),
            ]);

            return $redistribution;
        }, attempts: 3);

        return $remise->load('lignes');
    }

    /**
     * Annule une redistribution que personne n'a encore déclarée : le reliquat du préfet est libéré.
     */
    public function annulerRedistribution(Remise $remise, string $motif): void
    {
        $this->exigerAuteur();
        $auteur = auth()->user();
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > 500) {
            throw new InvalidArgumentException("Le motif d'annulation est obligatoire (500 caractères au plus).");
        }

        $cycleId = Decharge::whereKey($remise->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Remise sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($remise, $motif, $cycleId, $auteur) {
            $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();
            $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
            $actuelle = Remise::whereKey($remise->id)->lockForUpdate()->firstOrFail();

            $this->prefectureQuiRedistribue($decharge, $auteur);
            if ($actuelle->etape !== 'redistribution' || (int) $actuelle->de_id !== (int) $decharge->remis_a_id) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }
            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : annulation impossible.');
            }
            if ($actuelle->statut !== 'en_attente' || Declaration::where('remise_id', $actuelle->id)->exists()) {
                throw new RegleLogistique('Seule une redistribution en attente, sans déclaration, peut être annulée.');
            }

            $actuelle->forceFill(['statut' => 'annulee', 'motif_annulation' => $motif])->save();
            Journal::noter('remise', $actuelle->id, 'annulation_redistribution', ['statut' => 'en_attente'], [
                'statut' => 'annulee', 'decharge_id' => $decharge->id,
            ], $motif);
        }, attempts: 3);

        $remise->refresh();
    }

    /**
     * La préfecture qui redistribue une décharge : celle à qui elle est remise, dont l'auteur est l'autorité,
     * pour une sous-préfecture en circuit via le préfet. Sinon la redistribution est hors périmètre.
     */
    private function prefectureQuiRedistribue(Decharge $decharge, User $auteur): Destinataire
    {
        $prefecture = $decharge->remis_a_id === null ? null : Destinataire::find($decharge->remis_a_id);
        $destinataire = Destinataire::find($decharge->destinataire_id);

        if (! Gate::forUser($auteur)->allows('logistique-terrain')
            || $prefecture === null || $destinataire === null
            || $decharge->circuit !== 'via_prefet'
            || $prefecture->type !== 'prefecture'
            || $destinataire->type !== 'sous_prefecture'
            || (int) $destinataire->prefecture_id !== (int) $prefecture->id
            || ! $this->perimetre->estAutoriteDe($auteur, $prefecture)) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        return $prefecture;
    }

    /**
     * @param  array<int|string, mixed>  $lignes
     * @return array<int, int> quantité par identifiant d'article, triée
     */
    private function quantitesRedistribuees(array $lignes): array
    {
        $retenues = [];
        foreach ($lignes as $materielId => $quantite) {
            if (is_string($quantite) && preg_match('/^\d{1,12}$/', $quantite) === 1) {
                $quantite = (int) $quantite;
            }
            if (! is_int($materielId) || ! is_int($quantite) || $quantite < 0 || $quantite > 10_000_000) {
                throw new InvalidArgumentException('Les quantités à redistribuer doivent être des entiers de 0 à 10 000 000, par article.');
            }
            $retenues[$materielId] = $quantite;
        }
        ksort($retenues);

        return $retenues;
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
