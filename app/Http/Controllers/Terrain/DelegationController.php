<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\ExecuteAction;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DelegationService;
use App\Services\Logistique\PerimetreTerrain;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Délégations de signature de l'autorité à ses adjoints, et celles qu'un adjoint a reçues. */
class DelegationController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly DelegationService $delegations,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function index(Request $request)
    {
        $this->exigerAutorite($request);
        $utilisateur = $request->user();

        $destinataires = Destinataire::where('autorite_user_id', $utilisateur->id)->orderBy('libelle')->get();

        return view('terrain.delegations.index', [
            'destinataires' => $destinataires,
            'donnees' => Delegation::whereIn('destinataire_id', $destinataires->pluck('id')->all())
                ->with(['destinataire', 'delegue'])->orderByDesc('du')->orderByDesc('id')->get(),
            'recues' => Delegation::where('delegue_user_id', $utilisateur->id)
                ->with(['destinataire', 'autorite'])->orderByDesc('du')->orderByDesc('id')->get(),
            'candidats' => User::where('actif', true)->whereIn('role', CodeReceptionService::ROLES_AUTORITE)
                ->where('id', '!=', $utilisateur->id)->orderBy('name')->orderBy('prenom')->get(),
            'maintenant' => now(),
        ]);
    }

    public function store(Request $request)
    {
        $this->exigerAutorite($request);

        $donnees = $request->validate([
            'destinataire_id' => 'required|integer|exists:destinataires,id',
            'delegue_user_id' => 'required|integer|exists:users,id',
            'du' => 'required|date',
            'au' => 'nullable|date',
            'motif' => 'required|string|max:500',
        ], [
            'destinataire_id.required' => 'Choisissez le destinataire à déléguer.',
            'destinataire_id.exists' => 'Destinataire inconnu.',
            'delegue_user_id.required' => 'Choisissez le délégué.',
            'delegue_user_id.exists' => 'Délégué inconnu.',
            'du.required' => 'Indiquez le début de la délégation.',
            'du.date' => 'Le début de la délégation n\'est pas une date valide.',
            'au.date' => 'La fin de la délégation n\'est pas une date valide.',
            'motif.required' => 'Le motif est obligatoire.',
            'motif.max' => 'Le motif est limité à 500 caractères.',
        ]);

        return $this->executerVers(
            fn () => $this->delegations->deleguer(
                Destinataire::findOrFail($donnees['destinataire_id']),
                User::findOrFail($donnees['delegue_user_id']),
                Carbon::parse($donnees['du']),
                empty($donnees['au']) ? null : Carbon::parse($donnees['au']),
                $donnees['motif'],
            ),
            fn () => route('terrain.delegations.index'),
            'Délégation enregistrée.',
        );
    }

    public function revoquer(Request $request, Delegation $delegation)
    {
        $this->exigerAutorite($request);
        // Hors de ses destinataires, la délégation n'existe pas pour l'utilisateur (ni le délégué lui-même).
        abort_unless($this->perimetre->estTitulaireDe($request->user(), Destinataire::findOrFail($delegation->destinataire_id)), 404);

        $donnees = $request->validate(['motif' => 'required|string|max:500'], [
            'motif.required' => 'Le motif de la révocation est obligatoire.',
            'motif.max' => 'Le motif est limité à 500 caractères.',
        ]);

        return $this->executerVers(
            fn () => $this->delegations->revoquer($delegation, $donnees['motif']),
            fn () => route('terrain.delegations.index'),
            'Délégation révoquée : elle ne donne plus aucun accès.',
        );
    }

    /** Les agents de transport ne reçoivent ni ne donnent de délégation. */
    private function exigerAutorite(Request $request): void
    {
        abort_unless($request->user()->hasRole(CodeReceptionService::ROLES_AUTORITE), 403);
    }
}
