<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ce qu'un utilisateur de terrain a le droit de voir : les destinataires dont il est l'autorité
 * (ou, pour un gouverneur, ceux de sa région) et les décharges qui s'y rattachent. Un brouillon
 * n'est jamais visible sur le terrain ; un compte inactif n'a aucun périmètre.
 *
 * Un adjoint à qui une autorité a délégué un destinataire (délégation en vigueur maintenant) voit ce
 * destinataire et ses décharges et en reçoit les remises, sans en devenir le titulaire. Pour une préfecture,
 * l'adjoint habilité prépare aussi les redistributions et en signe le départ (estAutoriteDe) ; seules la
 * désignation de l'appareil (le central) et la délégation elle-même restent à l'autorité ou au central.
 */
class PerimetreTerrain
{
    public function __construct(private readonly LectureRecensement $recensement) {}

    /** @return Collection<int, int> */
    public function destinataireIds(User $utilisateur): Collection
    {
        return $this->portee($utilisateur)['ids'];
    }

    /**
     * Les destinataires de l'utilisateur (`ids`) et, parmi eux, ceux qu'une autorité lui a délégués, délégation
     * en vigueur maintenant (`delegues`).
     *
     * @return array{ids: Collection<int, int>, delegues: Collection<int, int>}
     */
    public function portee(User $utilisateur): array
    {
        $vide = collect();
        if (! $utilisateur->actif) {
            return ['ids' => $vide, 'delegues' => $vide];
        }

        $propres = match ($utilisateur->role) {
            'prefet' => $this->avecSousPrefectures(
                Destinataire::where('type', 'prefecture')->where('autorite_user_id', $utilisateur->id)->pluck('id')
            ),
            'sous_prefet', 'chef_poste' => Destinataire::where('autorite_user_id', $utilisateur->id)->pluck('id')
                ->map(fn ($id) => (int) $id)->values(),
            'gouverneur' => $this->avecSousPrefectures($this->prefecturesDeLaRegion($utilisateur)),
            default => $vide,
        };

        $delegues = in_array($utilisateur->role, CodeReceptionService::ROLES_AUTORITE, true)
            ? Delegation::where('delegue_user_id', $utilisateur->id)->activesA(now())
                ->orderBy('destinataire_id')->pluck('destinataire_id')->map(fn ($id) => (int) $id)->unique()->values()
            : $vide;

        return ['ids' => $propres->merge($delegues)->unique()->values(), 'delegues' => $delegues];
    }

    /** Décharges validées ou annulées visibles par l'utilisateur. */
    public function decharges(User $utilisateur): Builder
    {
        $requete = Decharge::query()->whereIn('statut_document', ['validee', 'annulee']);

        if (! $utilisateur->actif) {
            return $requete->whereRaw('1 = 0');
        }

        if ($utilisateur->role === 'agent_transport') {
            return $requete->where('agent_id', $utilisateur->id);
        }

        ['ids' => $ids, 'delegues' => $delegues] = $this->portee($utilisateur);
        if ($ids->isEmpty()) {
            return $requete->whereRaw('1 = 0');
        }

        // Le préfet voit aussi ce qui lui est remis pour redistribution ; un délégué, ce qui est remis au
        // destinataire qu'il représente (la livraison à la préfecture d'une décharge de sous-préfecture).
        $remisA = $utilisateur->role === 'prefet' ? $ids : $delegues;
        if ($remisA->isNotEmpty()) {
            return $requete->where(fn (Builder $q) => $q->whereIn('destinataire_id', $ids->all())->orWhereIn('remis_a_id', $remisA->all()));
        }

        return $requete->whereIn('destinataire_id', $ids->all());
    }

    public function peutVoir(User $utilisateur, Decharge $decharge): bool
    {
        return $this->decharges($utilisateur)->whereKey($decharge->id)->exists();
    }

    /** Vrai pour le titulaire du destinataire comme pour un adjoint à qui il l'a délégué, à l'instant (maintenant par défaut). */
    public function estAutoriteDe(User $utilisateur, Destinataire $destinataire, ?CarbonInterface $instant = null): bool
    {
        if ($this->estTitulaireDe($utilisateur, $destinataire)) {
            return true;
        }

        return (bool) $utilisateur->actif
            && in_array($utilisateur->role, CodeReceptionService::ROLES_AUTORITE, true)
            && Delegation::where('destinataire_id', $destinataire->id)->where('delegue_user_id', $utilisateur->id)
                ->activesA($instant ?? now())->exists();
    }

    /** Vrai pour le seul compte d'autorité du destinataire : un délégué n'est jamais titulaire. */
    public function estTitulaireDe(User $utilisateur, Destinataire $destinataire): bool
    {
        return (bool) $utilisateur->actif
            && $destinataire->autorite_user_id !== null
            && (int) $destinataire->autorite_user_id === (int) $utilisateur->id;
    }

    /** @return Collection<int, int> */
    private function prefecturesDeLaRegion(User $gouverneur): Collection
    {
        if ($gouverneur->region_id === null) {
            return collect();
        }

        $departements = $this->recensement->departements()
            ->where('region_id', (int) $gouverneur->region_id)->pluck('id')->all();

        return Destinataire::where('type', 'prefecture')->whereIn('departement_id', $departements)->pluck('id');
    }

    /**
     * @param  Collection<int, int|string>  $prefectures
     * @return Collection<int, int>
     */
    private function avecSousPrefectures(Collection $prefectures): Collection
    {
        $prefectures = $prefectures->map(fn ($id) => (int) $id)->values();
        if ($prefectures->isEmpty()) {
            return $prefectures;
        }

        return $prefectures
            ->merge(Destinataire::where('type', 'sous_prefecture')->whereIn('prefecture_id', $prefectures->all())->pluck('id')->map(fn ($id) => (int) $id))
            ->unique()->values();
    }
}
