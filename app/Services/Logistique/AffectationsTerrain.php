<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Support\Canonique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ce qu'un utilisateur de terrain doit faire constater ou constater, sous la forme que la tablette
 * télécharge : un élément par remise en attente (ou à vérifier) et, pour le préfet, un élément sans
 * remise par décharge dont il peut encore préparer une redistribution. Lecture seule, bornée au
 * périmètre de l'utilisateur.
 */
class AffectationsTerrain
{
    private const A_FAIRE = ['en_attente', 'a_verifier'];

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly RemiseService $remises,
        private readonly LibellesRemises $libelles,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function pour(User $utilisateur, ?Decharge $decharge = null): array
    {
        if (! $utilisateur->actif) {
            return [];
        }

        $decharges = $this->perimetre->decharges($utilisateur)->where('statut_document', 'validee');
        if ($decharge !== null) {
            $decharges->whereKey($decharge->id);
        }

        $remises = $this->remisesAFaire($utilisateur, $decharges->clone()->select('decharges.id'))
            ->with('lignes')->orderBy('id')->get();
        $preparations = $utilisateur->role === 'prefet' ? $this->preparations($utilisateur, $decharges) : collect();

        return $this->elements($remises, $preparations);
    }

    /** @return Builder<Remise> */
    private function remisesAFaire(User $utilisateur, Builder $deLaPortee)
    {
        $requete = Remise::query()->whereIn('decharge_id', $deLaPortee)->whereIn('statut', self::A_FAIRE);
        $destinataires = $this->perimetre->destinataireIds($utilisateur)->all();

        return match ($utilisateur->role) {
            'agent_transport' => $requete->where('etape', 'livraison'),
            'prefet' => $requete->where(function ($q) use ($utilisateur) {
                $prefectures = $this->prefectures($utilisateur);
                $q->where(fn ($l) => $l->where('etape', 'livraison')->where('a_type', 'destinataire')->whereIn('a_id', $prefectures))
                    ->orWhere(fn ($r) => $r->where('etape', 'redistribution')->where('de_type', 'destinataire')->whereIn('de_id', $prefectures));
            }),
            'sous_prefet', 'chef_poste' => $requete->where('etape', '!=', 'chargement')
                ->where('a_type', 'destinataire')->whereIn('a_id', $destinataires),
            default => $requete->whereRaw('1 = 0'),
        };
    }

