<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\ReserveAppareil;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Appareil désigné d'une préfecture et réserve hors ligne du préfet. Le central désigne un seul appareil par
 * préfecture ; avant de passer hors ligne, cet appareil réserve, par article, une part du solde du préfet. Cette
 * part n'est plus mobilisable par les opérations en ligne ; hors ligne, l'appareil ne redistribue que dans cette
 * réserve (RemiseService::redistribuerSynchronisee), qui se consomme au fil des remises synchronisées. Le reste est
 * libéré à la demande, ou à la révocation de l'appareil.
 *
 * Ordre des verrous : cycle, décharge, remise, appareil, réserves. Désigner et révoquer ne prennent que la
 * préfecture, l'appareil et ses réserves (jamais une décharge).
 */
class AppareilService
{
    private const MAX_NOM = 100;

    private const MAX_QUANTITE = 10_000_000;

    private const NON_DESIGNE = "Cet appareil n'est pas l'appareil désigné de la préfecture.";

    public function __construct(
        private readonly RemiseService $remises,
        private readonly PerimetreTerrain $perimetre,
        private readonly DelegationService $delegations,
    ) {}

    /**
     * Désigne l'appareil d'une préfecture. Il n'y en a qu'un d'actif : le précédent est révoqué (et sa réserve
     * libérée). Redésigner l'appareil déjà actif ne change rien ; un appareil révoqué peut être désigné de nouveau.
     */
    public function designer(Destinataire $prefecture, string $identifiant, string $nom): Appareil
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut désigner un appareil.');
        }
        $identifiant = mb_strtolower(trim($identifiant));
        if (! Str::isUuid($identifiant)) {
            throw new InvalidArgumentException("L'identifiant de l'appareil doit être un UUID.");
        }
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > self::MAX_NOM) {
            throw new InvalidArgumentException('Le nom de l\'appareil est obligatoire (100 caractères au plus).');
        }

        return DB::transaction(function () use ($prefecture, $identifiant, $nom) {
            // Le verrou de la préfecture ordonne les désignations concurrentes.
            $destinataire = Destinataire::whereKey($prefecture->id)->lockForUpdate()->firstOrFail();
            if ($destinataire->type !== 'prefecture') {
                throw new RegleLogistique('Un appareil se désigne pour une préfecture.');
            }

            $existant = Appareil::where('identifiant', $identifiant)->lockForUpdate()->first();
            if ($existant !== null && (int) $existant->destinataire_id !== (int) $destinataire->id) {
                throw new RegleLogistique('Cet appareil est déjà enregistré pour une autre préfecture.');
            }
            if ($existant?->actif) {
                return $existant;
            }

            foreach (Appareil::where('destinataire_id', $destinataire->id)->where('actif', true)->orderBy('id')->lockForUpdate()->get() as $courant) {
                $this->revoquerSousVerrou($courant, 'Remplacé par un nouvel appareil désigné.');
            }

            if ($existant === null) {
                $appareil = new Appareil(['destinataire_id' => $destinataire->id, 'identifiant' => $identifiant, 'nom' => $nom, 'designe_par' => auth()->id()]);
                $appareil->save();
            } else {
                $appareil = $existant;
                $appareil->forceFill(['actif' => true, 'revoque_le' => null, 'nom' => $nom, 'designe_par' => auth()->id()])->save();
            }

            Journal::noter('appareil', $appareil->id, 'designation_appareil', null, [
                'destinataire_id' => $destinataire->id, 'identifiant' => $identifiant, 'nom' => $nom,
            ]);

            return $appareil;
        }, attempts: 3);
    }

    /** Révoque l'appareil d'une préfecture : il ne prépare plus rien hors ligne et sa réserve est libérée. */
    public function revoquer(Appareil $appareil, string $motif): void
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut révoquer un appareil.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > 500) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        DB::transaction(function () use ($appareil, $motif) {
            Destinataire::whereKey($appareil->destinataire_id)->lockForUpdate()->firstOrFail();
            $courant = Appareil::whereKey($appareil->id)->lockForUpdate()->firstOrFail();
            if (! $courant->actif) {
                throw new RegleLogistique('Cet appareil est déjà révoqué.');
            }
            $this->revoquerSousVerrou($courant, $motif);
        }, attempts: 3);

        $appareil->refresh();
    }

    /**
     * Réserve à l'appareil, pour la décharge, une part du solde du préfet. L'auteur est l'autorité de la préfecture
     * (le préfet ou son adjoint habilité) qui utilise cet appareil, en ligne. Sans quantités : tout le mobilisable,
     * par article ; avec : exactement ces quantités (un article omis n'est pas réservé), chacune au plus égale au
     * mobilisable. Elle remplace la réserve active précédente de (appareil, décharge), dont les quantités redeviennent
     * mobilisables avant le calcul.
     *
     * @param  array<int, int|string>|null  $quantites  quantité par identifiant d'article
     * @return Collection<int, ReserveAppareil> les lignes de la nouvelle réserve, par article croissant
     */
    public function reserver(Appareil $appareil, Decharge $decharge, ?array $quantites = null): Collection
    {
        $this->exigerAuteur();
        $auteur = auth()->user();
        $quantites = $quantites === null ? null : $this->quantites($quantites);

        return DB::transaction(function () use ($appareil, $decharge, $quantites, $auteur) {
            $cycle = Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            // Un seul instant pour le contrôle du droit et la délégation consignée.
            $maintenant = now();
            $prefecture = $this->remises->prefectureQuiRedistribue($actuelle, $auteur, $maintenant);

            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : réserve impossible.');
            }
            if ($actuelle->statut_document !== 'validee') {
                throw new RegleLogistique('Seule une décharge validée peut faire l\'objet d\'une réserve.');
            }
            $livraison = Remise::where('decharge_id', $actuelle->id)->where('etape', 'livraison')
                ->where('statut', '!=', 'annulee')->lockForUpdate()->first();
            if ($livraison?->statut === 'a_verifier') {
                throw new RegleLogistique('Réception du préfet à vérifier : réserve impossible.');
            }
            if ($livraison === null || ! Declaration::where('remise_id', $livraison->id)->where('statut', 'retenue')->exists()) {
                throw new RegleLogistique("Le préfet n'a pas encore de réception retenue pour cette décharge.");
            }

            $courant = Appareil::whereKey($appareil->id)->lockForUpdate()->firstOrFail();
            if (! $courant->actif || (int) $courant->destinataire_id !== (int) $prefecture->id) {
                throw new RegleLogistique(self::NON_DESIGNE);
            }

            $anciennes = $this->lignesActives($courant, $actuelle, true)->keyBy('materiel_id');
            $solde = $this->remises->soldePrefet($actuelle);

            // Ce qui est mobilisable en comptant pour libre la réserve que celle-ci remplace.
            $plafond = $solde->map(fn (array $s, int $materielId) => max(0,
                $s['recu_utilisable'] - $s['transfere'] - ($s['reserve_appareil'] - (int) ($anciennes->get($materielId)?->quantite ?? 0))));

            $retenues = [];
            foreach ($quantites ?? $plafond->all() as $materielId => $quantite) {
                if (! $solde->has($materielId)) {
                    throw new InvalidArgumentException("Article {$materielId} absent de la décharge.");
                }
                if ($quantite > $plafond[$materielId]) {
                    $libelle = Materiel::find($materielId)?->libelle ?? "article {$materielId}";
                    throw new RegleLogistique("Réserve supérieure au mobilisable pour {$libelle} : mobilisable {$plafond[$materielId]}, demandé {$quantite}.");
                }
                if ($quantite > 0) {
                    $retenues[$materielId] = $quantite;
                }
            }
            ksort($retenues);
            if ($retenues === []) {
                throw new RegleLogistique('Rien à réserver.');
            }

            foreach ($anciennes as $ancienne) {
                $ancienne->forceFill(['statut' => 'liberee'])->save();
            }
            $lignes = collect();
            foreach ($retenues as $materielId => $quantite) {
                $ligne = new ReserveAppareil([
                    'appareil_id' => $courant->id, 'decharge_id' => $actuelle->id, 'materiel_id' => $materielId,
                    'quantite_initiale' => $quantite, 'quantite' => $quantite,
                ]);
                $ligne->save();
                $lignes->push($ligne);
            }

            Journal::noter('appareil', $courant->id, 'reservation_appareil', [
                'decharge_id' => $actuelle->id, 'reserve' => $anciennes->map(fn (ReserveAppareil $l) => $l->quantite)->all(),
            ], [
                'decharge_id' => $actuelle->id, 'reserve' => $retenues,
                'delegation_id' => $this->perimetre->estTitulaireDe($auteur, $prefecture) ? null : $this->delegations->activeA($prefecture, $auteur, $maintenant)?->id,
            ]);

            return $lignes;
        }, attempts: 3);
    }

    /**
     * Libère le reste de la réserve de l'appareil pour la décharge : il redevient mobilisable. Le préfet (ou son
     * adjoint) le fait à la synchronisation ; le responsable logistique peut le faire à tout moment. Sans effet s'il
     * ne reste rien. Renvoie les lignes libérées.
     *
     * @return Collection<int, ReserveAppareil>
     */
    public function liberer(Appareil $appareil, Decharge $decharge): Collection
    {
        $this->exigerAuteur();
        $auteur = auth()->user();

        return DB::transaction(function () use ($appareil, $decharge, $auteur) {
            // Libérer ne touche à aucun stock : possible aussi sur un cycle clos.
            Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            $courant = Appareil::whereKey($appareil->id)->lockForUpdate()->firstOrFail();

            if (Gate::allows('logistique-administrer')) {
                if ($actuelle->remis_a_id === null || (int) $actuelle->remis_a_id !== (int) $courant->destinataire_id) {
                    throw new RegleLogistique('Redistribution hors de votre périmètre.');
                }
            } else {
                $prefecture = $this->remises->prefectureQuiRedistribue($actuelle, $auteur);
                if ((int) $courant->destinataire_id !== (int) $prefecture->id) {
                    throw new RegleLogistique(self::NON_DESIGNE);
                }
            }

            $lignes = $this->lignesActives($courant, $actuelle, true);
            if ($lignes->isEmpty()) {
                return $lignes;
            }

            foreach ($lignes as $ligne) {
                $ligne->forceFill(['statut' => 'liberee'])->save();
            }
            Journal::noter('appareil', $courant->id, 'liberation_reserve_appareil', [
                'decharge_id' => $actuelle->id, 'reserve' => $lignes->pluck('quantite', 'materiel_id')->all(),
            ], ['decharge_id' => $actuelle->id, 'reserve' => []]);

            return $lignes;
        }, attempts: 3);
    }

    /** L'appareil actif qui porte cet identifiant, ou null. */
    public function actif(string $identifiant): ?Appareil
    {
        return Appareil::where('identifiant', mb_strtolower($identifiant))->where('actif', true)->first();
    }

    /**
     * Lignes de la réserve active de l'appareil pour la décharge, par article croissant.
     *
     * @return Collection<int, ReserveAppareil>
     */
    public function reserveActive(Appareil $appareil, Decharge $decharge): Collection
    {
        return $this->lignesActives($appareil, $decharge, false);
    }

    /** @return Collection<int, ReserveAppareil> */
    private function lignesActives(Appareil $appareil, Decharge $decharge, bool $verrou): Collection
    {
        $requete = ReserveAppareil::where('appareil_id', $appareil->id)->where('decharge_id', $decharge->id)
            ->where('statut', 'active')->orderBy('materiel_id');

        return ($verrou ? $requete->lockForUpdate() : $requete)->get();
    }

    private function revoquerSousVerrou(Appareil $appareil, string $motif): void
    {
        $liberees = ReserveAppareil::where('appareil_id', $appareil->id)->where('statut', 'active')->orderBy('id')->lockForUpdate()->get();
        foreach ($liberees as $ligne) {
            $ligne->forceFill(['statut' => 'liberee'])->save();
        }

        $appareil->forceFill(['actif' => false, 'revoque_le' => now()])->save();
        Journal::noter('appareil', $appareil->id, 'revocation_appareil', ['actif' => true], [
            'actif' => false, 'destinataire_id' => $appareil->destinataire_id,
            'reserves_liberees' => $liberees->map(fn (ReserveAppareil $l) => [
                'decharge_id' => $l->decharge_id, 'materiel_id' => $l->materiel_id, 'quantite' => $l->quantite,
            ])->all(),
        ], $motif);
    }

    /**
     * @param  array<int|string, mixed>  $quantites
     * @return array<int, int> quantité par identifiant d'article
     */
    private function quantites(array $quantites): array
    {
        $retenues = [];
        foreach ($quantites as $materielId => $quantite) {
            if (is_string($quantite) && preg_match('/^\d{1,12}$/', $quantite) === 1) {
                $quantite = (int) $quantite;
            }
            if (! is_int($materielId) || ! is_int($quantite) || $quantite < 0 || $quantite > self::MAX_QUANTITE) {
                throw new InvalidArgumentException('Les quantités à réserver doivent être des entiers de 0 à 10 000 000, par article.');
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
