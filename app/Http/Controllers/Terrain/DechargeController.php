<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\LibellesRemises;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\RemiseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Décharges du périmètre de l'utilisateur de terrain : liste et page de détail, avec les actions qu'il peut mener. */
class DechargeController extends Controller
{
    private const PAR_PAGE = 50;

    /** Qualité proposée au signataire d'une déclaration, d'après le rôle. */
    public const QUALITES = Delegation::QUALITES + [
        'agent_transport' => 'Agent de transport',
    ];

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly RemiseService $remises,
        private readonly LibellesRemises $libelles,
    ) {}

    public function index(Request $request)
    {
        $decharges = $this->perimetre->decharges($request->user())
            ->with(['destinataire', 'cycle'])
            ->orderByDesc('id')
            ->paginate(self::PAR_PAGE)->withQueryString();

        return view('terrain.decharges.index', ['decharges' => $decharges]);
    }

    public function show(Request $request, Decharge $decharge)
    {
        $utilisateur = $request->user();
        // Profil sans espace de décharges (gouverneur, commission…), hors périmètre ou brouillon : 404, pour ne rien révéler.
        abort_unless(Gate::allows('logistique-terrain') && $this->perimetre->peutVoir($utilisateur, $decharge), 404);

        $decharge->load([
            'cycle', 'destinataire', 'remisA', 'agent',
            'lignes' => fn ($q) => $q->orderBy('materiel_id'),
            'lignes.materiel', 'reclamations.materiel',
        ]);
        $ouvert = $decharge->cycle->estOuvert();
        $ecriture = Gate::allows('logistique-terrain');

        $remises = $this->remisesVisibles($utilisateur, $decharge);
        $destinataires = Destinataire::whereIn('id', $remises->where('a_type', 'destinataire')->pluck('a_id')->filter()->all())->get()->keyBy('id');
        $arbitrees = Arbitrage::whereIn('remise_id', $remises->pluck('id'))->pluck('remise_id')->all();

        // Déclaration « compte » : seule l'autorité destinataire de la remise, tant que celle-ci en attend une.
        $declarables = $ecriture ? $remises->filter(function (Remise $remise) use ($utilisateur, $destinataires, $arbitrees) {
            $destinataire = $remise->a_type === 'destinataire' ? $destinataires->get($remise->a_id) : null;
            if (! in_array($remise->etape, ['livraison', 'redistribution'], true)
                || $destinataire === null || ! $this->perimetre->estAutoriteDe($utilisateur, $destinataire)) {
                return false;
            }
            $attend = in_array($remise->statut, ['en_attente', 'a_verifier'], true)
                || ($remise->statut === 'recue' && ! in_array($remise->id, $arbitrees, true));
            $dejaFait = $remise->declarations->contains(
                fn ($d) => $d->mode === 'compte' && ! in_array($d->statut, ['ecartee', 'remplacee'], true)
            );

            return $attend && ! $dejaFait;
        })->pluck('id')->all() : [];

        // L'autorité de la préfecture d'une décharge qui passe par elle (le préfet ou son adjoint habilité) : solde,
        // redistribution et annulation.
        $estPrefetDeLaDecharge = $ecriture && $decharge->circuit === 'via_prefet'
            && $decharge->remisA?->type === 'prefecture'
            && $this->perimetre->estAutoriteDe($utilisateur, $decharge->remisA);
        $solde = $estPrefetDeLaDecharge && $decharge->estPartie() ? $this->remises->soldePrefet($decharge) : collect();
        $peutRedistribuer = $estPrefetDeLaDecharge && $ouvert && $decharge->statut_document === 'validee'
            && $solde->contains(fn (array $s) => $s['mobilisable'] > 0);
        $annulables = $estPrefetDeLaDecharge && $ouvert
            ? $remises->filter(fn (Remise $r) => $r->etape === 'redistribution' && $r->statut === 'en_attente' && $r->declarations->isEmpty())->pluck('id')->all()
            : [];

        return view('terrain.decharges.show', [
            'decharge' => $decharge,
            'cycle' => $decharge->cycle,
            'remises' => $remises,
            'parties' => $this->libelles->pour($remises),
            'declarables' => $declarables,
            'cles' => collect($declarables)->mapWithKeys(fn ($id) => [$id => (string) Str::ulid()])->all(),
            'solde' => $solde,
            'peutRedistribuer' => $peutRedistribuer,
            'annulables' => $annulables,
            'peutReclamer' => $ecriture && $decharge->estPartie(),
            'ecriture' => $ecriture,
            'signataireNom' => $utilisateur->nom_complet,
            'signataireQualite' => self::QUALITES[$utilisateur->role] ?? '',
        ]);
    }

    /**
     * Les remises de la décharge qui touchent le périmètre : reçues ou données par ses destinataires, et pour
     * un agent, celles où il prend ou livre le matériel.
     *
     * @return Collection<int, Remise>
     */
    private function remisesVisibles(User $utilisateur, Decharge $decharge): Collection
    {
        $ids = $this->perimetre->destinataireIds($utilisateur)->all();

        return Remise::where('decharge_id', $decharge->id)
            ->where(function (Builder $q) use ($ids, $utilisateur) {
                $q->where(fn (Builder $c) => $c->where('a_type', 'destinataire')->whereIn('a_id', $ids))
                    ->orWhere(fn (Builder $c) => $c->where('de_type', 'destinataire')->whereIn('de_id', $ids));
                if ($utilisateur->role === 'agent_transport') {
                    $q->orWhere(fn (Builder $c) => $c->where('a_type', 'agent')->where('a_id', $utilisateur->id))
                        ->orWhere(fn (Builder $c) => $c->where('de_type', 'agent')->where('de_id', $utilisateur->id));
                }
            })
            ->with(['lignes.materiel', 'declarations.lignes.materiel'])
            ->orderBy('id')->get();
    }
}
