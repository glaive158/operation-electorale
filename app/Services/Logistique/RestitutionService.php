<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\DeclarationEnCours;
use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\Logistique\RestitutionDeclarationLigne;
use App\Models\Logistique\RestitutionDeclarationPiece;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Support\Canonique;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Restitution du matériel réutilisable à l'issue d'un cycle : ouverture d'une restitution par détenteur
 * (agent ou destinataire) d'après son solde (SoldeDetenteurService), recalcul tant qu'elle est à préparer,
 * désignation de l'agent de retour, déclaration du détenteur (compte, ou tablette de l'agent avec le code
 * personnel du détenteur), arbitrage des déclarations divergentes et prise en charge par l'agent, puis réception
 * et contrôle au central (remise en stock du bon et du à réparer) et décision sur le réformé.
 *
 * Ordre des verrous : cycle (partagé, sauf l'ouverture), restitution, déclarations, lignes, puis matériels
 * (par id croissant, dans StockService). La restitution se tient aussi
 * sur un cycle clos (spec §9.3) : aucune écriture ici ne refuse un cycle clos.
 */
class RestitutionService
{
    private const HORS_PERIMETRE = 'Restitution hors de votre périmètre.';

    private const MAX_QUANTITE = 10_000_000;

    private const MAX_TEXTE = 500;

    private const REFUS_CLE_REUTILISEE = "Clé d'idempotence déjà utilisée pour un autre contenu.";

    private const REFUS_BLOC_REUTILISE = 'Preuve déjà utilisée par une autre déclaration.';

    private const REFUS_CONTROLE_QUANTITES = 'Les quantités contrôlées doivent être des entiers de 0 à 10 000 000.';

    private const DEJA_PRISE_EN_CHARGE = 'Ce retour est déjà pris en charge.';

    public function __construct(
        private readonly SoldeDetenteurService $soldes,
        private readonly PerimetreTerrain $perimetre,
        private readonly DelegationService $delegations,
        private readonly PreuveService $preuves,
        private readonly PieceJointeService $pieceJointes,
        private readonly StockService $stock,
    ) {}

    /**
     * Ouvre une restitution « à préparer » pour chaque détenteur qui a un solde et aucune restitution ouverte
     * (non contrôlée) sur le cycle ; ses lignes portent l'attendu, égal au solde. Idempotente : un détenteur qui
     * a déjà une restitution ouverte est laissé tel quel. Renvoie le nombre de restitutions créées et le nombre
     * de détenteurs laissés inchangés. Une écriture de journal par restitution créée.
     *
     * Le verrou du cycle est exclusif : l'unicité d'une restitution ouverte par détenteur ne repose sur aucune
     * contrainte de base, deux ouvertures simultanées doivent donc se succéder.
     *
     * @return array{creees: int, inchangees: int}
     */
    public function ouvrir(Cycle $cycle): array
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut ouvrir la restitution.');

        return DB::transaction(function () use ($cycle) {
            $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->firstOrFail();

            $parDetenteur = $this->soldes->soldes($actuel)
                ->groupBy(fn (array $solde) => $solde['detenteur_type'].':'.$solde['detenteur_id']);
            $ouvertes = Restitution::where('cycle_id', $actuel->id)->where('statut', '!=', 'controlee')
                ->get(['detenteur_type', 'detenteur_id'])
                ->mapWithKeys(fn (Restitution $r) => [$r->detenteur_type.':'.$r->detenteur_id => true]);

            $creees = 0;
            $inchangees = 0;
            foreach ($parDetenteur as $cle => $soldes) {
                if ($ouvertes->has($cle)) {
                    $inchangees++;

                    continue;
                }

                $premier = $soldes->first();
                $restitution = new Restitution([
                    'cycle_id' => $actuel->id, 'detenteur_type' => $premier['detenteur_type'], 'detenteur_id' => $premier['detenteur_id'],
                ]);
                $restitution->forceFill(['ouverte_par' => auth()->id()])->save();

                // Une seule requête pour toutes les lignes : le verrou exclusif du cycle se tient le moins longtemps
                // possible. Les quantités autres que l'attendu prennent la valeur 0 par défaut de la table.
                $maintenant = now();
                $attendus = [];
                $lignes = [];
                foreach ($soldes->sortBy('materiel_id') as $solde) {
                    $lignes[] = [
                        'restitution_id' => $restitution->id, 'materiel_id' => $solde['materiel_id'], 'attendu' => $solde['solde'],
                        'created_at' => $maintenant, 'updated_at' => $maintenant,
                    ];
                    $attendus[$solde['materiel_id']] = $solde['solde'];
                }
                RestitutionLigne::insert($lignes);

                Journal::noter('restitution', $restitution->id, 'ouverture_restitution', null, [
                    'cycle_id' => $actuel->id, 'detenteur_type' => $restitution->detenteur_type,
                    'detenteur_id' => $restitution->detenteur_id, 'attendus' => $attendus,
                ]);
                $creees++;
            }

            // Les détenteurs déjà ouverts sans solde restant sont aussi « inchangés ».
            $inchangees += $ouvertes->keys()->diff($parDetenteur->keys())->count();

            return ['creees' => $creees, 'inchangees' => $inchangees];
        }, attempts: 3);
    }

    /**
     * Remet les attendus d'une restitution « à préparer » au solde courant de son détenteur : une ligne est
     * ajoutée pour un article nouveau, mise à jour pour un article existant, et supprimée lorsque son solde est
     * tombé à zéro, sauf si un retour y a déjà été déclaré (elle reste alors, attendu à zéro). Les retours
     * déclarés par les autres restitutions du détenteur se déduisent, pas les siens.
     */
    public function recalculer(Restitution $restitution): void
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut recalculer la restitution.');

        DB::transaction(function () use ($restitution) {
            $cycle = Cycle::whereKey($restitution->cycle_id)->sharedLock()->firstOrFail();
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut !== 'a_preparer') {
                throw new RegleLogistique('Seule une restitution à préparer peut être recalculée.');
            }
            // Une déclaration (même à vérifier) porte sur les attendus du moment : ils ne bougent plus sous elle.
            $declaree = RestitutionDeclaration::where('restitution_id', $actuelle->id)
                ->whereNotIn('statut', ['ecartee', 'remplacee'])->lockForUpdate()->exists();
            if ($declaree) {
                throw new RegleLogistique('Cette restitution a déjà une déclaration : ses attendus ne peuvent plus être recalculés.');
            }

            $soldes = $this->soldes
                ->soldes($cycle, $actuelle->detenteur_type, $actuelle->detenteur_id, $actuelle->id)
                ->mapWithKeys(fn (array $solde) => [$solde['materiel_id'] => $solde['solde']]);
            $lignes = RestitutionLigne::where('restitution_id', $actuelle->id)->orderBy('materiel_id')->get()->keyBy('materiel_id');

            $avant = $lignes->map(fn (RestitutionLigne $l) => $l->attendu)->all();

            foreach ($lignes as $materielId => $ligne) {
                $solde = (int) ($soldes[$materielId] ?? 0);
                if ($solde === 0 && $ligne->declare_restitue === 0) {
                    $ligne->delete();
                } elseif ($ligne->attendu !== $solde) {
                    $ligne->forceFill(['attendu' => $solde])->save();
                }
            }
            foreach ($soldes as $materielId => $solde) {
                if (! $lignes->has($materielId)) {
                    RestitutionLigne::create(['restitution_id' => $actuelle->id, 'materiel_id' => $materielId, 'attendu' => $solde]);
                }
            }

            $apres = RestitutionLigne::where('restitution_id', $actuelle->id)->orderBy('materiel_id')->pluck('attendu', 'materiel_id')
                ->map(fn ($attendu) => (int) $attendu)->all();
            $actuelle->touch();

            Journal::noter('restitution', $actuelle->id, 'recalcul_restitution', ['attendus' => $avant], ['attendus' => $apres]);
        }, attempts: 3);
    }

    /**
     * Désigne l'agent de transport qui rapportera le retour (P6-R3), tant que le retour n'est pas pris en charge.
     * Seul l'agent désigné déclare à la tablette et prend en charge.
     */
    public function affecterAgent(Restitution $restitution, User $agent): void
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique("Seul un gestionnaire logistique peut désigner l'agent de retour.");
        }

        DB::transaction(function () use ($restitution, $agent) {
            $this->verrouillerCycle($restitution);
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();
            if (! $this->estAgentActif($agent)) {
                throw new RegleLogistique('Seul un agent de transport actif peut être désigné pour le retour.');
            }
            if ($actuelle->statut !== 'a_preparer') {
                throw new RegleLogistique(self::DEJA_PRISE_EN_CHARGE." L'agent ne change plus.");
            }
            if ($actuelle->agent_id !== null && (int) $actuelle->agent_id === (int) $agent->id) {
                return;
            }

            $avant = $actuelle->agent_id;
            $actuelle->forceFill(['agent_id' => $agent->id])->save();
            Journal::noter('restitution', $actuelle->id, 'affectation_agent_restitution', ['agent_id' => $avant], ['agent_id' => (int) $agent->id]);
        }, attempts: 3);

        $restitution->refresh();
    }

    /**
     * Déclaration de ce que le détenteur rend.
     *
     * - `compte` : l'auteur est le détenteur, l'autorité du destinataire (titulaire ou délégué actif) ou l'agent
     *   détenteur lui-même ; il signe par sa connexion.
     * - `tablette` : l'auteur est l'agent de retour désigné, le signataire est le détenteur, qui atteste par son code
     *   personnel (bloc de preuve, vérifié comme pour une remise) ; sans bloc (repli), l'identité n'est pas vérifiée.
     *   Hors de son périmètre, la restitution n'existe pas pour l'agent (ModelNotFoundException, 404).
     *
     * Chaque ligne de la restitution est déclarée une fois ; un retour supérieur à l'attendu est accepté mais à
     * vérifier (excédent). Idempotence : même clé et même envoi ⇒ la déclaration enregistrée, sans rien refaire ;
     * même clé pour autre chose ⇒ refus. Rapprochement comme pour une remise : la première déclaration saine est
     * retenue et fixe le retour déclaré des lignes ; une seconde identique la confirme ; une seconde divergente
     * les met toutes deux à vérifier, jusqu'à l'arbitrage du responsable (retenirDeclaration). Une restitution prise
     * en charge n'accepte plus de déclaration.
     *
     * L'horodatage de la tablette est obligatoire en mode tablette : un texte ISO 8601 avec décalage (celui que la
     * tablette atteste), ou un instant dont le décalage est celui de la tablette.
     *
     * @param  array{nom?: string, qualite?: string}  $signataire
     * @param  array<int|string, array<string, mixed>>  $lignes  liste portant `materiel_id`, ou par identifiant d'article : {restitue, observation?}
     * @param  array<string, string>  $pieces  rôle (signature, photo) par empreinte de pièce annoncée
     */
    public function declarer(
        Restitution $r,
        string $mode,
        array $signataire,
        array $lignes,
        string $cle,
        CarbonInterface|string|null $horodatageTerminal = null,
        ?string $blocPreuve = null,
        ?int $signataireUserId = null,
        array $pieces = [],
    ): RestitutionDeclaration {
        $this->exigerAuteur();
        $auteur = auth()->user();

        if (! array_key_exists($mode, Declaration::MODES)) {
            throw new InvalidArgumentException('Mode de déclaration inconnu.');
        }
        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $cle) !== 1) {
            throw new InvalidArgumentException("La clé d'idempotence doit compter de 16 à 64 caractères (lettres, chiffres, tirets).");
        }
        $signataire = $this->signataire($signataire);
        $lignes = $this->normaliser($lignes);
        $pieces = $this->pieces($pieces);
        $empreinte = self::empreinte((int) $r->id, $mode, $signataire, $lignes);

        if ($mode === 'compte') {
            if ($blocPreuve !== null || $signataireUserId !== null) {
                throw new InvalidArgumentException('La preuve ne concerne que les déclarations de tablette.');
            }
            $instant = $horodatageTerminal === null ? null : $this->horodatage($horodatageTerminal)['instant'];

            return $this->enregistrerOuRejouer($r, 'compte', $signataire, $lignes, $cle, $instant, null, $pieces, $empreinte, $auteur);
        }

        if ($horodatageTerminal === null) {
            throw new InvalidArgumentException("L'horodatage de la tablette est obligatoire.");
        }
        ['texte' => $texte, 'instant' => $instant] = $this->horodatage($horodatageTerminal);
        $bloc = PreuveService::empreinteBloc($blocPreuve);

        // Hors du périmètre de l'agent, la restitution n'existe pas : 404, avant même de prendre le verrou.
        $this->exigerAgentDesigne($auteur, $r);

        // Toute la séquence (rejeu, contrôles, vérification du code, enregistrement) est sérialisée par restitution :
        // deux envois simultanés de la même clé ne comptent pas deux fois un mauvais code.
        $verrou = Cache::lock('logistique:restitution-tablette:'.$r->id, 30);
        try {
            return $verrou->block(
                (int) config('logistique.declaration_attente_verrou', 10),
                fn () => $this->sequenceTablette($r, $signataire, $lignes, $cle, $texte, $instant, $signataireUserId, $blocPreuve, $bloc, $pieces, $empreinte, $auteur),
            );
        } catch (LockTimeoutException) {
            throw new DeclarationEnCours;
        }
    }

    /**
     * Le responsable retient une déclaration à vérifier (divergence, excédent, identité non établie) : les autres
     * déclarations actives de la restitution sont écartées et le retour déclaré des lignes devient le sien. Il peut
     * aussi confirmer la retenue en cours face à une déclaration divergente : elle reste retenue, l'autre est écartée.
     */
    public function retenirDeclaration(RestitutionDeclaration $d, string $motif): void
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut arbitrer une déclaration de restitution.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }
        $restitution = Restitution::findOrFail(RestitutionDeclaration::whereKey($d->id)->value('restitution_id'));

        DB::transaction(function () use ($restitution, $d, $motif) {
            $this->verrouillerCycle($restitution);
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();
            $declarations = RestitutionDeclaration::where('restitution_id', $actuelle->id)->orderBy('id')->lockForUpdate()->get();
            $retenue = $declarations->firstWhere('id', $d->id);

            if ($actuelle->statut !== 'a_preparer') {
                throw new RegleLogistique(self::DEJA_PRISE_EN_CHARGE.' La déclaration retenue ne change plus.');
            }
            // La retenue en cours se confirme tant qu'une déclaration divergente attend l'arbitrage (R28) : elle reste
            // retenue, l'autre est écartée. Sans rien à vérifier, il n'y a rien à arbitrer.
            $confirmation = $retenue !== null && $retenue->statut === 'retenue'
                && $declarations->contains(fn (RestitutionDeclaration $autre) => $autre->statut === 'a_verifier');
            if ($retenue === null || (! $confirmation && ! in_array($retenue->statut, ['a_verifier', 'valide'], true))) {
                throw new RegleLogistique('Rien à arbitrer pour cette déclaration.');
            }

            $ecartees = [];
            foreach ($declarations as $autre) {
                if ($autre->id !== $retenue->id && ! in_array($autre->statut, ['ecartee', 'remplacee'], true)) {
                    $autre->forceFill(['statut' => 'ecartee'])->save();
                    $ecartees[] = $autre->id;
                }
            }
            $avant = $retenue->statut;
            $retenue->forceFill(['statut' => 'retenue'])->save();
            $declares = $this->appliquerRetenue($actuelle);

            Journal::noter('restitution_declaration', $retenue->id, 'arbitrage_restitution', ['statut' => $avant], [
                'statut' => 'retenue', 'restitution_id' => $actuelle->id, 'ecartees' => $ecartees, 'declare_restitue' => $declares,
            ], $motif);
        }, attempts: 3);

        $d->refresh();
    }

    /**
     * L'agent prend en charge le retour : lui-même, ou un gestionnaire en son nom. L'agent est l'agent désigné ;
     * sans agent désigné, seul l'agent détenteur prend en charge son propre retour (il devient l'agent du retour).
     * Une déclaration retenue est requise ; la restitution passe « en retour ».
     */
    public function prendreEnCharge(Restitution $r, User $agent): void
    {
        $this->exigerAuteur();
        $gerer = Gate::allows('logistique-gerer');
        // Un compte de terrain n'agit que pour lui-même : pour lui, la restitution d'un autre n'existe pas.
        if (! $gerer && (int) auth()->id() !== (int) $agent->id) {
            throw (new ModelNotFoundException)->setModel(Restitution::class, [$r->id]);
        }

        DB::transaction(function () use ($r, $agent, $gerer) {
            $this->verrouillerCycle($r);
            $actuelle = Restitution::whereKey($r->id)->lockForUpdate()->firstOrFail();

            $detenteur = $actuelle->detenteur_type === 'agent' && (int) $actuelle->detenteur_id === (int) $agent->id;
            $designe = $actuelle->agent_id !== null ? (int) $actuelle->agent_id === (int) $agent->id : $detenteur;
            if (! $designe) {
                if (! $gerer) {
                    throw (new ModelNotFoundException)->setModel(Restitution::class, [$r->id]);
                }
                throw new RegleLogistique($actuelle->agent_id === null
                    ? "Aucun agent n'est désigné pour ce retour."
                    : "Cet agent n'est pas l'agent désigné pour ce retour.");
            }
            if (! $this->estAgentActif($agent)) {
                throw new RegleLogistique('Seul un agent de transport actif peut prendre en charge le retour.');
            }
            if ($actuelle->statut !== 'a_preparer') {
                throw new RegleLogistique(self::DEJA_PRISE_EN_CHARGE);
            }
            $retenue = RestitutionDeclaration::where('restitution_id', $actuelle->id)->where('statut', 'retenue')->lockForUpdate()->first();
            if ($retenue === null) {
                throw new RegleLogistique('Aucune déclaration retenue : le retour ne peut pas être pris en charge.');
            }
            // Une divergence (R20) laisse la retenue en place mais attend l'arbitrage : une fois le retour pris en charge,
            // plus rien ne permettrait de l'arbitrer, et le contrôle au central serait bloqué.
            if (RestitutionDeclaration::where('restitution_id', $actuelle->id)->where('statut', 'a_verifier')->lockForUpdate()->exists()) {
                throw new RegleLogistique('Une déclaration de restitution est à vérifier : le responsable doit arbitrer avant la prise en charge.');
            }

            $avant = ['statut' => $actuelle->statut, 'agent_id' => $actuelle->agent_id];
            $actuelle->forceFill(['statut' => 'en_retour', 'agent_id' => $agent->id])->save();
            Journal::noter('restitution', $actuelle->id, 'prise_en_charge_restitution', $avant, [
                'statut' => 'en_retour', 'agent_id' => (int) $agent->id, 'declaration_id' => $retenue->id,
                'declare_restitue' => RestitutionLigne::where('restitution_id', $actuelle->id)->orderBy('materiel_id')
                    ->pluck('declare_restitue', 'materiel_id')->map(fn ($q) => (int) $q)->all(),
            ]);
        }, attempts: 3);

        $r->refresh();
    }

    /**
     * Réception au central : le gestionnaire compte ce que l'agent a rapporté, article par article (chaque ligne
     * de la restitution, une seule fois, de 0 à 10 000 000). Aucun mouvement de stock : le contrôle décide de
     * l'état. Seul un retour pris en charge (« en retour ») se réceptionne ; la restitution passe « reçue au
     * central ». Un cycle clos n'y fait pas obstacle (spec §9.3).
     *
     * @param  array<int|string, mixed>  $recus  quantité reçue par identifiant d'article
     */
    public function receptionner(Restitution $r, array $recus): void
    {
        $this->exigerAuteur();
        $this->exigerGestionnaire('Seul un gestionnaire logistique peut réceptionner un retour.');
        $recus = $this->parArticle($recus, 'reçue', fn (mixed $valeur) => $this->entier($valeur, 'Les quantités reçues doivent être des entiers de 0 à 10 000 000.'));

        DB::transaction(function () use ($r, $recus) {
            $this->verrouillerCycle($r);
            $actuelle = Restitution::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut !== 'en_retour') {
                throw new RegleLogistique('Seul un retour pris en charge peut être réceptionné au central.');
            }
            $lignes = $this->lignesDe($actuelle, $recus, 'reçue');

            $avant = [];
            foreach ($lignes as $materielId => $ligne) {
                $avant[$materielId] = $ligne->recu_central;
                if ($ligne->recu_central !== $recus[$materielId]) {
                    $ligne->forceFill(['recu_central' => $recus[$materielId]])->save();
                }
            }
            $actuelle->forceFill(['statut' => 'recue_central'])->save();

            ksort($recus);
            Journal::noter('restitution', $actuelle->id, 'reception_restitution', [
                'statut' => 'en_retour', 'recu_central' => $avant,
            ], [
                'statut' => 'recue_central', 'recu_central' => $recus,
                'declare_restitue' => $lignes->map(fn (RestitutionLigne $l) => $l->declare_restitue)->all(),
            ]);
        }, attempts: 3);

        $r->refresh();
    }

    /**
     * Contrôle au central : pour chaque ligne, ce qui est reçu se répartit en bon, à réparer et réformé (leur somme
     * vaut le reçu, sans quoi InvalidArgumentException). Le bon et le à réparer rentrent au magasin
     * (StockService::reintegrer, référencé à la ligne) ; seul le bon augmente le libre. Le réformé ne produit aucun
     * mouvement : il est noté sur la ligne et attend la décision du responsable (deciderReforme, R3). La
     * restitution devient « contrôlée » quand aucune réforme n'attend de décision, sinon elle reste « reçue au
     * central ». Le contrôle se fait une fois, pour toutes les lignes ; il est refusé tant qu'une déclaration à
     * vérifier attend l'arbitrage du responsable (retenirDeclaration).
     *
     * @param  array<int|string, mixed>  $controle  {bon, a_reparer, reforme} par identifiant d'article
     */
    public function controler(Restitution $r, array $controle): void
    {
        $this->exigerAuteur();
        $this->exigerGestionnaire('Seul un gestionnaire logistique peut contrôler un retour.');
        $controle = $this->parArticle($controle, 'contrôlée', function (mixed $valeur) {
            if (! is_array($valeur)) {
                throw new InvalidArgumentException(self::REFUS_CONTROLE_QUANTITES);
            }
            $quantites = [];
            foreach (['bon', 'a_reparer', 'reforme'] as $etat) {
                $quantites[$etat] = $this->entier($valeur[$etat] ?? null, self::REFUS_CONTROLE_QUANTITES);
            }

            return $quantites;
        });

        DB::transaction(function () use ($r, $controle) {
            $this->verrouillerCycle($r);
            $actuelle = Restitution::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut !== 'recue_central') {
                throw new RegleLogistique('Seule une restitution reçue au central peut être contrôlée.');
            }
            // Le retour a pu être pris en charge pendant qu'une déclaration divergente attendait encore l'arbitrage.
            if (RestitutionDeclaration::where('restitution_id', $actuelle->id)->where('statut', 'a_verifier')->lockForUpdate()->exists()) {
                throw new RegleLogistique("Une déclaration à vérifier attend l'arbitrage du responsable : le contrôle est impossible.");
            }
            $lignes = $this->lignesDe($actuelle, $controle, 'contrôlée');
            // Le contrôle écrit toutes les lignes d'un coup : une ligne déjà répartie dit qu'il a eu lieu (le statut
            // ne change pas tant qu'une réforme attend sa décision).
            if ($lignes->contains(fn (RestitutionLigne $l) => $l->bon + $l->a_reparer + $l->reforme > 0)) {
                throw new RegleLogistique('Ce retour est déjà contrôlé.');
            }

            $materiels = Materiel::whereIn('id', $lignes->keys())->get()->keyBy('id');
            foreach ($lignes as $materielId => $ligne) {
                $q = $controle[$materielId];
                if ($ligne->recu_central !== $q['bon'] + $q['a_reparer'] + $q['reforme']) {
                    throw new InvalidArgumentException(sprintf(
                        'Contrôle incomplet pour %s : bon + à réparer + réformé doit valoir %d.',
                        $materiels[$materielId]->libelle, $ligne->recu_central,
                    ));
                }
            }

            // Par id croissant (lignesDe) : l'ordre des verrous de matériels est celui de StockService.
            $motif = 'Retour contrôlé au central (restitution n° '.$actuelle->id.')';
            foreach ($lignes as $materielId => $ligne) {
                $q = $controle[$materielId];
                foreach (['bon', 'a_reparer'] as $etat) {
                    if ($q[$etat] > 0) {
                        $this->stock->reintegrer($materiels[$materielId], $q[$etat], $etat, $ligne, $motif);
                    }
                }
                $ligne->forceFill($q)->save();
            }

            $statut = $this->reformesEnAttente($actuelle) > 0 ? 'recue_central' : 'controlee';
            if ($statut !== $actuelle->statut) {
                $actuelle->forceFill(['statut' => $statut])->save();
            }

            ksort($controle);
            Journal::noter('restitution', $actuelle->id, 'controle_restitution', ['statut' => 'recue_central'], [
                'statut' => $statut, 'controle' => $controle,
                'recu_central' => $lignes->map(fn (RestitutionLigne $l) => $l->recu_central)->all(),
            ]);
        }, attempts: 3);

        $r->refresh();
    }

    /**
     * Le responsable décide du réformé d'une ligne contrôlée : le motif est consigné avec son auteur. Aucun mouvement
     * de stock (R3) : ces unités n'ont jamais rejoint le magasin. La restitution devient « contrôlée » quand plus
     * aucune réforme n'attend de décision.
     */
    public function deciderReforme(RestitutionLigne $ligne, string $motif): void
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut décider de la réforme.');
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }
        $restitution = Restitution::findOrFail(RestitutionLigne::whereKey($ligne->id)->value('restitution_id'));

        DB::transaction(function () use ($restitution, $ligne, $motif) {
            $this->verrouillerCycle($restitution);
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();
            $courante = RestitutionLigne::where('restitution_id', $actuelle->id)->orderBy('materiel_id')->lockForUpdate()->get()->firstWhere('id', $ligne->id);

            if ($courante === null || $courante->reforme <= 0) {
                throw new RegleLogistique('Aucune quantité réformée à décider sur cette ligne.');
            }
            if ($courante->motif_reforme !== null) {
                throw new RegleLogistique('La réforme de cette ligne est déjà décidée.');
            }

            $courante->forceFill(['motif_reforme' => $motif, 'reforme_decidee_par' => auth()->id()])->save();
            $statut = $this->reformesEnAttente($actuelle) > 0 ? $actuelle->statut : 'controlee';
            if ($statut !== $actuelle->statut) {
                $actuelle->forceFill(['statut' => $statut])->save();
            }

            Journal::noter('restitution', $actuelle->id, 'decision_reforme_restitution', null, [
                'ligne_id' => $courante->id, 'materiel_id' => $courante->materiel_id, 'reforme' => $courante->reforme, 'statut' => $statut,
            ], $motif);
        }, attempts: 3);

        $ligne->refresh();
    }

    /**
     * Écarts de restitution du cycle : une ligne par (détenteur, article), en cinq requêtes au plus quel que soit
     * le volume. `non_restitue` (attendu − retour déclaré, jamais négatif) n'existe qu'une fois une déclaration
     * retenue ; `perdu_en_retour` (retour déclaré − reçu au central, jamais négatif) seulement pour une restitution
     * reçue au central ou contrôlée : avant la réception, un reçu à zéro ferait passer tout le retour pour perdu.
     * Un détenteur qui a eu plusieurs restitutions sur le cycle (la suivante n'attend que le reliquat de la
     * précédente) est cumulé : le retour déclaré et le reçu s'additionnent, l'attendu est ce qui avait déjà été
     * déclaré avant la dernière restitution plus l'attendu de celle-ci. `ligne_id` est la ligne de la dernière
     * restitution (celle qu'on justifie) ; la justification est la dernière écrite.
     *
     * @return Collection<int, array{detenteur_type: string, detenteur_id: int, detenteur_nom: string, materiel_id: int, materiel_libelle: string, restitution_id: int, ligne_id: int, attendu: int, declare_restitue: int, recu_central: int, non_restitue: ?int, perdu_en_retour: ?int, justification: ?string}>
     */
    public function ecarts(Cycle $cycle): Collection
    {
        $lignes = DB::table('restitution_lignes')
            ->join('restitutions', 'restitutions.id', '=', 'restitution_lignes.restitution_id')
            ->where('restitutions.cycle_id', $cycle->id)
            ->orderBy('restitutions.id')->orderBy('restitution_lignes.materiel_id')
            ->get([
                'restitution_lignes.id as ligne_id', 'restitutions.id as restitution_id', 'restitutions.detenteur_type',
                'restitutions.detenteur_id', 'restitutions.statut', 'restitution_lignes.materiel_id', 'restitution_lignes.attendu',
                'restitution_lignes.declare_restitue', 'restitution_lignes.recu_central', 'restitution_lignes.justification',
            ]);
        if ($lignes->isEmpty()) {
            return collect();
        }

        $retenues = DB::table('restitution_declarations')->where('statut', 'retenue')
            ->whereIn('restitution_id', DB::table('restitutions')->where('cycle_id', $cycle->id)->select('id'))
            ->pluck('restitution_id')->flip();
        $agents = User::whereIn('id', $lignes->where('detenteur_type', 'agent')->pluck('detenteur_id')->unique()->all())->get()->keyBy('id');
        $destinataires = Destinataire::whereIn('id', $lignes->where('detenteur_type', 'destinataire')->pluck('detenteur_id')->unique()->all())->pluck('libelle', 'id');
        $materiels = Materiel::whereIn('id', $lignes->pluck('materiel_id')->unique()->all())->get()->keyBy('id');

        $ecarts = [];
        foreach ($lignes as $l) {
            $cle = $l->detenteur_type.':'.$l->detenteur_id.':'.$l->materiel_id;
            $declare = (int) $l->declare_restitue;
            $recu = (int) $l->recu_central;
            $recue = in_array($l->statut, ['recue_central', 'controlee'], true);

            $precedent = $ecarts[$cle] ?? null;
            $avant = $precedent['declare_restitue'] ?? 0;
            $detenteur = $l->detenteur_type === 'agent'
                ? (($agents->get($l->detenteur_id)?->nom_complet) ?: 'Agent')
                : (string) $destinataires->get($l->detenteur_id, 'Destinataire');

            $ecarts[$cle] = [
                'detenteur_type' => $l->detenteur_type,
                'detenteur_id' => (int) $l->detenteur_id,
                'detenteur_nom' => $detenteur,
                'materiel_id' => (int) $l->materiel_id,
                'materiel_libelle' => $materiels->get($l->materiel_id)?->libelle ?? '',
                'restitution_id' => (int) $l->restitution_id,
                'ligne_id' => (int) $l->ligne_id,
                'attendu' => $avant + (int) $l->attendu,
                'declare_restitue' => $avant + $declare,
                'recu_central' => ($precedent['recu_central'] ?? 0) + $recu,
                'non_restitue' => $retenues->has($l->restitution_id) ? max(0, (int) $l->attendu - $declare) : null,
                'perdu_en_retour' => $recue ? ($precedent['perdu_en_retour'] ?? 0) + max(0, $declare - $recu) : ($precedent['perdu_en_retour'] ?? null),
                'justification' => $l->justification ?? ($precedent['justification'] ?? null),
            ];
        }

        return collect($ecarts)
            ->sort(fn (array $a, array $b) => $a['detenteur_type'] <=> $b['detenteur_type']
                ?: TriLibelles::comparer($a['detenteur_nom'], $b['detenteur_nom'])
                ?: $a['detenteur_id'] <=> $b['detenteur_id']
                ?: ($materiels->get($a['materiel_id'])?->ordre <=> $materiels->get($b['materiel_id'])?->ordre)
                ?: $a['materiel_id'] <=> $b['materiel_id'])
            ->values();
    }

    /**
     * Le gestionnaire justifie l'écart d'une ligne de restitution (500 caractères au plus, non vide) ; elle peut être
     * réécrite, le journal garde l'ancienne justification. Possible à tout statut et sur un cycle clos.
     */
    public function justifier(RestitutionLigne $l, string $justification): void
    {
        $this->exigerAuteur();
        $this->exigerGestionnaire('Seul le personnel logistique peut justifier un écart de restitution.');
        $justification = trim($justification);
        if ($justification === '' || mb_strlen($justification) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('La justification est obligatoire (500 caractères au plus).');
        }
        $restitution = Restitution::findOrFail(RestitutionLigne::whereKey($l->id)->value('restitution_id'));

        DB::transaction(function () use ($restitution, $l, $justification) {
            $this->verrouillerCycle($restitution);
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();
            $courante = RestitutionLigne::where('restitution_id', $actuelle->id)->whereKey($l->id)->lockForUpdate()->firstOrFail();

            $avant = $courante->justification;
            $courante->forceFill(['justification' => $justification])->save();

            Journal::noter('restitution', $actuelle->id, 'justification_restitution', ['justification' => $avant], [
                'ligne_id' => $courante->id, 'materiel_id' => $courante->materiel_id, 'justification' => $justification,
            ]);
        }, attempts: 3);

        $l->refresh();
    }

    /**
     * @param  list<array{materiel_id: int, restitue: int, observation: ?string}>  $lignes
     */
    private function sequenceTablette(Restitution $r, array $signataire, array $lignes, string $cle, string $texte, CarbonInterface $instant, ?int $signataireUserId, ?string $blocPreuve, ?string $bloc, array $pieces, string $empreinte, User $auteur): RestitutionDeclaration
    {
        // Le verrou tenu, ce qu'un envoi concurrent vient d'enregistrer est visible : on rejoue avant tout.
        $rejeu = $this->rejeu($cle, $r, 'tablette', $empreinte, $bloc, $auteur);
        if ($rejeu !== null) {
            return $rejeu;
        }

        $actuelle = Restitution::findOrFail($r->id);
        $this->exigerAgentDesigne($auteur, $actuelle);
        $this->controlerStatut($actuelle, 'tablette');
        // Les lignes avant le code : une déclaration incohérente n'est jamais l'occasion de compter un échec de code.
        $this->controlerLignes($actuelle, $lignes);

        // Hors de toute transaction : le journal et le compteur d'échecs survivent à un refus de l'enregistrement.
        $preuve = $this->verifierIdentite($actuelle, $signataire, $lignes, $cle, $texte, $instant, $signataireUserId, $blocPreuve, $bloc);

        return $this->enregistrerOuRejouer($actuelle, 'tablette', $signataire, $lignes, $cle, $instant, $preuve, $pieces, $empreinte, $auteur);
    }

    /**
     * Enregistre (une écriture concurrente de la même clé est retrouvée par enregistrer), puis rattache les pièces.
     *
     * @param  list<array{materiel_id: int, restitue: int, observation: ?string}>  $lignes
     * @param  array{verification: string, bloc_empreinte: ?string, signataire_user_id: ?int, delegation_id: ?int}|null  $preuve
     */
    private function enregistrerOuRejouer(Restitution $r, string $mode, array $signataire, array $lignes, string $cle, ?CarbonInterface $instant, ?array $preuve, array $pieces, string $empreinte, User $auteur): RestitutionDeclaration
    {
        $declaration = $this->enregistrer($r, $mode, $signataire, $lignes, $cle, $instant, $preuve, $pieces, $empreinte, $auteur);

        // Les pièces envoyées pendant l'enregistrement ont pu manquer le rattachement : on le refait.
        if ($pieces !== [] && $declaration->wasRecentlyCreated) {
            $this->pieceJointes->rattacherEnAttente(array_keys($pieces));
        }

        return $declaration->load('lignes');
    }

    /**
     * @param  list<array{materiel_id: int, restitue: int, observation: ?string}>  $lignes
     * @param  array{verification: string, bloc_empreinte: ?string, signataire_user_id: ?int, delegation_id: ?int}|null  $preuve
     * @param  array<string, string>  $pieces
     */
    private function enregistrer(Restitution $r, string $mode, array $signataire, array $lignes, string $cle, ?CarbonInterface $instant, ?array $preuve, array $pieces, string $empreinte, User $auteur): RestitutionDeclaration
    {
        try {
            [$declaration, $conflit] = DB::transaction(function () use ($r, $mode, $signataire, $lignes, $cle, $instant, $preuve, $pieces, $empreinte, $auteur) {
                // Les verrous avant toute autre lecture : sous REPEATABLE READ, l'instantané de la transaction se fige à
                // la première lecture non verrouillante ; prise après le verrou de la restitution, elle voit tout ce
                // que les écritures précédentes sur cette restitution ont validé.
                $this->verrouillerCycle($r);
                $actuelle = Restitution::whereKey($r->id)->lockForUpdate()->firstOrFail();

                // Rejeu : avant tout autre contrôle.
                $existante = RestitutionDeclaration::where('cle_idempotence', $cle)->first();
                if ($existante !== null) {
                    if ($this->memeEnvoi($existante, $mode, $empreinte, $preuve['bloc_empreinte'] ?? null, $auteur)) {
                        return [$existante, false];
                    }
                    $this->noterAnomalieIdempotence($existante, $cle, $r, $mode, $empreinte);

                    return [$existante, true];
                }

                $delegation = $this->controlerDroit($auteur, $actuelle, $mode);
                $this->controlerStatut($actuelle, $mode);
                $anomalies = $this->controlerLignes($actuelle, $lignes);
                if ($preuve !== null && ! in_array($preuve['verification'], Declaration::VERIFICATIONS_ETABLIES, true)) {
                    $anomalies[] = 'identite';
                }
                $anomalies = array_values(array_unique($anomalies));

                $declaration = new RestitutionDeclaration([
                    'restitution_id' => $actuelle->id, 'mode' => $mode,
                    'signataire_nom' => $signataire['nom'], 'signataire_qualite' => $signataire['qualite'],
                    'user_id' => $auteur->id, 'horodatage_terminal' => $instant,
                    'cle_idempotence' => $cle, 'empreinte_contenu' => $empreinte,
                ]);
                $declaration->forceFill([
                    'statut' => $anomalies === [] ? 'valide' : 'a_verifier',
                    'anomalies' => $anomalies === [] ? null : $anomalies,
                    // Au compte, le signataire est celui qui est connecté (et sa délégation) ; à la tablette, le compte
                    // nommé par l'envoi, vérifié avant l'enregistrement.
                    'signataire_user_id' => $mode === 'compte' ? $auteur->id : $preuve['signataire_user_id'],
                    'delegation_id' => $mode === 'compte' ? $delegation?->id : $preuve['delegation_id'],
                    'verification_identite' => $mode === 'compte' ? 'compte' : $preuve['verification'],
                    'bloc_empreinte' => $preuve['bloc_empreinte'] ?? null,
                ])->save();
                foreach ($lignes as $ligne) {
                    RestitutionDeclarationLigne::create(['restitution_declaration_id' => $declaration->id] + $ligne);
                }
                foreach ($pieces as $empreintePiece => $role) {
                    RestitutionDeclarationPiece::create([
                        'restitution_declaration_id' => $declaration->id, 'empreinte' => $empreintePiece, 'role' => $role,
                        // Liée seulement si l'auteur a lui-même envoyé (ou prouvé posséder) ces octets.
                        'piece_jointe_id' => $this->pieceJointes->pieceDe($auteur, $empreintePiece)?->id,
                    ]);
                }

                ['changements' => $changements, 'declare_restitue' => $declares] = $this->rapprocher($actuelle);
                $declaration->refresh();

                Journal::noter('restitution_declaration', $declaration->id, 'declaration_restitution', null, [
                    'restitution_id' => $actuelle->id, 'mode' => $mode,
                    'statut' => $declaration->statut, 'anomalies' => $declaration->anomalies ?? [],
                    'signataire_user_id' => $declaration->signataire_user_id, 'delegation_id' => $declaration->delegation_id,
                    'verification_identite' => $declaration->verification_identite, 'bloc_empreinte' => $declaration->bloc_empreinte,
                    'cle' => $cle, 'empreinte' => $empreinte, 'signataire' => $signataire, 'lignes' => $lignes, 'pieces' => $pieces,
                    'horodatage_terminal' => $instant?->toIso8601String(),
                    'rapprochement' => $changements, 'declare_restitue' => $declares,
                ]);

                return [$declaration, false];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            [$declaration, $conflit] = $this->apresViolation($e, $r, $mode, $cle, $empreinte, $preuve, $auteur);
        }

        if ($conflit) {
            throw new RegleLogistique(self::REFUS_CLE_REUTILISEE);
        }

        return $declaration;
    }

    /**
     * Filet de concurrence, après une violation d'unicité : une écriture a échappé aux verrous. La base dit laquelle
     * (le message de l'erreur cite toutes les colonnes insérées, il ne dit rien) : une déclaration de cette clé ⇒
     * rejeu (même envoi) ou conflit ; sinon une déclaration portant ce bloc ⇒ preuve déjà utilisée ; sinon l'erreur.
     *
     * @param  array{bloc_empreinte?: ?string, signataire_user_id?: ?int}|null  $preuve
     * @return array{0: RestitutionDeclaration, 1: bool} la déclaration et vrai pour un conflit de clé
     */
    private function apresViolation(UniqueConstraintViolationException $e, Restitution $r, string $mode, string $cle, string $empreinte, ?array $preuve, User $auteur): array
    {
        $existante = RestitutionDeclaration::where('cle_idempotence', $cle)->first();
        if ($existante !== null) {
            if ($this->memeEnvoi($existante, $mode, $empreinte, $preuve['bloc_empreinte'] ?? null, $auteur)) {
                return [$existante->load('lignes'), false];
            }
            $this->noterAnomalieIdempotence($existante, $cle, $r, $mode, $empreinte);

            return [$existante, true];
        }

        $bloc = $preuve['bloc_empreinte'] ?? null;
        $porteur = $bloc === null ? null : RestitutionDeclaration::where('bloc_empreinte', $bloc)->value('id');
        if ($porteur !== null) {
            Journal::noter('restitution_declaration', (int) $porteur, 'anomalie_preuve', null, [
                'nature' => 'bloc_reutilise', 'signataire_user_id' => $preuve['signataire_user_id'] ?? null,
            ], self::REFUS_BLOC_REUTILISE);

            throw new RegleLogistique(self::REFUS_BLOC_REUTILISE);
        }

        throw $e;
    }

    /**
     * La déclaration déjà enregistrée sous cette clé, si l'envoi est le même ; null si la clé est libre ; refus si
     * elle a servi à autre chose.
     */
    private function rejeu(string $cle, Restitution $r, string $mode, string $empreinte, ?string $bloc, User $auteur): ?RestitutionDeclaration
    {
        $existante = RestitutionDeclaration::where('cle_idempotence', $cle)->first();
        if ($existante === null) {
            return null;
        }
        if ($this->memeEnvoi($existante, $mode, $empreinte, $bloc, $auteur)) {
            return $existante->load('lignes');
        }

        $this->noterAnomalieIdempotence($existante, $cle, $r, $mode, $empreinte);

        throw new RegleLogistique(self::REFUS_CLE_REUTILISEE);
    }

    private function noterAnomalieIdempotence(RestitutionDeclaration $existante, string $cle, Restitution $r, string $mode, string $empreinte): void
    {
        Journal::noter('restitution_declaration', $existante->id, 'anomalie_idempotence', null, [
            'cle' => $cle, 'restitution_id' => $r->id, 'mode' => $mode, 'empreinte_recue' => $empreinte,
        ], "Clé d'idempotence réutilisée pour un autre contenu.");
    }

    /**
     * Même contenu ; pour une tablette, même bloc de preuve (ou aucun des deux) et même compte connecté (une clé
     * ne se rejoue pas depuis un autre appareil).
     */
    private function memeEnvoi(RestitutionDeclaration $existante, string $mode, string $empreinte, ?string $bloc, User $auteur): bool
    {
        if (! hash_equals((string) $existante->empreinte_contenu, $empreinte)) {
            return false;
        }
        if ($mode === 'compte') {
            return true;
        }

        return ($existante->bloc_empreinte === null && $bloc === null
                || $existante->bloc_empreinte !== null && $bloc !== null && hash_equals($existante->bloc_empreinte, $bloc))
            && (int) $existante->user_id === (int) $auteur->id;
    }

    /**
     * Résultat de la vérification d'identité d'une déclaration de tablette. Sans bloc (repli : code oublié ou
     * verrouillé), l'identité n'est pas vérifiée, et un compte ne reste rattaché que s'il est le détenteur attendu
     * (titulaire ou délégué à l'instant de la signature, ou l'agent détenteur) ; signataire inconnu, elle est en échec.
     *
     * @param  array{nom: string, qualite: string}  $signataire
     * @param  list<array{materiel_id: int, restitue: int, observation: ?string}>  $lignes
     * @return array{verification: string, bloc_empreinte: ?string, signataire_user_id: ?int, delegation_id: ?int}
     */
    private function verifierIdentite(Restitution $r, array $signataire, array $lignes, string $cle, string $texte, CarbonInterface $instant, ?int $signataireUserId, ?string $blocPreuve, ?string $bloc): array
    {
        $utilisateur = $signataireUserId === null ? null : User::find($signataireUserId);
        $base = ['bloc_empreinte' => $bloc, 'signataire_user_id' => $utilisateur?->id, 'delegation_id' => null];
        $destinataire = $r->detenteur_type === 'destinataire' ? Destinataire::find($r->detenteur_id) : null;

        if ($blocPreuve === null || $blocPreuve === '') {
            if ($utilisateur !== null) {
                $delegation = null;
                if ($destinataire !== null) {
                    $titulaire = $this->perimetre->estTitulaireDe($utilisateur, $destinataire);
                    $delegation = $titulaire ? null : $this->delegations->activeA($destinataire, $utilisateur, $instant);
                    $attendu = $titulaire || $delegation !== null;
                } else {
                    $attendu = (bool) $utilisateur->actif && (int) $r->detenteur_id === (int) $utilisateur->id;
                }
                $base['signataire_user_id'] = $attendu ? $utilisateur->id : null;
                $base['delegation_id'] = $delegation?->id;
            }

            return ['verification' => 'non_verifiee'] + $base;
        }
        if ($utilisateur === null) {
            return ['verification' => 'echec'] + $base;
        }

        // Un délégué signe au titre de la délégation en vigueur à l'instant de sa signature, pas de celle d'aujourd'hui.
        $delegation = null;
        if ($destinataire !== null && ! $this->perimetre->estTitulaireDe($utilisateur, $destinataire)) {
            $delegation = $this->delegations->activeA($destinataire, $utilisateur, $instant);
        }

        $contenu = $this->preuves->contenuPreuveRestitution($r, $signataire, $utilisateur->id, $lignes, $cle, $texte);
        $resultat = $this->preuves->verifierDans(ContextePreuve::restitution($r), $contenu, $cle, $blocPreuve, $utilisateur, $instant, $delegation);

        return ['verification' => $resultat['verification'], 'bloc_empreinte' => $resultat['bloc_empreinte'], 'delegation_id' => $delegation?->id] + $base;
    }

    /**
     * Qui déclare : au compte, le détenteur (l'autorité titulaire du destinataire ou son délégué actif, dont la
     * délégation est rapportée ; l'agent détenteur lui-même) ; à la tablette, l'agent de retour désigné.
     */
    private function controlerDroit(User $auteur, Restitution $r, string $mode): ?Delegation
    {
        if (! Gate::forUser($auteur)->allows('logistique-terrain')) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        if ($mode === 'tablette' || $r->detenteur_type === 'agent') {
            $attendu = $mode === 'tablette' ? $r->agent_id : $r->detenteur_id;
            if ($auteur->role !== 'agent_transport' || $attendu === null || (int) $attendu !== (int) $auteur->id) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }

            return null;
        }

        $destinataire = Destinataire::find($r->detenteur_id);
        // Un seul instant pour les deux contrôles : une délégation qui prend fin entre les deux ne passe pas.
        $maintenant = now();
        if ($destinataire === null || ! $this->perimetre->estAutoriteDe($auteur, $destinataire, $maintenant)) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }
        if ($this->perimetre->estTitulaireDe($auteur, $destinataire)) {
            return null;
        }
        $delegation = $this->delegations->activeA($destinataire, $auteur, $maintenant);
        if ($delegation === null) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        return $delegation;
    }

    /** Une restitution dont l'agent n'est pas l'agent de retour désigné est, pour lui, inconnue (404). */
    private function exigerAgentDesigne(User $auteur, Restitution $r): void
    {
        $actuelle = Restitution::findOrFail($r->id);
        try {
            $this->controlerDroit($auteur, $actuelle, 'tablette');
        } catch (RegleLogistique) {
            throw (new ModelNotFoundException)->setModel(Restitution::class, [$r->id]);
        }
    }

    private function controlerStatut(Restitution $r, string $mode): void
    {
        if ($r->statut !== 'a_preparer') {
            throw new RegleLogistique("Cette restitution n'attend plus de déclaration.");
        }
        $dejaFaite = RestitutionDeclaration::where('restitution_id', $r->id)->where('mode', $mode)
            ->whereNotIn('statut', ['ecartee', 'remplacee'])->lockForUpdate()->exists();
        if ($dejaFaite) {
            throw new RegleLogistique('Une déclaration '.mb_strtolower(Declaration::MODES[$mode]).' existe déjà pour cette restitution.');
        }
    }

    /**
     * Chaque ligne de la restitution, et elle seule, est déclarée ; anomalie « excedent » pour un retour supérieur
     * à l'attendu.
     *
     * @param  list<array{materiel_id: int, restitue: int, observation: ?string}>  $lignes
     * @return list<string>
     */
    private function controlerLignes(Restitution $r, array $lignes): array
    {
        $attendus = RestitutionLigne::where('restitution_id', $r->id)->lockForUpdate()->pluck('attendu', 'materiel_id')
            ->map(fn ($attendu) => (int) $attendu)->all();

        $anomalies = [];
        foreach ($lignes as $ligne) {
            // Dépend de l'état de la restitution (ses lignes), pas de la forme de l'envoi : une règle, pas une saisie invalide.
            if (! array_key_exists($ligne['materiel_id'], $attendus)) {
                throw new RegleLogistique('Article hors de cette restitution.');
            }
            if ($ligne['restitue'] > $attendus[$ligne['materiel_id']]) {
                $anomalies[] = 'excedent';
            }
        }
        if (count($lignes) !== count($attendus)) {
            throw new InvalidArgumentException('Chaque ligne de la restitution doit être déclarée.');
        }

        return array_values(array_unique($anomalies));
    }

    /**
     * Rapproche les déclarations actives (ni écartées ni remplacées). Une déclaration retenue (d'office ou par
     * arbitrage) est figée : une nouvelle déclaration saine et identique la confirme (concordante), toute autre est
     * à vérifier, sans toucher à la retenue ni au retour déclaré ; seul l'arbitrage change la retenue. Sans retenue :
     * une seule saine est retenue ; deux saines et identiques se confirment (la plus ancienne est retenue, l'autre
     * concordante) ; deux divergentes sont toutes deux à vérifier, et rien n'est compté jusqu'à l'arbitrage. Le
     * retour déclaré des lignes suit la retenue. L'appelant tient les verrous du cycle et de la restitution.
     *
     * @return array{changements: array<int, array{avant: string, apres: string}>, declare_restitue: array<int, int>}
     */
    private function rapprocher(Restitution $r): array
    {
        $actives = RestitutionDeclaration::where('restitution_id', $r->id)->whereNotIn('statut', ['ecartee', 'remplacee'])
            ->orderBy('id')->lockForUpdate()->with('lignes')->get()->values();
        if ($actives->count() > 2) {
            throw new LogicException('Plus de deux déclarations actives sur une restitution : invariant rompu.');
        }

        $cibles = [];
        $retenue = $actives->first(fn (RestitutionDeclaration $d) => $d->statut === 'retenue');
        $divergentes = [];
        if ($retenue !== null) {
            foreach ($actives as $declaration) {
                if ($declaration->id === $retenue->id) {
                    $cibles[$declaration->id] = 'retenue';

                    continue;
                }
                $identique = $this->contenu($declaration) === $this->contenu($retenue);
                $cibles[$declaration->id] = $identique && $this->saine($declaration) ? 'concordante' : 'a_verifier';
                if (! $identique) {
                    $divergentes[] = $declaration->id;
                }
            }
        } elseif ($actives->count() === 1) {
            $cibles[$actives[0]->id] = $this->saine($actives[0]) ? 'retenue' : 'a_verifier';
        } elseif ($actives->count() === 2) {
            [$premiere, $seconde] = [$actives[0], $actives[1]];
            if ($this->contenu($premiere) !== $this->contenu($seconde)) {
                $divergentes = [$premiere->id, $seconde->id];
                $cibles = [$premiere->id => 'a_verifier', $seconde->id => 'a_verifier'];
            } elseif ($this->saine($premiere) && $this->saine($seconde)) {
                $cibles = [$premiere->id => 'retenue', $seconde->id => 'concordante'];
            } else {
                $cibles = [$premiere->id => 'a_verifier', $seconde->id => 'a_verifier'];
            }
        }

        $changements = [];
        foreach ($actives as $declaration) {
            if ($retenue !== null && $declaration->id === $retenue->id) {
                continue;
            }
            $anomalies = $declaration->anomalies ?? [];
            if (in_array($declaration->id, $divergentes, true) && ! in_array('divergence', $anomalies, true)) {
                $anomalies[] = 'divergence';
            }
            $cible = $cibles[$declaration->id];
            if ($cible === $declaration->statut && $anomalies === ($declaration->anomalies ?? [])) {
                continue;
            }
            $changements[$declaration->id] = ['avant' => $declaration->statut, 'apres' => $cible];
            $declaration->forceFill(['statut' => $cible, 'anomalies' => $anomalies === [] ? null : $anomalies])->save();
        }

        return ['changements' => $changements, 'declare_restitue' => $this->appliquerRetenue($r)];
    }

    /**
     * Le retour déclaré de chaque ligne est celui de la déclaration retenue, zéro sans déclaration retenue.
     *
     * @return array<int, int> retour déclaré par article
     */
    private function appliquerRetenue(Restitution $r): array
    {
        $retenue = RestitutionDeclaration::where('restitution_id', $r->id)->where('statut', 'retenue')->lockForUpdate()->with('lignes')->first();
        $declares = $retenue?->lignes->mapWithKeys(fn (RestitutionDeclarationLigne $l) => [$l->materiel_id => $l->restitue])->all() ?? [];

        $resultat = [];
        foreach (RestitutionLigne::where('restitution_id', $r->id)->orderBy('materiel_id')->lockForUpdate()->get() as $ligne) {
            $quantite = (int) ($declares[$ligne->materiel_id] ?? 0);
            if ($ligne->declare_restitue !== $quantite) {
                $ligne->forceFill(['declare_restitue' => $quantite])->save();
            }
            $resultat[$ligne->materiel_id] = $quantite;
        }

        return $resultat;
    }

    /** Saine : aucune anomalie propre (une divergence passée ne compte plus, le contenu étant figé). */
    private function saine(RestitutionDeclaration $declaration): bool
    {
        return array_diff($declaration->anomalies ?? [], ['divergence']) === [];
    }

    /** @return list<array{0: int, 1: int}> */
    private function contenu(RestitutionDeclaration $declaration): array
    {
        return $declaration->lignes->sortBy('materiel_id')
            ->map(fn (RestitutionDeclarationLigne $l) => [(int) $l->materiel_id, (int) $l->restitue])->values()->all();
    }

    /** @return array{nom: string, qualite: string} */
    private function signataire(array $signataire): array
    {
        $retenu = [];
        foreach (['nom', 'qualite'] as $champ) {
            $valeur = $signataire[$champ] ?? null;
            $valeur = is_string($valeur) ? trim($valeur) : '';
            if ($valeur === '' || mb_strlen($valeur) > 150) {
                throw new InvalidArgumentException('Le nom et la qualité du signataire sont obligatoires (150 caractères au plus).');
            }
            $retenu[$champ] = $valeur;
        }

        return $retenu;
    }

    /**
     * Lignes normalisées (entiers, triées par article), chaque article une seule fois ; les bornes sont vérifiées ici.
     *
     * @return list<array{materiel_id: int, restitue: int, observation: ?string}>
     */
    private function normaliser(array $lignes): array
    {
        $parArticle = [];
        foreach ($lignes as $position => $ligne) {
            if (! is_array($ligne)) {
                throw new InvalidArgumentException('Chaque ligne de la restitution doit être déclarée.');
            }
            $materielId = $ligne['materiel_id'] ?? $position;
            if (! is_int($materielId) && ! (is_string($materielId) && preg_match('/^\d{1,12}$/', $materielId) === 1)) {
                throw new InvalidArgumentException('Chaque ligne de la restitution doit être déclarée.');
            }
            $materielId = (int) $materielId;
            if (isset($parArticle[$materielId])) {
                throw new InvalidArgumentException('Un article ne peut être déclaré qu\'une fois.');
            }

            $observation = $ligne['observation'] ?? null;
            if ($observation !== null && ! is_string($observation)) {
                throw new InvalidArgumentException("L'observation doit être un texte.");
            }
            $observation = $observation === null ? null : trim($observation);
            $observation = $observation === '' ? null : $observation;
            if ($observation !== null && mb_strlen($observation) > self::MAX_TEXTE) {
                throw new InvalidArgumentException("L'observation est limitée à 500 caractères.");
            }

            $parArticle[$materielId] = [
                'materiel_id' => $materielId,
                'restitue' => $this->quantite($ligne['restitue'] ?? null),
                'observation' => $observation,
            ];
        }
        ksort($parArticle);

        return array_values($parArticle);
    }

    private function quantite(mixed $valeur): int
    {
        if (is_string($valeur) && preg_match('/^-?\d{1,12}$/', $valeur) === 1) {
            $valeur = (int) $valeur;
        }
        if (! is_int($valeur) || $valeur < 0 || $valeur > self::MAX_QUANTITE) {
            throw new InvalidArgumentException('Les quantités restituées doivent être des entiers de 0 à 10 000 000.');
        }

        return $valeur;
    }

    /**
     * @param  array<string, string>  $pieces
     * @return array<string, string>
     */
    private function pieces(array $pieces): array
    {
        $retenues = [];
        foreach ($pieces as $empreinte => $role) {
            $empreinte = (string) $empreinte;
            if (preg_match('/^[0-9a-f]{64}$/', $empreinte) !== 1 || ! is_string($role) || ! array_key_exists($role, RestitutionDeclarationPiece::ROLES)) {
                throw new InvalidArgumentException('Pièce annoncée invalide : empreinte SHA-256 et rôle (signature ou photo) attendus.');
            }
            $retenues[$empreinte] = $role;
        }

        return $retenues;
    }

    /**
     * Le texte attesté par la tablette (ISO 8601 avec décalage, tel quel) et l'instant ramené au fuseau de
     * l'application, pour les comparaisons en base et le stockage.
     *
     * @return array{texte: string, instant: Carbon}
     */
    private function horodatage(CarbonInterface|string $valeur): array
    {
        if ($valeur instanceof CarbonInterface) {
            return ['texte' => $valeur->format('Y-m-d\TH:i:sP'), 'instant' => Carbon::instance($valeur)->setTimezone((string) config('app.timezone'))];
        }

        $instant = Carbon::createFromFormat('Y-m-d\TH:i:sP', $valeur);
        if ($instant === false || $instant->format('Y-m-d\TH:i:sP') !== $valeur) {
            throw new InvalidArgumentException("L'horodatage doit être au format ISO 8601 avec décalage, par exemple 2026-11-05T10:00:00+00:00.");
        }

        return ['texte' => $valeur, 'instant' => $instant->setTimezone((string) config('app.timezone'))];
    }

    /**
     * Empreinte du contenu : SHA-256 du JSON canonique {restitution, mode, signataire, lignes triées par article}.
     *
     * @param  array{nom: string, qualite: string}  $signataire
     * @param  list<array{materiel_id: int, restitue: int, observation: ?string}>  $lignes
     */
    private static function empreinte(int $restitutionId, string $mode, array $signataire, array $lignes): string
    {
        return Canonique::empreinte(['restitution_id' => $restitutionId, 'mode' => $mode, 'signataire' => $signataire, 'lignes' => $lignes]);
    }

    /**
     * Quantités par article d'un envoi de réception ou de contrôle : clés d'articles entiers, chaque valeur validée
     * par `$valeur`.
     *
     * @param  callable(mixed): mixed  $valeur
     * @return array<int, mixed>
     */
    private function parArticle(array $envoi, string $verbe, callable $valeur): array
    {
        $retenu = [];
        foreach ($envoi as $materielId => $contenu) {
            if (! is_int($materielId) && ! (is_string($materielId) && preg_match('/^\d{1,12}$/', $materielId) === 1)) {
                throw new InvalidArgumentException('Chaque ligne de la restitution doit être '.$verbe.'.');
            }
            $retenu[(int) $materielId] = $valeur($contenu);
        }

        return $retenu;
    }

    private function entier(mixed $valeur, string $message): int
    {
        if (is_string($valeur) && preg_match('/^-?\d{1,12}$/', $valeur) === 1) {
            $valeur = (int) $valeur;
        }
        if (! is_int($valeur) || $valeur < 0 || $valeur > self::MAX_QUANTITE) {
            throw new InvalidArgumentException($message);
        }

        return $valeur;
    }

    /**
     * Les lignes de la restitution, verrouillées par article croissant : l'envoi les couvre toutes et elles seules.
     *
     * @param  array<int, mixed>  $envoi
     * @return Collection<int, RestitutionLigne>
     */
    private function lignesDe(Restitution $r, array $envoi, string $verbe): Collection
    {
        $lignes = RestitutionLigne::where('restitution_id', $r->id)->orderBy('materiel_id')->lockForUpdate()->get()->keyBy('materiel_id');
        foreach (array_keys($envoi) as $materielId) {
            if (! $lignes->has($materielId)) {
                throw new RegleLogistique('Article hors de cette restitution.');
            }
        }
        if (count($envoi) !== $lignes->count()) {
            throw new InvalidArgumentException('Chaque ligne de la restitution doit être '.$verbe.'.');
        }

        return $lignes;
    }

    /** Nombre de lignes dont le réformé attend encore la décision du responsable. */
    private function reformesEnAttente(Restitution $r): int
    {
        return RestitutionLigne::where('restitution_id', $r->id)->where('reforme', '>', 0)->whereNull('motif_reforme')->count();
    }

    /** Verrou partagé du cycle, d'abord : la restitution ne refuse pas un cycle clos (spec §9.3). */
    private function verrouillerCycle(Restitution $r): void
    {
        if (Cycle::whereKey($r->cycle_id)->sharedLock()->first() === null) {
            throw new LogicException('Cycle introuvable : invariant rompu.');
        }
    }

    private function estAgentActif(User $agent): bool
    {
        $compte = User::find($agent->id);

        return $compte !== null && (bool) $compte->actif && $compte->role === 'agent_transport';
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération de restitution sans auteur.');
        }
    }

    private function exigerGestionnaire(string $message): void
    {
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique($message);
        }
    }

    private function exigerResponsable(string $message): void
    {
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique($message);
        }
    }
}
