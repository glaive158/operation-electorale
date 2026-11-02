<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Support\Canonique;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Bloc de preuve d'une déclaration de terrain. À la saisie du code personnel, la tablette chiffre
 * {code, cle, empreinte} avec la clé publique du serveur (RSA-OAEP, SHA-1) : ni le code ni un moyen de le
 * vérifier ne restent sur l'appareil. Au dépôt, le serveur déchiffre le bloc, contrôle qu'il atteste bien
 * le contenu déclaré (clé d'idempotence et empreinte canonique) puis vérifie le code de l'autorité.
 *
 * Le code n'est ni journalisé, ni écrit dans un message d'erreur, ni renvoyé : il ne vit que le temps de
 * la vérification, en mémoire.
 *
 * Verrous : ce service ne prend aucun verrou de cycle. L'appelant (DeclarationService::declarer, via l'API)
 * l'invoque avant d'ouvrir sa transaction, une seule fois par nouvelle déclaration, après avoir traité
 * le rejeu idempotent (même clé, même contenu) : un échec de code est compté à chaque appel.
 */
class PreuveService
{
    /** États internes de l'étape « délégation » : « en règle » n'est jamais un résultat de verifier(). */
    private const DELEGATION_EN_REGLE = 'delegation_en_regle';

    /** Gravité des états, de la meilleure à la pire : le pire l'emporte. */
    private const RANG = ['verifiee_code' => 0, self::DELEGATION_EN_REGLE => 0, 'a_verifier' => 1, 'echec' => 2];

    /** Écart d'horloge toléré de la tablette, avant la création de la remise ou après l'instant présent. */
    private const TOLERANCE_HORLOGE_MINUTES = 10;

    private const REFUS_REUTILISE = 'Preuve déjà utilisée par une autre déclaration.';

    private const REFUS_CONTENU = 'Preuve ne correspondant pas au contenu de la déclaration.';

    public function __construct(private readonly CodeReceptionService $codes, private readonly DelegationService $delegations) {}

    /** Clé publique au format PEM (SPKI), publiée aux tablettes. */
    public function clePublique(): string
    {
        $details = openssl_pkey_get_details($this->clePrivee());
        if ($details === false || ! isset($details['key'])) {
            throw new LogicException('Clé de preuve illisible.');
        }

        return (string) $details['key'];
    }

    /** SHA-256 hexadécimal (minuscules) de la clé publique encodée en DER SPKI : de quoi la reconnaître à l'œil. */
    public function empreinteClePublique(?string $pem = null): string
    {
        $pem ??= $this->clePublique();
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true);
        if ($der === false || $der === '') {
            throw new LogicException('Clé publique illisible.');
        }

