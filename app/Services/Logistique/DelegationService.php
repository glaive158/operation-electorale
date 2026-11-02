<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Délégations de signature. L'autorité d'un destinataire (ou le responsable logistique) confie à un préfet,
 * un sous-préfet ou un chef de poste actif le droit de recevoir à sa place ce qui est remis à ce destinataire.
 *
 * Une délégation ne remonte jamais dans le temps : elle ne peut pas commencer dans le passé, pour qu'on ne puisse
 * pas régulariser après coup une réception déjà signée. Elle vaut de son début (inclus) à la première des dates de
 * fin et de révocation (exclue) ; activeA() répond donc aussi pour un instant passé, après une révocation.
 */
class DelegationService
{
    private const MAX_MOTIF = 500;

    /** Tolérance sur le début : un formulaire envoie l'heure à la minute près. */
    private const TOLERANCE_DEBUT_MINUTES = 5;

    private const DROIT_REFUSE = 'Seule l\'autorité du destinataire ou le responsable logistique peut déléguer.';

    public function __construct(private readonly PerimetreTerrain $perimetre) {}

    public function deleguer(Destinataire $d, User $delegue, CarbonInterface $du, ?CarbonInterface $au, string $motif): Delegation
    {
        $this->exigerAuteur();
        $motif = $this->motif($motif);
        $du = Carbon::instance($du)->startOfSecond();
        $au = $au === null ? null : Carbon::instance($au)->startOfSecond();
        if ($au !== null && $au <= $du) {
            throw new InvalidArgumentException('La fin de la délégation doit suivre son début.');
        }

        $delegation = DB::transaction(function () use ($d, $delegue, $du, $au, $motif) {
            // Le verrou du destinataire ordonne les délégations concurrentes sur lui (chevauchement).
            $destinataire = Destinataire::whereKey($d->id)->lockForUpdate()->firstOrFail();
            $auteur = auth()->user();
            if (! $this->perimetre->estTitulaireDe($auteur, $destinataire) && ! Gate::allows('logistique-administrer')) {
                throw new RegleLogistique(self::DROIT_REFUSE);
            }
            if ($destinataire->autorite_user_id === null) {
                throw new RegleLogistique('Ce destinataire n\'a pas de compte d\'autorité : rattachez-en un avant de déléguer.');
            }
            if ($du < now()->subMinutes(self::TOLERANCE_DEBUT_MINUTES)) {
                throw new RegleLogistique('Cette délégation ne peut pas commencer dans le passé : une réception déjà signée n\'est pas régularisée par une délégation.');
            }

            $compte = User::find($delegue->id);
            if ($compte === null || ! $compte->actif || ! in_array($compte->role, CodeReceptionService::ROLES_AUTORITE, true)) {
                throw new RegleLogistique('Le délégué doit être un préfet, un sous-préfet ou un chef de poste actif.');
            }
            if ((int) $compte->id === (int) $destinataire->autorite_user_id) {
                throw new RegleLogistique('Une autorité ne peut pas se déléguer à elle-même.');
            }
            if ($this->chevauche($destinataire, $compte, $du, $au)) {
                throw new RegleLogistique('Cette personne a déjà une délégation sur ce destinataire pour cette période.');
            }

            $delegation = new Delegation([
                'destinataire_id' => $destinataire->id,
                'autorite_user_id' => $destinataire->autorite_user_id,
                'delegue_user_id' => $compte->id,
                'du' => $du, 'au' => $au, 'motif' => $motif, 'cree_par' => $auteur->id,
            ]);
            $delegation->save();

            Journal::noter('delegation', $delegation->id, 'delegation_creee', null, [
                'destinataire_id' => $destinataire->id,
                'autorite_user_id' => (int) $destinataire->autorite_user_id,
                'delegue_user_id' => $compte->id,
                'du' => $du->toIso8601String(),
                'au' => $au?->toIso8601String(),
            ], $motif);

            return $delegation;
        }, attempts: 3);

        return $delegation->refresh();
    }

    public function revoquer(Delegation $x, string $motif): void
    {
        $this->exigerAuteur();
        $motif = $this->motif($motif);

        DB::transaction(function () use ($x, $motif) {
            $delegation = Delegation::whereKey($x->id)->lockForUpdate()->firstOrFail();
            $destinataire = Destinataire::findOrFail($delegation->destinataire_id);
            if (! $this->perimetre->estTitulaireDe(auth()->user(), $destinataire) && ! Gate::allows('logistique-administrer')) {
                throw new RegleLogistique('Seule l\'autorité du destinataire ou le responsable logistique peut révoquer une délégation.');
            }
            if ($delegation->revoquee_le !== null) {
                throw new RegleLogistique('Cette délégation est déjà révoquée.');
            }
            $maintenant = now()->startOfSecond();
            if ($delegation->au !== null && $delegation->au <= $maintenant) {
                throw new RegleLogistique('Cette délégation est déjà terminée.');
            }

            $delegation->forceFill(['revoquee_le' => $maintenant])->save();

            Journal::noter('delegation', $delegation->id, 'delegation_revoquee', ['revoquee_le' => null], [
                'revoquee_le' => $maintenant->toIso8601String(),
                'destinataire_id' => (int) $delegation->destinataire_id,
                'delegue_user_id' => (int) $delegation->delegue_user_id,
            ], $motif);
        }, attempts: 3);

        $x->refresh();
    }

    /** La délégation de $u sur $d qui était en vigueur à cet instant (même si elle a été révoquée depuis). */
    public function activeA(Destinataire $d, User $u, CarbonInterface $instant): ?Delegation
    {
        return Delegation::where('destinataire_id', $d->id)->where('delegue_user_id', $u->id)
            ->activesA($instant)->orderByDesc('du')->orderByDesc('id')->first();
    }

    /** Une délégation existante de ce délégué sur ce destinataire couvre-t-elle une part de la période ? */
    private function chevauche(Destinataire $d, User $delegue, CarbonInterface $du, ?CarbonInterface $au): bool
    {
        return Delegation::where('destinataire_id', $d->id)->where('delegue_user_id', $delegue->id)->get()
            ->contains(function (Delegation $existante) use ($du, $au) {
                $fin = $existante->fin();

                return ($au === null || $existante->du < $au) && ($fin === null || $fin > $du);
            });
    }

    private function motif(string $motif): string
    {
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_MOTIF) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        return $motif;
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
