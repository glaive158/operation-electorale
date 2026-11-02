<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ce qu'un utilisateur de terrain a le droit de voir : les destinataires dont il est l'autorité
 * (ou, pour un gouverneur, ceux de sa région) et les décharges qui s'y rattachent. Un brouillon
 * n'est jamais visible sur le terrain ; un compte inactif n'a aucun périmètre.
 */
class PerimetreTerrain
{
    public function __construct(private readonly LectureRecensement $recensement) {}

    /** @return Collection<int, int> */
    public function destinataireIds(User $utilisateur): Collection
    {
        if (! $utilisateur->actif) {
            return collect();
        }

        return match ($utilisateur->role) {
            'prefet' => $this->avecSousPrefectures(
                Destinataire::where('type', 'prefecture')->where('autorite_user_id', $utilisateur->id)->pluck('id')
            ),
            'sous_prefet', 'chef_poste' => Destinataire::where('autorite_user_id', $utilisateur->id)->pluck('id')
                ->map(fn ($id) => (int) $id)->values(),
            'gouverneur' => $this->avecSousPrefectures($this->prefecturesDeLaRegion($utilisateur)),
            default => collect(),
        };
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

        $ids = $this->destinataireIds($utilisateur)->all();
        if ($ids === []) {
            return $requete->whereRaw('1 = 0');
        }

        if ($utilisateur->role === 'prefet') {
            // Le préfet voit aussi ce qui lui est remis pour redistribution.
            return $requete->where(fn (Builder $q) => $q->whereIn('destinataire_id', $ids)->orWhereIn('remis_a_id', $ids));
        }

        return $requete->whereIn('destinataire_id', $ids);
    }

    public function peutVoir(User $utilisateur, Decharge $decharge): bool
    {
        return $this->decharges($utilisateur)->whereKey($decharge->id)->exists();
    }

    public function estAutoriteDe(User $utilisateur, Destinataire $destinataire): bool
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
            ->merge(Destinataire::whereIn('prefecture_id', $prefectures->all())->pluck('id')->map(fn ($id) => (int) $id))
            ->unique()->values();
    }
}
