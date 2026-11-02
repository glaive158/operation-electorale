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
    /** Valeurs de retour de verifier(), de la meilleure à la pire. */
    public const ORDRE = ['verifiee_code' => 0, 'a_verifier' => 1, 'echec' => 2];

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
     * Contrôle le bloc d'une déclaration encore à créer.
     *
     * Rejets (RegleLogistique, journal « anomalie_preuve ») : bloc déjà porté par une déclaration ; bloc qui
     * n'atteste pas ce contenu. Sinon l'état d'identité : « verifiee_code », « a_verifier » (code changé
     * ou délégation révoquée depuis la signature hors ligne) ou « echec » (bloc indéchiffrable, signataire
     * sans code utilisable ou verrouillé, code faux, délégation jamais en vigueur). Un code faux est compté
     * (trois échecs verrouillent le compte) ; les autres échecs ne le sont pas.
     *
     * @param  array<string, mixed>  $contenu  le contenu de contenuPreuve()
     * @return array{verification: string, bloc_empreinte: string}
     */
    public function verifier(array $contenu, string $cle, string $blocBase64, User $signataire, CarbonInterface $horodatageTerminal, ?Delegation $delegation): array
    {
        $chiffre = base64_decode($blocBase64, true);
        $blocEmpreinte = hash('sha256', $chiffre === false ? $blocBase64 : $chiffre);

        // 1. Un bloc ne sert qu'une fois : il est lié à une déclaration par son empreinte.
        $porteur = Declaration::where('bloc_empreinte', $blocEmpreinte)->value('id');
        if ($porteur !== null) {
            $this->refuser((int) $porteur, 'bloc_reutilise', $signataire, self::REFUS_REUTILISE);
        }

        // 2. Déchiffrement ; tout ce qui n'est pas un bloc complet est un échec d'identité.
        $bloc = $chiffre === false ? null : $this->dechiffrer($chiffre);
        if ($bloc === null) {
            return $this->resultat('echec', $blocEmpreinte);
        }

        // 3. Le bloc doit attester cette clé d'idempotence et ce contenu, signé par ce compte.
        $signataireDuContenu = $contenu['signataire']['user_id'] ?? null;
        if (! hash_equals($cle, $bloc['cle'])
            || ! hash_equals(Canonique::empreinte($contenu), $bloc['empreinte'])
            || ($signataireDuContenu !== null && (int) $signataireDuContenu !== (int) $signataire->id)) {
            $this->refuser((int) ($contenu['remise_id'] ?? 0), 'contenu_different', $signataire, self::REFUS_CONTENU, 'remise');
        }

        // 4. Le code, puis 5. la délégation : le pire des deux états l'emporte.
        $etat = $this->verifierCode($signataire, $bloc['code'], $horodatageTerminal);
        if ($delegation !== null) {
            $etat = $this->pire($etat, $this->verifierDelegation($delegation, $signataire, $horodatageTerminal));
        }

        return $this->resultat($etat, $blocEmpreinte);
    }

    private function verifierCode(User $signataire, string $code, CarbonInterface $horodatage): string
    {
        $compte = User::find($signataire->id);
        if ($compte === null || ! in_array($compte->role, CodeReceptionService::ROLES_AUTORITE, true)) {
            return 'echec';
        }
        // Verrouillé, sans code choisi ou à réinitialiser : rien à comparer, et rien à compter.
        if ($compte->code_verrouille_le !== null || $compte->doit_choisir_code) {
            return 'echec';
        }
        $actuel = $this->codes->codeActuel($compte);
        if ($actuel === null) {
            return 'echec';
        }

        if ($this->codes->correspond($compte, $code, $actuel)) {
            if ((int) $compte->code_echecs > 0) {
                User::whereKey($compte->id)->where('code_echecs', '>', 0)->update(['code_echecs' => 0]);
            }

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
        if ($this->delegations->activeA($destinataire, $signataire, $horodatage) === null) {
            return 'echec';
        }

        return $this->delegations->activeA($destinataire, $signataire, now()) === null ? 'a_verifier' : 'verifiee_code';
    }

    private function pire(string $a, string $b): string
    {
        return self::ORDRE[$a] >= self::ORDRE[$b] ? $a : $b;
    }

    /** @return array{verification: string, bloc_empreinte: string} */
    private function resultat(string $verification, string $blocEmpreinte): array
    {
        return ['verification' => $verification, 'bloc_empreinte' => $blocEmpreinte];
    }

    /** @return array{code: string, cle: string, empreinte: string}|null */
    private function dechiffrer(string $chiffre): ?array
    {
        $clair = '';
        // Une erreur OpenSSL (bloc tronqué, mauvaise clé) n'est jamais une exception : on vide la pile des erreurs.
        $ok = @openssl_private_decrypt($chiffre, $clair, $this->clePrivee(), OPENSSL_PKCS1_OAEP_PADDING);
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
