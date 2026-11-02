<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\DeclarationEnCours;
use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\DeclarationLigne;
use App\Models\Logistique\DeclarationPiece;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
use App\Models\User;
use App\Support\Canonique;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Déclarations de réception : l'autorité destinataire (compte) ou l'agent (tablette) déclare ce qui a été
 * reçu et ce qui est défectueux sur une remise. Une déclaration n'est jamais modifiée dans ses quantités ni
 * supprimée ; le rapprochement ne fait évoluer que son statut.
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation.
 */
class DeclarationService
{
    private const HORS_PERIMETRE = 'Déclaration hors de votre périmètre.';

    private const MAX_QUANTITE = 10_000_000;

    private const REFUS_CLE_REUTILISEE = "Clé d'idempotence déjà utilisée pour un autre contenu.";

    private const REFUS_BLOC_REUTILISE = 'Preuve déjà utilisée par une autre déclaration.';

    /** Vérifications qui établissent l'identité du signataire : les autres ajoutent l'anomalie « identite ». */
    private const IDENTITES_ETABLIES = ['verifiee_code', 'verifiee_appareil', 'compte'];

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly DelegationService $delegations,
        private readonly PreuveService $preuves,
        private readonly PieceJointeService $pieceJointes,
    ) {}

    /**
     * Enregistre une déclaration de réception sur une remise de livraison ou de redistribution.
     *
     * Pour une déclaration de tablette, `$preuve` porte le résultat de la vérification d'identité, déjà
     * obtenu hors de toute transaction (PreuveService::verifier garde ainsi son journal et son compteur
     * d'échecs même si l'enregistrement est refusé) : {verification, bloc_empreinte, signataire_user_id,
     * delegation_id}. Sans `$preuve`, une déclaration de tablette est « non vérifiée » (comme le repli sans code).
     * Une vérification qui n'établit pas l'identité (échec, à vérifier, non vérifiée) ajoute l'anomalie
     * « identite » : la déclaration n'est jamais retenue automatiquement.
     *
     * @param  array{nom?: string, qualite?: string}  $signataire
     * @param  array<int, array{recu: int|string, defectueux: int|string, observation?: ?string}>  $lignes  par identifiant d'article
     * @param  array{verification: string, bloc_empreinte: ?string, signataire_user_id: ?int, delegation_id: ?int}|null  $preuve
     * @param  array<string, string>  $pieces  rôle (signature, photo) par empreinte de pièce annoncée
     * @param  array{lat: float|int|string, lng: float|int|string, precision?: int|string|null}|null  $gps
     */
    public function declarer(
        Remise $remise,
        string $mode,
        array $signataire,
        array $lignes,
        string $cle,
        ?CarbonInterface $horodatageTerminal = null,
        ?array $preuve = null,
        array $pieces = [],
        ?array $gps = null,
    ): Declaration {
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
        $preuve = $this->preuve($mode, $preuve);
        $pieces = $this->pieces($pieces);
        $gps = $this->gps($gps);
        // L'instant est stocké tel quel dans une colonne sans fuseau : toujours dans le fuseau de l'application.
        $horodatageTerminal = $horodatageTerminal?->copy()->setTimezone((string) config('app.timezone'));
        $empreinte = self::empreinte((int) $remise->id, $mode, $signataire, $lignes);

        $cycleId = Decharge::whereKey($remise->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Remise sans décharge : invariant rompu.');
        }

        try {
            [$declaration, $conflit] = DB::transaction(function () use ($remise, $mode, $signataire, $lignes, $cle, $horodatageTerminal, $empreinte, $cycleId, $auteur, $preuve, $pieces, $gps) {
                $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();

                // Rejeu : avant tout autre contrôle.
                $existante = Declaration::where('cle_idempotence', $cle)->first();
                if ($existante !== null) {
                    if ($this->memeEnvoi($existante, $empreinte, $preuve, $auteur)) {
                        return [$existante, false];
                    }
                    Journal::noter('declaration', $existante->id, 'anomalie_idempotence', null, [
                        'cle' => $cle, 'remise_id' => $remise->id, 'mode' => $mode, 'empreinte_recue' => $empreinte,
                    ], "Clé d'idempotence réutilisée pour un autre contenu.");

                    return [$existante, true];
                }

                // Une déclaration de réception ne touche à aucun stock central : elle reste possible après la clôture
                // du cycle (une livraison non déclarée doit pouvoir l'être). Le verrou du cycle suffit à l'ordonner.
                if ($cycle === null) {
                    throw new LogicException('Cycle introuvable : invariant rompu.');
                }
                $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
                $actuelle = Remise::whereKey($remise->id)->lockForUpdate()->firstOrFail();

                $this->controlerEtape($actuelle);
                ['anomalies' => $anomalies, 'delegation' => $delegation] = $this->controlerDroit($auteur, $actuelle, $decharge, $mode);
                $this->controlerStatut($actuelle, $mode);

                $anomalies = array_merge($anomalies, $this->controlerLignes($actuelle, $lignes));
                if ($decharge->statut_document === 'annulee') {
                    $anomalies[] = 'version_annulee';
                }
                if ($preuve !== null && ! in_array($preuve['verification'], self::IDENTITES_ETABLIES, true)) {
                    $anomalies[] = 'identite';
                }
                $anomalies = array_values(array_unique($anomalies));

                $declaration = new Declaration([
                    'remise_id' => $actuelle->id, 'mode' => $mode,
                    'signataire_nom' => $signataire['nom'], 'signataire_qualite' => $signataire['qualite'],
                    'user_id' => $auteur->id, 'decharge_id' => $decharge->id,
                    'horodatage_terminal' => $horodatageTerminal, 'cle_idempotence' => $cle, 'empreinte_contenu' => $empreinte,
                ]);
                $declaration->forceFill([
                    'statut' => $anomalies === [] ? 'valide' : 'a_verifier',
                    'anomalies' => $anomalies === [] ? null : $anomalies,
                    // Au compte, le signataire est celui qui est connecté : l'autorité, ou son délégué (et la délégation).
                    // À la tablette, c'est le compte nommé par le bloc de preuve, vérifié avant l'enregistrement.
                    'signataire_user_id' => $mode === 'compte' ? $auteur->id : ($preuve['signataire_user_id'] ?? null),
                    'delegation_id' => $mode === 'compte' ? $delegation?->id : ($preuve['delegation_id'] ?? null),
                    'verification_identite' => $mode === 'compte' ? 'compte' : ($preuve['verification'] ?? 'non_verifiee'),
                    'bloc_empreinte' => $preuve['bloc_empreinte'] ?? null,
                    'gps_lat' => $gps['lat'] ?? null, 'gps_lng' => $gps['lng'] ?? null, 'gps_precision' => $gps['precision'] ?? null,
                ])->save();
                foreach ($lignes as $ligne) {
                    DeclarationLigne::create(['declaration_id' => $declaration->id] + $ligne);
                }
                foreach ($pieces as $empreintePiece => $role) {
                    DeclarationPiece::create([
                        'declaration_id' => $declaration->id, 'empreinte' => $empreintePiece, 'role' => $role,
                        // Liée seulement si l'auteur a lui-même envoyé (ou prouvé posséder) ces octets.
                        'piece_jointe_id' => $this->pieceJointes->pieceDe($auteur, $empreintePiece)?->id,
                    ]);
                }

                $this->rapprocher($actuelle);
                $declaration->refresh();

                Journal::noter('declaration', $declaration->id, 'declaration_reception', null, [
                    'remise_id' => $actuelle->id, 'decharge_id' => $decharge->id, 'mode' => $mode,
                    'statut' => $declaration->statut, 'anomalies' => $declaration->anomalies ?? [],
                    'signataire_user_id' => $declaration->signataire_user_id, 'delegation_id' => $declaration->delegation_id,
                    'verification_identite' => $declaration->verification_identite, 'bloc_empreinte' => $declaration->bloc_empreinte,
                    'cle' => $cle, 'empreinte' => $empreinte, 'signataire' => $signataire, 'lignes' => $lignes,
                    'pieces' => $pieces, 'gps' => $gps,
                    'horodatage_terminal' => $horodatageTerminal?->toIso8601String(),
                ]);

                $this->apresRetenue($actuelle);

                return [$declaration, false];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // Filet de concurrence : le même bloc de preuve ne sert qu'à une seule déclaration.
            if (str_contains($e->getMessage(), 'bloc_empreinte')) {
                $porteur = Declaration::where('bloc_empreinte', $preuve['bloc_empreinte'] ?? '')->value('id');
                if ($porteur !== null) {
                    Journal::noter('declaration', (int) $porteur, 'anomalie_preuve', null, [
                        'nature' => 'bloc_reutilise', 'signataire_user_id' => $preuve['signataire_user_id'] ?? null,
                    ], self::REFUS_BLOC_REUTILISE);
                }

                throw new RegleLogistique(self::REFUS_BLOC_REUTILISE);
            }

            throw $e;
        }

        if ($conflit) {
            throw new RegleLogistique(self::REFUS_CLE_REUTILISEE);
        }

        $remise->refresh();

        // Les pièces envoyées pendant l'enregistrement ont pu manquer le rattachement : on le refait.
        if ($pieces !== [] && $declaration->wasRecentlyCreated) {
            $this->pieceJointes->rattacherEnAttente(array_keys($pieces));
        }

        // Avis au signataire d'une nouvelle déclaration de tablette (jamais sur un rejeu), une fois la
        // transaction englobante validée : une déclaration qui n'existe pas ne donne pas d'avis.
        if ($mode === 'tablette' && $declaration->wasRecentlyCreated && $declaration->signataire_user_id !== null) {
            DB::afterCommit(function () use ($declaration) {
                try {
                    // Service résolu à l'appel : il dépend lui-même de celui-ci.
                    app(AvisReceptionService::class)->envoyer($declaration);
                } catch (\Throwable $e) {
                    // La déclaration est enregistrée : un avis manqué ne la défait pas, il est consigné.
                    report($e);
                }
            });
        }

        return $declaration->load('lignes');
    }

    /**
     * Déclaration envoyée par la tablette : rejeu idempotent, vérification de l'identité par le bloc de preuve,
     * puis enregistrement.
     *
     * 1. Même clé : même contenu, même bloc et même auteur ⇒ la déclaration enregistrée est renvoyée telle
     *    quelle, sans vérifier à nouveau le code (aucun échec n'est compté deux fois) ; sinon refus.
     * 2. Les droits et l'état de la remise sont contrôlés avant de toucher au code : un agent hors périmètre
     *    ne peut ni tester ni verrouiller le code d'une autorité.
     * 3. PreuveService::verifier est appelée HORS de toute transaction : son journal et son compteur d'échecs
     *    survivent à un refus de l'enregistrement.
     * 4. declarer() enregistre dans une transaction.
     *
     * @param  array{nom?: string, qualite?: string}  $signataire
     * @param  array<int|string, array<string, mixed>>  $lignes  liste portant `materiel_id`, ou par identifiant d'article
     * @param  array<string, string>  $pieces  rôle par empreinte de pièce
     * @param  array{lat: float|int|string, lng: float|int|string, precision?: int|string|null}|null  $gps
     * @return array{0: Declaration, 1: bool} la déclaration et vrai si elle vient d'être créée (faux : rejeu)
     */
    public function declarerParTablette(
        Remise $remise,
        array $signataire,
        array $lignes,
        string $cle,
        string $horodatageTerminal,
        int $signataireUserId,
        ?string $blocPreuve,
        array $pieces = [],
        ?array $gps = null,
    ): array {
        $this->exigerAuteur();
        $auteur = auth()->user();

        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $cle) !== 1) {
            throw new InvalidArgumentException("La clé d'idempotence doit compter de 16 à 64 caractères (lettres, chiffres, tirets).");
        }
        $signataire = $this->signataire($signataire);
        $parArticle = [];
        foreach ($lignes as $position => $ligne) {
            $materielId = is_array($ligne) ? ($ligne['materiel_id'] ?? $position) : $position;
            if (! is_int($materielId) && ! (is_string($materielId) && preg_match('/^\d{1,12}$/', $materielId) === 1)) {
                throw new InvalidArgumentException('Chaque ligne de la remise doit être déclarée.');
            }
            if (isset($parArticle[(int) $materielId])) {
                throw new InvalidArgumentException('Un article ne peut être déclaré qu\'une fois.');
            }
            $parArticle[(int) $materielId] = $ligne;
        }
        $lignes = $this->normaliser($parArticle);
        $horodatage = $this->horodatage($horodatageTerminal);
        $empreinte = self::empreinte((int) $remise->id, 'tablette', $signataire, $lignes);
        $bloc = PreuveService::empreinteBloc($blocPreuve);

        // Hors du périmètre de l'appelant, la remise n'existe pas : 404, avant même de prendre le verrou.
        $this->exigerPerimetre($auteur, $remise);

        // Toute la séquence (rejeu, contrôles, vérification du code, enregistrement) est sérialisée par remise :
        // deux envois simultanés de la même clé ne comptent pas deux fois un mauvais code.
        $verrou = Cache::lock('logistique:declaration-tablette:'.$remise->id, 30);
        try {
            return $verrou->block(
                (int) config('logistique.declaration_attente_verrou', 10),
                fn () => $this->sequenceTablette($remise, $signataire, $lignes, $cle, $horodatageTerminal, $horodatage, $signataireUserId, $blocPreuve, $bloc, $pieces, $gps, $empreinte, $auteur),
            );
        } catch (LockTimeoutException) {
            throw new DeclarationEnCours;
        }
    }

    /**
     * @param  list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>  $lignes
     * @return array{0: Declaration, 1: bool}
     */
    private function sequenceTablette(Remise $remise, array $signataire, array $lignes, string $cle, string $horodatageTerminal, CarbonInterface $horodatage, int $signataireUserId, ?string $blocPreuve, ?string $bloc, array $pieces, ?array $gps, string $empreinte, User $auteur): array
    {
        // Le verrou tenu, ce qu'un envoi concurrent vient d'enregistrer est visible : on rejoue avant tout.
        $rejeu = $this->rejeuTablette($cle, $remise, $empreinte, $bloc, $auteur);
        if ($rejeu !== null) {
            return [$rejeu, false];
        }

        $actuelle = Remise::findOrFail($remise->id);
        $this->exigerPerimetre($auteur, $actuelle);
        $this->controlerEtape($actuelle);
        $this->controlerStatut($actuelle, 'tablette');

        $preuve = $this->verifierIdentite(
            $actuelle, $signataire, $lignes, $cle, $horodatageTerminal, $horodatage, $signataireUserId, $blocPreuve, $bloc,
        );

        try {
            $declaration = $this->declarer(
                $actuelle, 'tablette', $signataire, array_column($lignes, null, 'materiel_id'), $cle, $horodatage, $preuve, $pieces, $gps,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Filet : une autre écriture de la même clé a échappé au verrou ; on retrouve celle qui a gagné.
            $rejeu = str_contains($e->getMessage(), 'cle_idempotence') ? $this->rejeuTablette($cle, $remise, $empreinte, $bloc, $auteur) : null;
            if ($rejeu === null) {
                throw $e;
            }

            return [$rejeu, false];
        }

        return [$declaration, $declaration->wasRecentlyCreated];
    }

    /** Une remise que l'appelant n'a pas à connaître est une remise inconnue (404), pas un refus de règle. */
    private function exigerPerimetre(User $auteur, Remise $remise): void
    {
        $actuelle = Remise::findOrFail($remise->id);
        $decharge = Decharge::findOrFail($actuelle->decharge_id);
        try {
            $this->controlerDroit($auteur, $actuelle, $decharge, 'tablette');
        } catch (RegleLogistique) {
            throw (new ModelNotFoundException)->setModel(Remise::class, [$remise->id]);
        }
    }

    /**
     * La déclaration déjà enregistrée sous cette clé, si l'envoi est le même ; null si la clé est libre ;
     * refus si elle a servi à autre chose.
     */
    private function rejeuTablette(string $cle, Remise $remise, string $empreinte, ?string $bloc, User $auteur): ?Declaration
    {
        $existante = Declaration::where('cle_idempotence', $cle)->first();
        if ($existante === null) {
            return null;
        }
        if ($this->memeEnvoi($existante, $empreinte, ['bloc_empreinte' => $bloc], $auteur)) {
            return $existante->load('lignes');
        }

        Journal::noter('declaration', $existante->id, 'anomalie_idempotence', null, [
            'cle' => $cle, 'remise_id' => $remise->id, 'mode' => 'tablette', 'empreinte_recue' => $empreinte,
        ], "Clé d'idempotence réutilisée pour un autre contenu.");

        throw new RegleLogistique(self::REFUS_CLE_REUTILISEE);
    }

    /**
     * Même contenu ; pour une tablette, même bloc de preuve et même compte connecté (une clé ne se rejoue pas
     * depuis un autre appareil).
     *
     * @param  array{bloc_empreinte?: ?string}|null  $preuve
     */
    private function memeEnvoi(Declaration $existante, string $empreinte, ?array $preuve, User $auteur): bool
    {
        if (! hash_equals((string) $existante->empreinte_contenu, $empreinte)) {
            return false;
        }
        if ($preuve === null) {
            return true;
        }

        return ($existante->bloc_empreinte === null && ($preuve['bloc_empreinte'] ?? null) === null
                || $existante->bloc_empreinte !== null && hash_equals($existante->bloc_empreinte, (string) ($preuve['bloc_empreinte'] ?? '')))
            && (int) $existante->user_id === (int) $auteur->id;
    }

    /**
     * Résultat de la vérification d'identité d'une déclaration de tablette. Sans bloc (repli : tablette qui n'a
     * pu chiffrer), l'identité n'est pas vérifiée ; signataire inconnu, elle est en échec.
     *
     * @param  array{nom: string, qualite: string}  $signataire
     * @param  list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>  $lignes
     * @return array{verification: string, bloc_empreinte: ?string, signataire_user_id: ?int, delegation_id: ?int}
     */
    private function verifierIdentite(Remise $remise, array $signataire, array $lignes, string $cle, string $horodatageTerminal, CarbonInterface $horodatage, int $signataireUserId, ?string $blocPreuve, ?string $bloc): array
    {
        $utilisateur = User::find($signataireUserId);
        $base = ['bloc_empreinte' => $bloc, 'signataire_user_id' => $utilisateur?->id, 'delegation_id' => null];

        if ($blocPreuve === null || $blocPreuve === '') {
            return ['verification' => 'non_verifiee'] + $base;
        }
        if ($utilisateur === null) {
            return ['verification' => 'echec'] + $base;
        }

        // Un délégué signe au titre de la délégation en vigueur à l'instant de sa signature, pas de celle d'aujourd'hui.
        $delegation = null;
        $destinataire = $remise->a_type === 'destinataire' && $remise->a_id !== null ? Destinataire::find($remise->a_id) : null;
        if ($destinataire !== null && ! $this->perimetre->estTitulaireDe($utilisateur, $destinataire)) {
            $delegation = $this->delegations->activeA($destinataire, $utilisateur, $horodatage);
        }

        $contenu = $this->preuves->contenuPreuve($remise, $signataire, $utilisateur->id, $lignes, $cle, $horodatageTerminal);
        $resultat = $this->preuves->verifier($contenu, $cle, $blocPreuve, $utilisateur, $horodatage, $delegation);

        return ['verification' => $resultat['verification'], 'bloc_empreinte' => $resultat['bloc_empreinte'], 'delegation_id' => $delegation?->id] + $base;
    }

    private function horodatage(string $texte): Carbon
    {
        $instant = Carbon::createFromFormat('Y-m-d\TH:i:sP', $texte);
        if ($instant === false || $instant->format('Y-m-d\TH:i:sP') !== $texte) {
            throw new InvalidArgumentException("L'horodatage doit être au format ISO 8601 avec décalage, par exemple 2026-11-05T10:00:00+00:00.");
        }

        return $instant;
    }

    /**
     * @param  array<string, mixed>|null  $preuve
     * @return array{verification: string, bloc_empreinte: ?string, signataire_user_id: ?int, delegation_id: ?int}|null
     */
    private function preuve(string $mode, ?array $preuve): ?array
    {
        if ($preuve === null) {
            // Une déclaration de tablette sans vérification n'établit pas l'identité de son signataire.
            return $mode === 'tablette'
                ? ['verification' => 'non_verifiee', 'bloc_empreinte' => null, 'signataire_user_id' => null, 'delegation_id' => null]
                : null;
        }
        if ($mode !== 'tablette') {
            throw new InvalidArgumentException('La preuve ne concerne que les déclarations de tablette.');
        }
        $verification = $preuve['verification'] ?? null;
        if (! is_string($verification) || ! array_key_exists($verification, Declaration::VERIFICATIONS) || $verification === 'compte') {
            throw new InvalidArgumentException("Vérification d'identité inconnue.");
        }
        $bloc = $preuve['bloc_empreinte'] ?? null;
        if ($bloc !== null && (! is_string($bloc) || preg_match('/^[0-9a-f]{64}$/', $bloc) !== 1)) {
            throw new InvalidArgumentException('Empreinte du bloc de preuve invalide.');
        }

        return [
            'verification' => $verification, 'bloc_empreinte' => $bloc,
            'signataire_user_id' => isset($preuve['signataire_user_id']) ? (int) $preuve['signataire_user_id'] : null,
            'delegation_id' => isset($preuve['delegation_id']) ? (int) $preuve['delegation_id'] : null,
        ];
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
            if (preg_match('/^[0-9a-f]{64}$/', $empreinte) !== 1 || ! is_string($role) || ! array_key_exists($role, DeclarationPiece::ROLES)) {
                throw new InvalidArgumentException('Pièce annoncée invalide : empreinte SHA-256 et rôle (signature ou photo) attendus.');
            }
            $retenues[$empreinte] = $role;
        }

        return $retenues;
    }

    /** @return array{lat: float, lng: float, precision: ?int}|null */
    private function gps(?array $gps): ?array
    {
        if ($gps === null) {
            return null;
        }
        $lat = $gps['lat'] ?? null;
        $lng = $gps['lng'] ?? null;
        $precision = $gps['precision'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180
            || ($precision !== null && (! is_numeric($precision) || (int) $precision < 0 || (int) $precision > 100_000_000))) {
            throw new InvalidArgumentException('Position GPS invalide.');
        }

        return ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6), 'precision' => $precision === null ? null : (int) $precision];
    }

    /**
     * Recalcule le statut d'acheminement de la décharge d'une remise après une retenue, un écartement ou une
     * anomalie : « à vérifier » tant qu'une de ses remises l'est ; sinon « reçue par le destinataire » si le
     * destinataire final a une déclaration retenue, « reçue par l'intermédiaire » si une autre remise en a une,
     * et « chargée » si aucune ne l'est. Une redistribution partielle laisse la décharge reçue par le destinataire.
     *
     * Hors de declarer(), l'appelant détient déjà le verrou du cycle (ordre des verrous : cycle d'abord).
     */
    public function apresRetenue(Remise $remise): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($remise) {
            $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
            if ($decharge->statut_acheminement === 'a_preparer') {
                return;
            }

            $remises = Remise::where('decharge_id', $decharge->id)
                ->whereIn('etape', ['livraison', 'redistribution'])->where('statut', '!=', 'annulee')->get();

            if ($remises->contains('statut', 'a_verifier')) {
                $cible = 'a_verifier';
            } else {
                $retenues = Declaration::whereIn('remise_id', $remises->pluck('id'))->where('statut', 'retenue')->pluck('remise_id');
                $final = $remises->whereIn('id', $retenues->all())->first(
                    fn (Remise $r) => $r->a_type === 'destinataire' && (int) $r->a_id === (int) $decharge->destinataire_id
                );
                $cible = $final !== null ? 'recue_destinataire' : ($retenues->isNotEmpty() ? 'recue_intermediaire' : 'chargee');
            }

            if ($cible !== $decharge->statut_acheminement) {
                $avant = $decharge->statut_acheminement;
                $decharge->forceFill(['statut_acheminement' => $cible])->save();
                Journal::noter('decharge', $decharge->id, 'acheminement_decharge', ['statut_acheminement' => $avant], [
                    'statut_acheminement' => $cible, 'remise_id' => $remise->id,
                ]);
            }

            // Service résolu à l'appel : le service des réclamations dépend lui-même de celui-ci.
            app(ReclamationService::class)->verifierRemplacements($decharge);
        }, attempts: 3);
    }

    private function controlerEtape(Remise $remise): void
    {
        if (! in_array($remise->etape, ['livraison', 'redistribution'], true)) {
            throw new RegleLogistique("Cette remise n'attend pas de déclaration.");
        }
    }

    private function controlerStatut(Remise $remise, string $mode): void
    {
        if (! in_array($remise->statut, ['en_attente', 'a_verifier'], true) && ! $this->attendUneConfirmation($remise)) {
            throw new RegleLogistique("Cette remise n'attend pas de déclaration.");
        }
        $dejaFaite = Declaration::where('remise_id', $remise->id)->where('mode', $mode)
            ->whereNotIn('statut', ['ecartee', 'remplacee'])->exists();
        if ($dejaFaite) {
            throw new RegleLogistique('Une déclaration '.mb_strtolower(Declaration::MODES[$mode]).' existe déjà pour cette remise.');
        }
    }

    /**
     * Qui a le droit de déclarer, et avec quelles anomalies : le compte de l'autorité destinataire ; la tablette
     * de l'agent de la décharge (un ancien agent est accepté mais à vérifier) ou, sur une redistribution,
     * celle de l'autorité qui redistribue. Au compte, le délégué en vigueur de l'autorité destinataire déclare
     * aussi, et la délégation est rapportée ; le départ d'une redistribution reste celui de l'autorité elle-même.
     *
     * @return array{anomalies: list<string>, delegation: ?Delegation}
     */
    private function controlerDroit(User $auteur, Remise $remise, Decharge $decharge, string $mode): array
    {
        if (! Gate::forUser($auteur)->allows('logistique-terrain')) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        if ($mode === 'compte') {
            $destinataire = $remise->a_type === 'destinataire' && $remise->a_id !== null ? Destinataire::find($remise->a_id) : null;
            // Un seul instant pour les deux contrôles : une délégation qui prend fin entre les deux ne passe pas.
            $maintenant = now();
            if ($destinataire === null || ! $this->perimetre->estAutoriteDe($auteur, $destinataire, $maintenant)) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }

            $delegation = $this->perimetre->estTitulaireDe($auteur, $destinataire) ? null : $this->delegations->activeA($destinataire, $auteur, $maintenant);
            if ($delegation === null && ! $this->perimetre->estTitulaireDe($auteur, $destinataire)) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }

            return ['anomalies' => [], 'delegation' => $delegation];
        }

        if ($remise->etape === 'livraison') {
            if ($remise->de_type !== 'agent') {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }
            if ($decharge->agent_id !== null && (int) $decharge->agent_id === (int) $auteur->id) {
                return ['anomalies' => [], 'delegation' => null];
            }
            if ((int) $remise->de_id === (int) $auteur->id) {
                return ['anomalies' => ['affectation_retiree'], 'delegation' => null];
            }
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        $destinataire = $remise->de_type === 'destinataire' && $remise->de_id !== null ? Destinataire::find($remise->de_id) : null;
        if ($destinataire === null || ! $this->perimetre->estTitulaireDe($auteur, $destinataire)) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        return ['anomalies' => [], 'delegation' => null];
    }

    /**
     * Une remise déjà reçue attend encore la déclaration de l'autre mode, tant qu'aucun arbitrage n'a tranché.
     */
    private function attendUneConfirmation(Remise $remise): bool
    {
        return $remise->statut === 'recue' && ! Arbitrage::where('remise_id', $remise->id)->exists();
    }

    /**
     * Anomalies tirées des quantités ; refuse une déclaration incomplète ou incohérente.
     *
     * @param  list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>  $lignes
     * @return list<string>
     */
    private function controlerLignes(Remise $remise, array $lignes): array
    {
        $attendues = RemiseLigne::where('remise_id', $remise->id)->pluck('quantite', 'materiel_id')
            ->map(fn ($quantite) => (int) $quantite)->all();
        $declarees = array_column($lignes, 'materiel_id');
        sort($declarees);
        $ids = array_map('intval', array_keys($attendues));
        sort($ids);
        if ($declarees !== $ids) {
            throw new InvalidArgumentException('Chaque ligne de la remise doit être déclarée.');
        }

        $anomalies = [];
        foreach ($lignes as $ligne) {
            if ($ligne['defectueux'] > $ligne['recu']) {
                $libelle = Materiel::find($ligne['materiel_id'])?->libelle ?? "article {$ligne['materiel_id']}";
                throw new InvalidArgumentException("Défectueux supérieur au reçu pour {$libelle}.");
            }
            if ($ligne['recu'] > $attendues[$ligne['materiel_id']]) {
                $anomalies[] = 'excedent';
            }
        }

        return $anomalies;
    }

    /**
     * Rapproche les déclarations actives de la remise (ni écartées ni remplacées) : une seule saine est
     * retenue ; deux de modes différents, saines et identiques, se confirment (la plus ancienne est retenue) ;
     * deux divergentes sont toutes deux à vérifier, et rien n'est compté jusqu'à l'arbitrage.
     *
     * Hors de declarer() (arbitrage), l'appelant détient déjà les verrous : cycle, décharge, remise.
     */
    public function rapprocher(Remise $remise): void
    {
        $actives = Declaration::where('remise_id', $remise->id)->whereNotIn('statut', ['ecartee', 'remplacee'])
            ->orderBy('id')->lockForUpdate()->with('lignes')->get()->values();

        if ($actives->count() > 2) {
            throw new LogicException('Plus de deux déclarations actives sur une remise : invariant rompu.');
        }

        $cibles = [];
        $divergence = false;
        // Une retenue contestée dont la réception sert déjà en aval est figée (redistribution, exemplaire de
        // réception) : la défaire ferait tomber le solde du préfet sous ce qui est déjà remis. Elle ne bouge qu'à
        // la levée de la contestation ou à l'arbitrage ; toute autre déclaration active est réconciliée comme si
        // aucune déclaration saine n'existait (à vérifier, jamais retenue à côté d'elle).
        $figee = $actives->first(fn (Declaration $d) => $d->statut === 'retenue' && $d->contestation === 'contestee');
        if ($figee !== null) {
            foreach ($actives as $declaration) {
                $cibles[$declaration->id] = $declaration->id === $figee->id ? 'retenue' : 'a_verifier';
                if ($declaration->id !== $figee->id && $this->contenu($declaration) !== $this->contenu($figee)) {
                    $divergence = true;
                }
            }
        } elseif ($actives->count() === 1) {
            $cibles[$actives[0]->id] = $this->saine($actives[0]) ? 'retenue' : 'a_verifier';
        } elseif ($actives->count() === 2) {
            [$premiere, $seconde] = [$actives[0], $actives[1]];
            if ($this->contenu($premiere) !== $this->contenu($seconde)) {
                $divergence = true;
                $cibles = [$premiere->id => 'a_verifier', $seconde->id => 'a_verifier'];
            } elseif ($this->saine($premiere) && $this->saine($seconde)) {
                $cibles = [$premiere->id => 'retenue', $seconde->id => 'concordante'];
            } else {
                $cibles = [$premiere->id => 'a_verifier', $seconde->id => 'a_verifier'];
            }
        }

        foreach ($actives as $declaration) {
            if ($figee !== null && $declaration->id === $figee->id) {
                continue;
            }
            $cible = $cibles[$declaration->id];
            $anomalies = $declaration->anomalies ?? [];
            if ($divergence && ! in_array('divergence', $anomalies, true)) {
                $anomalies[] = 'divergence';
            }
            if ($cible === $declaration->statut && $anomalies === ($declaration->anomalies ?? [])) {
                continue;
            }

            $avant = $declaration->statut;
            $declaration->forceFill(['statut' => $cible, 'anomalies' => $anomalies === [] ? null : $anomalies])->save();
            if ($cible === 'retenue') {
                Journal::noter('declaration', $declaration->id, 'retenue_declaration', ['statut' => $avant], [
                    'statut' => 'retenue', 'remise_id' => $remise->id, 'mode' => $declaration->mode,
                ]);
            }
            if ($cible !== $avant) {
                Journal::noter('declaration', $declaration->id, 'statut_declaration', ['statut' => $avant], [
                    'statut' => $cible, 'remise_id' => $remise->id, 'anomalies' => $anomalies,
                ]);
            }
        }

        if ($divergence) {
            Journal::noter('remise', $remise->id, 'divergence_declarations', null, [
                'declarations' => $actives->pluck('id')->all(), 'contenus' => $actives->map(fn ($d) => $this->contenu($d))->all(),
            ]);
        }

        $statut = $actives->contains(fn ($d) => $cibles[$d->id] === 'a_verifier') ? 'a_verifier'
            : ($actives->contains(fn ($d) => $cibles[$d->id] === 'retenue') ? 'recue' : null);
        if ($statut !== null && $remise->statut !== $statut) {
            $avant = $remise->statut;
            $remise->forceFill(['statut' => $statut])->save();
            Journal::noter('remise', $remise->id, 'statut_remise', ['statut' => $avant], ['statut' => $statut]);
        }
    }

    /**
     * Une déclaration saine n'a aucune anomalie propre (une divergence passée ne compte plus : le contenu est figé)
     * et n'est pas contestée : une déclaration contestée n'est jamais retenue d'office, seul l'arbitrage la retient.
     * Une contestation levée redonne à la déclaration son traitement normal.
     */
    private function saine(Declaration $declaration): bool
    {
        return $declaration->contestation !== 'contestee' && array_diff($declaration->anomalies ?? [], ['divergence']) === [];
    }

    /** @return list<array{0: int, 1: int, 2: int}> */
    private function contenu(Declaration $declaration): array
    {
        return $declaration->lignes->sortBy('materiel_id')
            ->map(fn (DeclarationLigne $l) => [(int) $l->materiel_id, (int) $l->recu, (int) $l->defectueux])->values()->all();
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
     * Lignes normalisées (entiers, triées par article) ; les bornes sont vérifiées ici.
     *
     * @return list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>
     */
    private function normaliser(array $lignes): array
    {
        $normalisees = [];
        foreach ($lignes as $materielId => $ligne) {
            if (! is_int($materielId) || ! is_array($ligne)) {
                throw new InvalidArgumentException('Chaque ligne de la remise doit être déclarée.');
            }
            $observation = $ligne['observation'] ?? null;
            if ($observation !== null && ! is_string($observation)) {
                throw new InvalidArgumentException("L'observation doit être un texte.");
            }
            $observation = $observation === null ? null : trim($observation);
            $observation = $observation === '' ? null : $observation;
            if ($observation !== null && mb_strlen($observation) > 500) {
                throw new InvalidArgumentException("L'observation est limitée à 500 caractères.");
            }

            $normalisees[] = [
                'materiel_id' => $materielId,
                'recu' => $this->quantite($ligne['recu'] ?? null),
                'defectueux' => $this->quantite($ligne['defectueux'] ?? null),
                'observation' => $observation,
            ];
        }
        usort($normalisees, fn (array $a, array $b) => $a['materiel_id'] <=> $b['materiel_id']);

        return $normalisees;
    }

    private function quantite(mixed $valeur): int
    {
        if (is_string($valeur) && preg_match('/^-?\d{1,12}$/', $valeur) === 1) {
            $valeur = (int) $valeur;
        }
        if (! is_int($valeur) || $valeur < 0 || $valeur > self::MAX_QUANTITE) {
            throw new InvalidArgumentException('Les quantités déclarées doivent être des entiers de 0 à 10 000 000.');
        }

        return $valeur;
    }

    /**
     * Empreinte du contenu : SHA-256 du JSON canonique {remise, mode, signataire, lignes triées par article}.
     *
     * @param  array{nom: string, qualite: string}  $signataire
     * @param  list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>  $lignes
     */
    private static function empreinte(int $remiseId, string $mode, array $signataire, array $lignes): string
    {
        $contenu = ['remise_id' => $remiseId, 'mode' => $mode, 'signataire' => $signataire, 'lignes' => $lignes];

        return Canonique::empreinte($contenu);
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