    /** @return list<int> les préfectures dont l'utilisateur est l'autorité */
    private function prefectures(User $utilisateur): array
    {
        return Destinataire::where('type', 'prefecture')
            ->whereIn('id', $this->perimetre->destinataireIds($utilisateur)->all())
            ->where('autorite_user_id', $utilisateur->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Décharges reçues par la préfecture du préfet, en circuit via le préfet, dont il reste du matériel
     * en bon état à redistribuer : décharge => reliquat par article (identifiant d'article => quantité).
     *
     * @return Collection<int, array{decharge: Decharge, reliquats: array<int, int>}>
     */
    private function preparations(User $utilisateur, Builder $decharges): Collection
    {
        $prefectures = $this->prefectures($utilisateur);
        if ($prefectures === []) {
            return collect();
        }

        $candidates = $decharges->clone()
            ->where('circuit', 'via_prefet')->whereIn('remis_a_id', $prefectures)
            ->whereColumn('destinataire_id', '!=', 'remis_a_id')
            ->whereExists(fn ($q) => $q->from('remises')->whereColumn('remises.decharge_id', 'decharges.id')
                ->where('remises.etape', 'livraison')->where('remises.statut', 'recue'))
            ->orderBy('id')->get();
        if ($candidates->isEmpty()) {
            return collect();
        }

        $ouverts = Cycle::whereIn('id', $candidates->pluck('cycle_id')->unique())->get()
            ->filter(fn (Cycle $cycle) => $cycle->estOuvert())->pluck('id')->all();

        // Trois requêtes par décharge candidate (soldePrefet) : une décharge par sous-préfecture et par catégorie.
        return $candidates->filter(fn (Decharge $d) => in_array($d->cycle_id, $ouverts, true))
            ->map(function (Decharge $d) {
                $reliquats = $this->remises->soldePrefet($d)
                    ->map(fn (array $solde) => $solde['reliquat'])->filter(fn (int $reliquat) => $reliquat > 0)->all();

                return ['decharge' => $d, 'reliquats' => $reliquats];
            })->filter(fn (array $p) => $p['reliquats'] !== [])->values();
    }

    /**
     * @param  Collection<int, Remise>  $remises
     * @param  Collection<int, array{decharge: Decharge, reliquats: array<int, int>}>  $preparations
     * @return list<array<string, mixed>>
     */
    private function elements(Collection $remises, Collection $preparations): array
    {
        $decharges = Decharge::whereIn('id', $remises->pluck('decharge_id')->all())->get()->keyBy('id')
            ->union($preparations->mapWithKeys(fn (array $p) => [$p['decharge']->id => $p['decharge']]));

        $materiels = Materiel::whereIn('id', $remises->flatMap->lignes->pluck('materiel_id')
            ->merge($preparations->flatMap(fn (array $p) => array_keys($p['reliquats'])))->unique()->all())->pluck('libelle', 'id');
        $lignes = fn (iterable $quantites) => collect($quantites)->map(fn (int $quantite, int $materielId) => [
            'materiel_id' => $materielId, 'libelle' => (string) $materiels->get($materielId, ''), 'quantite' => $quantite,
        ])->sortKeys()->values()->all();

        $libelles = $this->libelles->pour($remises);
        $autorites = $this->autorites(
            $remises->filter(fn (Remise $r) => $r->a_type === 'destinataire')->pluck('a_id')
                ->merge($preparations->map(fn (array $p) => $p['decharge']->destinataire_id))->unique()->all(),
        );

        $elements = [];
        foreach ($remises as $remise) {
            $elements[] = $this->empreinter([
                'remise' => [
                    'id' => $remise->id, 'etape' => $remise->etape, 'statut' => $remise->statut,
                    'de' => $libelles[$remise->id]['de'], 'a' => $libelles[$remise->id]['a'],
                ],
                'decharge' => $this->decharge($decharges[$remise->decharge_id]),
                'autorite_attendue' => $autorites[$remise->a_id] ?? $this->autoriteInconnue(),
                'lignes' => $lignes($remise->lignes->mapWithKeys(fn ($l) => [(int) $l->materiel_id => (int) $l->quantite])),
            ]);
        }
        foreach ($preparations as $preparation) {
            $decharge = $preparation['decharge'];
            $elements[] = $this->empreinter([
                'remise' => null,
                'decharge' => $this->decharge($decharge),
                'autorite_attendue' => $autorites[$decharge->destinataire_id] ?? $this->autoriteInconnue(),
                'lignes' => $lignes($preparation['reliquats']),
            ]);
        }

        return $elements;
    }

    /** @return array<string, mixed> */
    private function decharge(Decharge $decharge): array
    {
        return [
            'id' => $decharge->id, 'identifiant' => $decharge->identifiant, 'numero' => $decharge->numero,
            'categorie' => $decharge->categorie, 'nature' => $decharge->nature,
            'statut_document' => $decharge->statut_document, 'statut_acheminement' => $decharge->statut_acheminement,
        ];
    }

    /**
     * Qui doit signer pour chaque destinataire : le compte rattaché s'il y en a un, sinon le signataire
     * enregistré sans compte. Les délégués arrivent avec les délégations.
     *
     * @param  list<int>  $destinataireIds
     * @return Collection<int, array<string, mixed>> par identifiant de destinataire
     */
    private function autorites(array $destinataireIds): Collection
    {
        $destinataires = Destinataire::whereIn('id', $destinataireIds)->get();
        $comptes = User::whereIn('id', $destinataires->pluck('autorite_user_id')->filter()->all())->get()->keyBy('id');

        return $destinataires->mapWithKeys(function (Destinataire $destinataire) use ($comptes) {
            $compte = $destinataire->autorite_user_id === null ? null : $comptes->get($destinataire->autorite_user_id);

            return [$destinataire->id => [
                'user_id' => $compte?->id,
                'nom' => (string) ($destinataire->signataire_nom ?: $compte?->nom_complet),
                'qualite' => (string) $destinataire->signataire_qualite,
                'delegues' => [],
            ]];
        });
    }

    /** @return array<string, mixed> */
    private function autoriteInconnue(): array
    {
        return ['user_id' => null, 'nom' => '', 'qualite' => '', 'delegues' => []];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private function empreinter(array $element): array
    {
        return $element + ['empreinte_element' => Canonique::empreinte($element)];
    }
}
