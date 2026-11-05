<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\ExecuteAction;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Restitution;
use App\Models\User;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\PieceJointeService;
use App\Services\Logistique\RestitutionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Restitutions du détenteur, depuis son compte : celles des destinataires dont il est l'autorité (titulaire ou
 * délégué en vigueur), ou les siennes s'il est agent ; déclaration de ce qu'il rend (mode « compte »). Une
 * restitution dont il n'est pas le détenteur n'existe pas pour lui (404).
 */
class RestitutionController extends Controller
{
    use ExecuteAction;

    private const PAR_PAGE = 50;

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly RestitutionService $restitutions,
        private readonly PieceJointeService $pieces,
    ) {}

    public function index(Request $request)
    {
        $utilisateur = $request->user();
        $restitutions = $this->detenues($utilisateur)
            ->with(['cycle', 'agent', 'lignes.materiel', 'declarations' => fn ($q) => $q->orderBy('id'), 'declarations.lignes', 'declarations.pieces', 'declarations.signataireUser', 'declarations.delegation.autorite'])
            ->orderByDesc('id')->paginate(self::PAR_PAGE)->withQueryString();

        $destinataires = Destinataire::whereIn('id', $restitutions->getCollection()->where('detenteur_type', 'destinataire')->pluck('detenteur_id')->unique()->all())->pluck('libelle', 'id');
        // Au compte, une seule déclaration active par restitution, tant qu'elle est à préparer.
        $declarables = $restitutions->getCollection()->filter(fn (Restitution $r) => $r->statut === 'a_preparer'
            && ! $r->declarations->contains(fn ($d) => $d->mode === 'compte' && ! in_array($d->statut, ['ecartee', 'remplacee'], true)))
            ->pluck('id')->all();

        // Signature et photo : seulement celles que ce compte a le droit de voir.
        $piecesVisibles = $restitutions->getCollection()->flatMap(fn (Restitution $r) => $r->declarations)->flatMap(fn ($d) => $d->pieces)
            ->filter(fn ($piece) => $piece->piece_jointe_id !== null && $this->pieces->visiblePar($utilisateur, $piece->empreinte) !== null)
            ->pluck('empreinte')->unique()->values()->all();

        return view('terrain.restitutions.index', [
            'piecesVisibles' => $piecesVisibles,
            'restitutions' => $restitutions,
            'destinataires' => $destinataires,
            'declarables' => $declarables,
            'cles' => collect($declarables)->mapWithKeys(fn ($id) => [$id => (string) Str::ulid()])->all(),
            'signataireNom' => $utilisateur->nom_complet,
            'signataireQualite' => DechargeController::QUALITES[$utilisateur->role] ?? '',
        ]);
    }

    public function declarer(Request $request, Restitution $restitution)
    {
        abort_unless($this->detenues($request->user())->whereKey($restitution->id)->exists(), 404);

        $donnees = $request->validate([
            'cle' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'signataire_nom' => 'required|string|max:150',
            'signataire_qualite' => 'required|string|max:150',
            'lignes' => 'required|array|min:1',
            'lignes.*.restitue' => 'required|integer|min:0|max:10000000',
            'lignes.*.observation' => 'nullable|string|max:500',
        ]);

        $lignes = [];
        foreach ($donnees['lignes'] as $materielId => $ligne) {
            $lignes[(int) $materielId] = ['restitue' => (int) $ligne['restitue'], 'observation' => $ligne['observation'] ?? null];
        }

        return $this->executerVers(
            fn () => $this->restitutions->declarer(
                $restitution, 'compte', ['nom' => $donnees['signataire_nom'], 'qualite' => $donnees['signataire_qualite']],
                $lignes, $donnees['cle'],
            ),
            fn () => route('terrain.restitutions.index'),
            'Restitution déclarée.',
        );
    }

    /** Les restitutions dont l'utilisateur est le détenteur. */
    private function detenues(User $utilisateur): Builder
    {
        $destinataires = $this->perimetre->autoriteDe($utilisateur)->all();

        return Restitution::query()->where(function (Builder $q) use ($utilisateur, $destinataires) {
            $q->whereRaw('1 = 0');
            if ($destinataires !== []) {
                $q->orWhere(fn (Builder $d) => $d->where('detenteur_type', 'destinataire')->whereIn('detenteur_id', $destinataires));
            }
            if ($utilisateur->actif && $utilisateur->role === 'agent_transport') {
                $q->orWhere(fn (Builder $a) => $a->where('detenteur_type', 'agent')->where('detenteur_id', $utilisateur->id));
            }
        });
    }
}