        return hash('sha256', $der);
    }

    /**
     * Le contenu dont l'empreinte entre dans le bloc. La tablette construit exactement le même (voir
     * resources/js/terrain), avec l'horodatage tel qu'elle l'envoie.
     *
     * @param  array{nom?: string, qualite?: string}  $signataire
     * @param  array<int|string, array<string, mixed>>  $lignes  par identifiant d'article, ou liste de lignes portant materiel_id
     * @return array<string, mixed>
     */
    public function contenuPreuve(Remise $r, array $signataire, int $signataireUserId, array $lignes, string $cle, string $horodatageTerminal): array
    {
        $identifiant = Decharge::whereKey($r->decharge_id)->value('identifiant');
        if ($identifiant === null) {
            throw new LogicException('Remise sans décharge : invariant rompu.');
        }

        $normalisees = [];
        foreach ($lignes as $materielId => $ligne) {
            $observation = isset($ligne['observation']) && is_string($ligne['observation']) ? trim($ligne['observation']) : null;
            $normalisees[] = [
                'materiel_id' => (int) ($ligne['materiel_id'] ?? $materielId),
                'recu' => (int) ($ligne['recu'] ?? 0),
                'defectueux' => (int) ($ligne['defectueux'] ?? 0),
                'observation' => $observation === '' ? null : $observation,
            ];
        }
        usort($normalisees, fn (array $a, array $b) => $a['materiel_id'] <=> $b['materiel_id']);

        return [
            'decharge_id' => (int) $r->decharge_id,
            'decharge_identifiant' => (string) $identifiant,
            'remise_id' => (int) $r->id,
            'cle' => $cle,
            'signataire' => [
                'user_id' => $signataireUserId,
                'nom' => (string) ($signataire['nom'] ?? ''),
                'qualite' => (string) ($signataire['qualite'] ?? ''),
            ],
            'lignes' => $normalisees,
            'horodatage_terminal' => $horodatageTerminal,
        ];
    }

    /**
     * Empreinte (SHA-256 des octets chiffrés) sous laquelle un bloc est mémorisé ; null pour un bloc absent,
     * vide ou qui n'est pas du base64 : il n'y a alors rien à mémoriser.
     */
    public static function empreinteBloc(?string $blocBase64): ?string
    {
        if ($blocBase64 === null || $blocBase64 === '') {
            return null;
        }
        $chiffre = base64_decode($blocBase64, true);

        return $chiffre === false || $chiffre === '' ? null : hash('sha256', $chiffre);
    }

    /**
     * Contrôle le bloc d'une déclaration encore à créer.
     *
     * Rejets (RegleLogistique, journal « anomalie_preuve ») : bloc déjà porté par une déclaration ; bloc qui
     * n'atteste pas ce contenu ou dont l'horodatage n'est pas celui du contenu. Sinon l'état d'identité :
     * « verifiee_code », « a_verifier » (code changé ou réinitialisé, même pas encore rechoisi, ou délégation
     * révoquée depuis la signature hors ligne) ou « echec » (bloc vide, mal formé ou indéchiffrable ; signataire qui
     * n'est pas l'autorité attendue de la remise ; horodatage hors bornes ; compte verrouillé ; aucun code valide à
     * l'instant de la signature ; code faux ; délégation jamais en vigueur). Seul un code faux face à un code en
     * vigueur est compté (trois échecs verrouillent le compte).
     *
     * bloc_empreinte vaut null quand le bloc est vide ou n'est pas du base64 : rien n'est à mémoriser.
     *
     * @param  array<string, mixed>  $contenu  le contenu de contenuPreuve()
     * @return array{verification: string, bloc_empreinte: ?string}
     */
    public function verifier(array $contenu, string $cle, string $blocBase64, User $signataire, CarbonInterface $horodatageTerminal, ?Delegation $delegation): array
    {
        // Les comparaisons en base (codes, délégations) lisent des dates sans fuseau, dans celui de l'application :
        // l'instant de la tablette y est ramené. Le décalage d'origine ne sert qu'au contenu attesté (memeInstant).
        $horodatageTerminal = Carbon::instance($horodatageTerminal)->setTimezone((string) config('app.timezone'));
        $chiffre = base64_decode($blocBase64, true);
        if ($chiffre === false || $chiffre === '') {
            return $this->resultat('echec', null);
        }
        $blocEmpreinte = hash('sha256', $chiffre);

        // 1. Un bloc ne sert qu'une fois : il est lié à une déclaration par son empreinte.
        $porteur = Declaration::where('bloc_empreinte', $blocEmpreinte)->value('id');
        if ($porteur !== null) {
            $this->refuser((int) $porteur, 'bloc_reutilise', $signataire, self::REFUS_REUTILISE);
        }

        // 2. Déchiffrement ; tout ce qui n'est pas un bloc complet est un échec d'identité.
        $bloc = $this->dechiffrer($chiffre);
        if ($bloc === null) {
            return $this->resultat('echec', $blocEmpreinte);
        }

        // 3. Le bloc doit attester cette clé d'idempotence et ce contenu, signé par ce compte, à cet instant.
        $signataireDuContenu = $contenu['signataire']['user_id'] ?? null;
        if (! hash_equals($cle, $bloc['cle'])
            || ! hash_equals(Canonique::empreinte($contenu), $bloc['empreinte'])
            || ($signataireDuContenu !== null && (int) $signataireDuContenu !== (int) $signataire->id)
            || ! $this->memeInstant($contenu['horodatage_terminal'] ?? null, $horodatageTerminal)) {
            $this->refuser((int) ($contenu['remise_id'] ?? 0), 'contenu_different', $signataire, self::REFUS_CONTENU, 'remise');
        }

        // Qui peut signer cette remise, et quand : un échec ici n'est pas une erreur de code, donc non compté.
        if (! $this->signataireAttendu($contenu, $signataire, $horodatageTerminal, $delegation)) {
            return $this->resultat('echec', $blocEmpreinte);
        }

        // 4. Le code, puis 5. la délégation : le pire des deux états l'emporte.
        $etat = $this->verifierCode($signataire, $bloc['code'], $horodatageTerminal);
        if ($delegation !== null) {
            $etat = $this->pire($etat, $this->verifierDelegation($delegation, $signataire, $horodatageTerminal));
        }

        return $this->resultat($etat, $blocEmpreinte);
    }

    private function memeInstant(mixed $texte, CarbonInterface $instant): bool
    {
        if (! is_string($texte) || $texte === '') {
            return false;
        }
        try {
            return Carbon::parse($texte)->getTimestamp() === $instant->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * La remise du contenu va à un destinataire ; le signataire en est le titulaire, ou le délégué d'une
     * délégation de CE destinataire ; l'horodatage tient entre la création de la remise et l'instant présent.
     *
     * @param  array<string, mixed>  $contenu
     */
    private function signataireAttendu(array $contenu, User $signataire, CarbonInterface $horodatage, ?Delegation $delegation): bool
    {
        $remise = Remise::find((int) ($contenu['remise_id'] ?? 0));
        if ($remise === null || $remise->a_type !== 'destinataire' || $remise->a_id === null) {
            return false;
        }
        $destinataire = Destinataire::find($remise->a_id);
        if ($destinataire === null) {
            return false;
        }

        // Sans date de création, la borne basse est invérifiable : l'identité n'est pas tenue pour établie.
        if ($remise->created_at === null
            || $horodatage->greaterThan(now()->addMinutes(self::TOLERANCE_HORLOGE_MINUTES))
            || $horodatage->lessThan(Carbon::instance($remise->created_at)->subMinutes(self::TOLERANCE_HORLOGE_MINUTES))) {
            return false;
        }

        if ($delegation === null) {
            return app(PerimetreTerrain::class)->estTitulaireDe($signataire, $destinataire);
        }

        return (int) $delegation->destinataire_id === (int) $destinataire->id
            && (int) $delegation->delegue_user_id === (int) $signataire->id;
    }

    private function verifierCode(User $signataire, #[\SensitiveParameter] string $code, CarbonInterface $horodatage): string
    {
        $compte = User::find($signataire->id);
        if ($compte === null || ! in_array($compte->role, CodeReceptionService::ROLES_AUTORITE, true)) {
            return 'echec';
        }
        // Verrouillé : rien à comparer, et rien à compter.
        if ($compte->code_verrouille_le !== null) {
            return 'echec';
        }
        $actuel = $compte->doit_choisir_code ? null : $this->codes->codeActuel($compte);
        if ($actuel === null) {
            // Code réinitialisé et pas encore rechoisi (ou jamais choisi) : seul le code valide à l'instant de la
            // signature peut être comparé. Juste, l'identité est à vérifier ; faux, c'est un échec, jamais compté
            // (il n'y a pas de code en vigueur à protéger).
            $alors = $this->codes->codeValideA($compte, $horodatage);

            return $alors !== null && $this->codes->correspond($compte, $code, $alors) ? 'a_verifier' : 'echec';
        }

        if ($this->codes->correspond($compte, $code, $actuel)) {
            $this->codes->reinitialiserEchecs($compte);

            return 'verifiee_code';
        }

        // Le code que l'autorité utilisait à l'instant de la signature, changé ou réinitialisé depuis.
        $alors = $this->codes->codeValideA($compte, $horodatage);
        if ($alors !== null && $alors !== $actuel && $this->codes->correspond($compte, $code, $alors)) {
            return 'a_verifier';
        }

        $this->codes->enregistrerEchec($compte);

        return 'echec';
    }

    private function verifierDelegation(Delegation $delegation, User $signataire, CarbonInterface $horodatage): string
    {
        $destinataire = Destinataire::find($delegation->destinataire_id);
        if ($destinataire === null || (int) $delegation->delegue_user_id !== (int) $signataire->id) {
            return 'echec';
        }
        // La délégation en vigueur à l'instant de la signature doit être celle qui est invoquée.
        $alors = $this->delegations->activeA($destinataire, $signataire, $horodatage);
        if ($alors === null || (int) $alors->id !== (int) $delegation->id) {
            return 'echec';
        }

        return $this->delegations->activeA($destinataire, $signataire, now()) === null ? 'a_verifier' : self::DELEGATION_EN_REGLE;
    }

    private function pire(string $a, string $b): string
    {
        return self::RANG[$a] >= self::RANG[$b] ? $a : $b;
    }

    /** @return array{verification: string, bloc_empreinte: ?string} */
    private function resultat(string $verification, ?string $blocEmpreinte): array
    {
        return ['verification' => $verification, 'bloc_empreinte' => $blocEmpreinte];
    }

    /** @return array{code: string, cle: string, empreinte: string}|null */
    private function dechiffrer(string $chiffre): ?array
    {
        $cle = $this->clePrivee();
        $details = openssl_pkey_get_details($cle);
        // Un chiffré RSA fait exactement la taille du module : toute autre longueur est refusée d'emblée.
        if ($details === false || strlen($chiffre) !== intdiv((int) $details['bits'], 8)) {
            return null;
        }

        $clair = '';
        // Une erreur OpenSSL (bloc tronqué, mauvaise clé) n'est jamais une exception : on vide la pile des erreurs.
        $ok = @openssl_private_decrypt($chiffre, $clair, $cle, OPENSSL_PKCS1_OAEP_PADDING);
        while (openssl_error_string() !== false) {
            // vider la pile
        }
        if (! $ok) {
            return null;
        }

        $donnees = json_decode($clair, true);
        if (! is_array($donnees) || ! is_string($donnees['code'] ?? null) || ! is_string($donnees['cle'] ?? null) || ! is_string($donnees['empreinte'] ?? null)) {
            return null;
        }

        return ['code' => $donnees['code'], 'cle' => $donnees['cle'], 'empreinte' => $donnees['empreinte']];
    }

    /**
     * Journalise l'anomalie (sans code ni contenu du bloc) puis refuse.
     *
     * @return never
     */
    private function refuser(int $objetId, string $nature, User $signataire, string $message, string $objetType = 'declaration'): void
    {
        Journal::noter($objetType, $objetId, 'anomalie_preuve', null, [
            'nature' => $nature, 'signataire_user_id' => (int) $signataire->id,
        ], $message);

        throw new RegleLogistique($message);
    }

    private function clePrivee(): \OpenSSLAsymmetricKey
    {
        $chemin = (string) config('logistique.cle_preuve_privee');
        $pem = $chemin !== '' && is_file($chemin) ? file_get_contents($chemin) : false;
        $cle = $pem === false ? false : openssl_pkey_get_private($pem);
        if ($cle === false) {
            throw new LogicException('Clé de preuve absente ou illisible : lancez « php artisan logistique:cle-preuve ».');
        }

        return $cle;
    }
}
