<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\User;
use App\Support\Canonique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ce qu'un utilisateur de terrain doit faire constater ou constater, sous la forme que la tablette
 * télécharge : un élément par remise en attente (ou à vérifier) et, pour le préfet, un élément sans
 * remise par décharge dont il peut encore préparer une redistribution (le préfet et son adjoint habilité ; ce qui est
 * réservé à l'appareil désigné n'y figure plus). Lecture seule, bornée au
 * périmètre de l'utilisateur.
 */
class AffectationsTerrain
{
    private const A_FAIRE = ['en_attente', 'a_verifier'];

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly RemiseService $remises,
        private readonly LibellesRemises $libelles,
        private readonly DeclarationService $declarations,
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
        $preparations = in_array($utilisateur->role, CodeReceptionService::ROLES_AUTORITE, true) ? $this->preparations($utilisateur, $decharges) : collect();

        return $this->elements($utilisateur, $remises, $preparations);
    }

    /** @return Builder<Remise> */
    private function remisesAFaire(User $utilisateur, Builder $deLaPortee)
    {
        $requete = Remise::query()->whereIn('decharge_id', $deLaPortee)->whereIn('statut', self::A_FAIRE);
        ['ids' => $destinataires, 'delegues' => $delegues] = $this->perimetre->portee($utilisateur);

        return match ($utilisateur->role) {
            'agent_transport' => $requete->where('etape', 'livraison'),
            // Le préfet constate ses livraisons et le départ de ses redistributions ; ce qui est adressé à un
            // destinataire qui lui est délégué se constate comme pour un sous-préfet (ce qu'on lui remet).
            'prefet' => $requete->where(function ($q) use ($utilisateur, $delegues) {
                $prefectures = $this->prefectures($utilisateur);
                $q->where(fn ($l) => $l->where('etape', 'livraison')->where('a_type', 'destinataire')->whereIn('a_id', $prefectures))
                    ->orWhere(fn ($r) => $r->where('etape', 'redistribution')->where('de_type', 'destinataire')->whereIn('de_id', $prefectures));
                if ($delegues->isNotEmpty()) {
                    $q->orWhere(fn ($d) => $d->where('etape', '!=', 'chargement')->where('a_type', 'destinataire')->whereIn('a_id', $delegues->all()));
                }
            }),
            // Un adjoint d'une préfecture (de tout rôle d'autorité) en signe aussi le départ des redistributions.
            'sous_prefet', 'chef_poste' => $requete->where(function ($q) use ($destinataires, $utilisateur) {
                $q->where(fn ($r) => $r->where('etape', '!=', 'chargement')->where('a_type', 'destinataire')->whereIn('a_id', $destinataires->all()));
                $prefectures = $this->prefectures($utilisateur);
                if ($prefectures !== []) {
                    $q->orWhere(fn ($d) => $d->where('etape', 'redistribution')->where('de_type', 'destinataire')->whereIn('de_id', $prefectures));
                }
            }),
            default => $requete->whereRaw('1 = 0'),
        };
    }

    /** @return list<int> les préfectures dont l'utilisateur est l'autorité : titulaire, ou adjoint habilité par une délégation en vigueur */
    private function prefectures(User $utilisateur): array
    {
        $propres = Destinataire::where('type', 'prefecture')->where('autorite_user_id', $utilisateur->id)->pluck('id');
        $deleguees = $this->perimetre->portee($utilisateur)['delegues'];
        $deleguees = $deleguees->isEmpty() ? $deleguees : Destinataire::where('type', 'prefecture')->whereIn('id', $deleguees->all())->pluck('id');

        return $propres->merge($deleguees)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Décharges reçues par la préfecture du préfet (ou de l'adjoint habilité), en circuit via le préfet, dont il reste
     * du matériel en bon état à redistribuer : décharge => mobilisable par article (identifiant d'article =>
     * quantité), hors ce qui est réservé à l'appareil désigné.
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
            ->whereExists(fn ($q) => $q->from('destinataires')->whereColumn('destinataires.id', 'decharges.destinataire_id')
                ->where('destinataires.type', 'sous_prefecture')->whereColumn('destinataires.prefecture_id', 'decharges.remis_a_id'))
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
                    ->map(fn (array $solde) => $solde['mobilisable'])->filter(fn (int $mobilisable) => $mobilisable > 0)->all();

                return ['decharge' => $d, 'reliquats' => $reliquats];
            })->filter(fn (array $p) => $p['reliquats'] !== [])->values();
    }

    /**
     * `peut_declarer_tablette` : l'utilisateur peut envoyer la déclaration depuis sa tablette (même règle que
     * l'envoi) ; sinon la tablette montre la remise sans la proposer (à signer sur la tablette de l'agent ou du
     * préfet, ou à confirmer depuis son compte). Toujours faux pour une préparation de redistribution.
     *
     * @param  Collection<int, Remise>  $remises
     * @param  Collection<int, array{decharge: Decharge, reliquats: array<int, int>}>  $preparations
     * @return list<array<string, mixed>>
     */
    private function elements(User $utilisateur, Collection $remises, Collection $preparations): array
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
            // L'autorité n'existe que pour un destinataire : un identifiant d'agent ne désigne jamais un destinataire.
            $autorite = $remise->a_type === 'destinataire' ? $autorites->get($remise->a_id) : null;
            $elements[] = $this->empreinter([
                'remise' => [
                    'id' => $remise->id, 'etape' => $remise->etape, 'statut' => $remise->statut,
                    'de' => $libelles[$remise->id]['de'], 'a' => $libelles[$remise->id]['a'],
                ],
                'decharge' => $this->decharge($decharges[$remise->decharge_id]),
                'autorite_attendue' => $autorite ?? $this->autoriteInconnue(),
                'lignes' => $lignes($remise->lignes->mapWithKeys(fn ($l) => [(int) $l->materiel_id => (int) $l->quantite])),
                'peut_declarer_tablette' => $this->declarations->peutDeclarerParTablette($utilisateur, $remise, $decharges[$remise->decharge_id]),
            ]);
        }
        foreach ($preparations as $preparation) {
            $decharge = $preparation['decharge'];
            $elements[] = $this->empreinter([
                'remise' => null,
                'decharge' => $this->decharge($decharge),
                'autorite_attendue' => $autorites[$decharge->destinataire_id] ?? $this->autoriteInconnue(),
                'lignes' => $lignes($preparation['reliquats']),
                'peut_declarer_tablette' => false,
            ]);
        }

        return $elements;
    }

    /**
     * Les restitutions de matériel réutilisable que la tablette montre à l'utilisateur, à côté des éléments de
     * remise (clé `restitutions` de l'API) : celles dont il est l'agent de retour désigné, et celles dont il est le
     * détenteur (autorité titulaire ou déléguée du destinataire, ou l'agent détenteur lui-même), tant qu'elles sont
     * à préparer ou en retour. Chacune porte son empreinte.
     *
     * `peut_declarer_tablette` : seul l'agent désigné, tant que la restitution est à préparer, et seulement pour un
     * destinataire (un agent détenteur déclare depuis son compte, P6-R16). `peut_prendre_en_charge` : l'agent qui
     * prendrait le retour en charge (le désigné, ou l'agent détenteur sans agent désigné), une fois une déclaration
     * retenue ; une déclaration à vérifier (`arbitrage_en_attente`) n'est pas anticipée : le serveur refuse alors
     * avec son message.
     *
     * @return list<array<string, mixed>>
     */
    public function restitutions(User $utilisateur): array
    {
        if (! $utilisateur->actif) {
            return [];
        }

        $detenus = $this->perimetre->autoriteDe($utilisateur)->all();
        $agent = $utilisateur->role === 'agent_transport';
        $restitutions = Restitution::query()->whereIn('statut', ['a_preparer', 'en_retour'])
            ->where(function (Builder $q) use ($utilisateur, $detenus, $agent) {
                $q->whereRaw('1 = 0');
                if ($agent) {
                    $q->orWhere('agent_id', $utilisateur->id)
                        ->orWhere(fn (Builder $a) => $a->where('detenteur_type', 'agent')->where('detenteur_id', $utilisateur->id));
                }
                if ($detenus !== []) {
                    $q->orWhere(fn (Builder $d) => $d->where('detenteur_type', 'destinataire')->whereIn('detenteur_id', $detenus));
                }
            })
            ->with('lignes', 'cycle')->orderBy('id')->get();
        if ($restitutions->isEmpty()) {
            return [];
        }

        $declarations = RestitutionDeclaration::whereIn('restitution_id', $restitutions->pluck('id'))
            ->whereIn('statut', ['retenue', 'a_verifier'])->get(['restitution_id', 'statut'])->groupBy('restitution_id');
        $materiels = Materiel::whereIn('id', $restitutions->flatMap->lignes->pluck('materiel_id')->unique()->all())->pluck('libelle', 'id');
        $agents = User::whereIn('id', $restitutions->where('detenteur_type', 'agent')->pluck('detenteur_id')->unique()->all())->get()->keyBy('id');
        $destinataireIds = $restitutions->where('detenteur_type', 'destinataire')->pluck('detenteur_id')->unique()->all();
        $libelles = Destinataire::whereIn('id', $destinataireIds)->pluck('libelle', 'id');
        $autorites = $this->autorites($destinataireIds);

        return $restitutions->map(function (Restitution $r) use ($utilisateur, $agent, $declarations, $materiels, $agents, $libelles, $autorites) {
            $statuts = $declarations->get($r->id, collect())->pluck('statut');
            $retenue = $statuts->contains('retenue');
            $estDestinataire = $r->detenteur_type === 'destinataire';
            $detenteurAgent = $estDestinataire ? null : $agents->get($r->detenteur_id);
            $nom = $estDestinataire ? (string) $libelles->get($r->detenteur_id, 'Destinataire') : ($detenteurAgent?->nom_complet ?: 'Agent');
            $designe = $r->agent_id !== null && (int) $r->agent_id === (int) $utilisateur->id;
            $preneur = $r->agent_id !== null ? $designe : ! $estDestinataire && (int) $r->detenteur_id === (int) $utilisateur->id;

            return $this->empreinter([
                'id' => $r->id,
                'statut' => $r->statut,
                'cycle' => (string) $r->cycle?->libelle,
                'detenteur' => ['type' => $r->detenteur_type, 'id' => (int) $r->detenteur_id, 'nom' => $nom],
                'autorite_attendue' => $estDestinataire
                    ? ($autorites->get($r->detenteur_id) ?? $this->autoriteInconnue())
                    : ['user_id' => $detenteurAgent?->id, 'nom' => $detenteurAgent?->nom_complet, 'qualite' => 'Agent de transport', 'delegues' => []],
                'lignes' => $r->lignes->sortBy('materiel_id')->map(fn ($l) => [
                    'materiel_id' => (int) $l->materiel_id, 'libelle' => (string) $materiels->get($l->materiel_id, ''), 'attendu' => (int) $l->attendu,
                ])->values()->all(),
                'declaration_retenue' => $retenue,
                'arbitrage_en_attente' => $statuts->contains('a_verifier'),
                'peut_declarer_tablette' => $agent && $designe && $estDestinataire && $r->statut === 'a_preparer',
                'peut_prendre_en_charge' => $agent && $preneur && $retenue && $r->statut === 'a_preparer',
            ]);
        })->values()->all();
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
     * enregistré sans compte, et les délégués en vigueur maintenant (chargés en un lot pour tous les destinataires).
     *
     * @param  list<int>  $destinataireIds
     * @return Collection<int, array<string, mixed>> par identifiant de destinataire
     */
    private function autorites(array $destinataireIds): Collection
    {
        $destinataires = Destinataire::whereIn('id', $destinataireIds)->get();
        $comptes = User::whereIn('id', $destinataires->pluck('autorite_user_id')->filter()->all())->get()->keyBy('id');
        $delegues = $this->delegues($destinataires->pluck('id')->all());

        return $destinataires->mapWithKeys(function (Destinataire $destinataire) use ($comptes, $delegues) {
            $compte = $destinataire->autorite_user_id === null ? null : $comptes->get($destinataire->autorite_user_id);

            return [$destinataire->id => [
                'user_id' => $compte?->id,
                'nom' => (string) ($destinataire->signataire_nom ?: $compte?->nom_complet),
                'qualite' => (string) $destinataire->signataire_qualite,
                'delegues' => $delegues->get($destinataire->id, []),
            ]];
        });
    }

    /**
     * Les délégués en vigueur maintenant, par identifiant de destinataire : un seul lot, comptes inclus, comme
     * le périmètre (compte actif d'un rôle d'autorité), triés par compte.
     *
     * @param  list<int>  $destinataireIds
     * @return Collection<int, list<array{user_id: int, nom: string, qualite: string}>>
     */
    private function delegues(array $destinataireIds): Collection
    {
        if ($destinataireIds === []) {
            return collect();
        }

        return Delegation::query()->activesA(now())->whereIn('delegations.destinataire_id', $destinataireIds)
            ->join('users', 'users.id', '=', 'delegations.delegue_user_id')
            ->where('users.actif', true)->whereIn('users.role', CodeReceptionService::ROLES_AUTORITE)
            ->orderBy('users.id')->orderBy('delegations.id')
            ->get(['delegations.destinataire_id', 'users.id as user_id', 'users.name', 'users.prenom', 'users.role'])
            ->groupBy('destinataire_id')
            ->map(fn (Collection $lignes) => $lignes->unique('user_id')->map(fn ($l) => [
                'user_id' => (int) $l->user_id,
                'nom' => trim($l->prenom.' '.$l->name),
                'qualite' => Delegation::QUALITES[$l->role] ?? '',
            ])->values()->all());
    }

    /** @return array<string, mixed> */
    private function autoriteInconnue(): array
    {
        return ['user_id' => null, 'nom' => null, 'qualite' => null, 'delegues' => []];
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
