<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CodeReception;
use App\Models\NotificationOp;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;

/**
 * Code personnel de réception : six chiffres choisis par chaque autorité de terrain, que la tablette fait saisir
 * pour signer une réception. Seul le haché est conservé (compte et historique) ; le code n'est jamais journalisé,
 * renvoyé ni écrit en clair. Les périodes de validité successives permettent de vérifier un code à l'instant où
 * la réception a été signée, même hors ligne, après un changement ou une réinitialisation.
 *
 * Trois échecs de vérification consécutifs verrouillent le compte et alertent le personnel responsable ; seule la
 * réinitialisation par un responsable lève le verrou.
 */
class CodeReceptionService
{
    /** Rôles qui signent des réceptions avec un code personnel (l'agent de transport n'en a pas). */
    public const ROLES_AUTORITE = ['prefet', 'sous_prefet', 'chef_poste'];

    public const ECHECS_AVANT_VERROU = 3;

    private const MAX_MOTIF = 500;

    public function choisir(User $u, string $code, string $motDePasse, ?string $nouveauMotDePasse = null): void
    {
        $this->exigerAuteur();
        if ((int) auth()->id() !== (int) $u->id) {
            throw new RegleLogistique('Vous ne pouvez choisir que votre propre code.');
        }
        if (! Hash::check($motDePasse, (string) $u->password)) {
            throw new RegleLogistique('Mot de passe incorrect.');
        }
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            throw new InvalidArgumentException('Le code doit comporter exactement 6 chiffres.');
        }
        if (preg_match('/^([0-9])\1{5}$/D', $code) || in_array($code, ['123456', '654321'], true)) {
            throw new InvalidArgumentException('Code trop simple.');
        }
        // Le code ne doit pas se déduire du mot de passe : ni l'actuel, ni celui choisi dans le même formulaire.
        if ($code === $motDePasse || ($nouveauMotDePasse !== null && $code === $nouveauMotDePasse)) {
            throw new InvalidArgumentException('Le code doit être différent du mot de passe.');
        }
        if ($nouveauMotDePasse !== null && $nouveauMotDePasse === $motDePasse) {
            throw new InvalidArgumentException('Le nouveau mot de passe doit être différent de l\'actuel.');
        }

        DB::transaction(function () use ($u, $code, $nouveauMotDePasse) {
            $actuel = $this->verrouillerCompte($u);
            $maintenant = now();
            $aUnCode = $actuel->code_reception_hash !== null;

            $this->cloreEntreeActive($actuel->id, $maintenant);
            $hash = Hash::make($code);
            CodeReception::create([
                'user_id' => $actuel->id, 'hash' => $hash, 'valide_du' => $maintenant, 'cree_par' => auth()->id(),
            ]);

            $actuel->forceFill([
                'code_reception_hash' => $hash,
                'code_echecs' => 0,
                'code_verrouille_le' => null,
                'doit_choisir_code' => false,
            ]);
            if ($nouveauMotDePasse !== null) {
                $actuel->password = $nouveauMotDePasse;
            }
            $actuel->save();

            Journal::noter('utilisateur', $actuel->id, 'choix_code_reception', [
                'code_defini' => $aUnCode,
            ], [
                'code_defini' => true,
                'mot_de_passe_modifie' => $nouveauMotDePasse !== null,
            ]);
        }, attempts: 3);

        $u->refresh();
    }

    public function reinitialiser(User $u, string $motif): void
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul le responsable logistique peut réinitialiser un code de réception.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_MOTIF) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }
        if (! in_array($u->role, self::ROLES_AUTORITE, true)) {
            throw new RegleLogistique('Ce compte n\'a pas de code de réception.');
        }

        DB::transaction(function () use ($u, $motif) {
            $actuel = $this->verrouillerCompte($u);
            $avant = [
                'code_defini' => $actuel->code_reception_hash !== null,
                'verrouille' => $actuel->code_verrouille_le !== null,
                'code_echecs' => (int) $actuel->code_echecs,
            ];

            $this->cloreEntreeActive($actuel->id, now());
            $actuel->forceFill([
                'code_reception_hash' => null,
                'code_echecs' => 0,
                'code_verrouille_le' => null,
                'doit_choisir_code' => true,
            ])->save();

            Journal::noter('utilisateur', $actuel->id, 'reinitialisation_code_reception', $avant, [
                'code_defini' => false, 'verrouille' => false, 'code_echecs' => 0,
            ], $motif);
        }, attempts: 3);

        $u->refresh();
    }

    /** Le haché du code qui était valide à cet instant (null si l'autorité n'avait pas de code). */
    public function codeValideA(User $u, CarbonInterface $instant): ?string
    {
        return CodeReception::where('user_id', $u->id)
            ->where('valide_du', '<=', $instant)
            ->where(fn ($q) => $q->whereNull('valide_au')->orWhere('valide_au', '>', $instant))
            ->orderByDesc('valide_du')->orderByDesc('id')
            ->value('hash');
    }

    /** Le haché du code en vigueur (null tant que l'autorité n'en a pas choisi). */
    public function codeActuel(User $u): ?string
    {
        return User::whereKey($u->id)->value('code_reception_hash');
    }

    /** À la troisième erreur de code, le compte est verrouillé et chaque responsable logistique actif est alerté. */
    public function enregistrerEchec(User $u): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($u) {
            $actuel = $this->verrouillerCompte($u);
            $echecs = min(255, (int) $actuel->code_echecs + 1);
            $verrouille = $echecs >= self::ECHECS_AVANT_VERROU && $actuel->code_verrouille_le === null;

            $actuel->forceFill(['code_echecs' => $echecs]);
            if ($verrouille) {
                $actuel->code_verrouille_le = now();
            }
            $actuel->save();

            Journal::noter('utilisateur', $actuel->id, 'echec_code_reception', ['code_echecs' => $echecs - 1], ['code_echecs' => $echecs]);

            if ($verrouille) {
                Journal::noter('utilisateur', $actuel->id, 'verrouillage_code_reception', null, ['code_echecs' => $echecs]);
                $nom = trim(($actuel->prenom ?? '').' '.$actuel->name);
                foreach (User::where('role', 'logistique_responsable')->where('actif', true)->get(['id']) as $responsable) {
                    NotificationOp::create([
                        'user_id' => $responsable->id,
                        'titre' => 'Code de réception verrouillé',
                        'message' => "Le code de réception de {$nom} est verrouillé après ".self::ECHECS_AVANT_VERROU.' erreurs. Réinitialisez-le depuis la page des codes de réception.',
                        'type' => 'logistique_alerte_code',
                    ]);
                }
            }
        }, attempts: 3);
    }

    private function verrouillerCompte(User $u): User
    {
        $compte = User::whereKey($u->id)->lockForUpdate()->first();
        if ($compte === null) {
            throw new LogicException('Compte introuvable : invariant rompu.');
        }

        return $compte;
    }

    private function cloreEntreeActive(int $userId, CarbonInterface $instant): void
    {
        CodeReception::where('user_id', $userId)->whereNull('valide_au')->update(['valide_au' => $instant]);
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
