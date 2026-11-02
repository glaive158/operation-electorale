<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /** Tables logistiques portant un auteur (user_id) : attributions_stock n'en a pas, ses écritures passent par le journal. */
    private const TABLES_LOGISTIQUES = [
        'mouvements_stock', 'reservations_stock', 'journal_logistique', 'commandes', 'receptions_fournisseur',
    ];

    public function index(Request $request)
    {
        $query = User::query();
        if ($request->filled('role'))   $query->where('role', $request->role);
        if ($request->filled('actif'))  $query->where('actif', (bool)$request->actif);
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn($s) =>
                $s->where('name', 'like', "%$q%")
                  ->orWhere('prenom', 'like', "%$q%")
                  ->orWhere('email', 'like', "%$q%")
            );
        }
        $users = $query->latest()->paginate(20)->withQueryString();
        return view('users.index', compact('users'));
    }

    public function create()
    {
        [$regions, $depts, $arrs, $communes] = self::geoData();
        return view('users.create', compact('regions', 'depts', 'arrs', 'communes'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:100',
            'prenom'            => 'nullable|string|max:100',
            'email'             => 'required|email|unique:users',
            'password'          => ['required', Password::min(8)],
            'role'              => 'required|in:admin,gouverneur,prefet,sous_prefet,commission,logistique_gestionnaire,logistique_responsable,chef_poste,agent_transport',
            'telephone'         => 'nullable|string|max:20',
            'region_id'         => 'nullable|integer',
            'region_nom'        => 'nullable|string|max:100',
            'departement_id'    => 'nullable|integer',
            'departement_nom'   => 'nullable|string|max:100',
            'arrondissement_id' => 'nullable|integer',
            'arrondissement_nom'=> 'nullable|string|max:100',
            'commune_id'        => 'nullable|integer',
            'commune_nom'       => 'nullable|string|max:100',
            'actif'             => 'boolean',
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['actif']    = $request->boolean('actif', true);

        // Une autorité de terrain choisit son code personnel de réception à sa première connexion.
        (new User($data))->forceFill([
            'doit_choisir_code' => in_array($data['role'], CodeReceptionService::ROLES_AUTORITE, true),
        ])->save();
        return redirect()->route('users.index')->with('success', 'Utilisateur créé avec succès.');
    }

    public function show(User $user)
    {
        return view('users.show', compact('user'));
    }

    public function edit(User $user)
    {
        [$regions, $depts, $arrs, $communes] = self::geoData();
        return view('users.edit', compact('user', 'regions', 'depts', 'arrs', 'communes'));
    }

    private static function geoData(): array
    {
        $db = DB::connection('recensement');
        return [
            $db->table('regions')->orderBy('nom')->get(['id', 'nom']),
            $db->table('departements')->orderBy('nom')->get(['id', 'nom', 'region_id']),
            $db->table('arrondissements')->orderBy('nom')->get(['id', 'nom', 'departement_id']),
            $db->table('communes')->orderBy('nom')->get(['id', 'nom', 'arrondissement_id']),
        ];
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:100',
            'prenom'            => 'nullable|string|max:100',
            'email'             => "required|email|unique:users,email,{$user->id}",
            'password'          => ['nullable', Password::min(8)],
            'role'              => 'required|in:admin,gouverneur,prefet,sous_prefet,commission,logistique_gestionnaire,logistique_responsable,chef_poste,agent_transport',
            'telephone'         => 'nullable|string|max:20',
            'region_id'         => 'nullable|integer',
            'region_nom'        => 'nullable|string|max:100',
            'departement_id'    => 'nullable|integer',
            'departement_nom'   => 'nullable|string|max:100',
            'arrondissement_id' => 'nullable|integer',
            'arrondissement_nom'=> 'nullable|string|max:100',
            'commune_id'        => 'nullable|integer',
            'commune_nom'       => 'nullable|string|max:100',
            'actif'             => 'boolean',
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $data['actif'] = $request->boolean('actif');

        // Un changement de rôle, dans un sens ou dans l'autre, annule le code de réception : le compte est
        // enregistré et son code annulé ensemble.
        DB::transaction(function () use ($user, $data) {
            $ancienRole = $user->role;
            $user->update($data);
            if ($user->role !== $ancienRole) {
                app(CodeReceptionService::class)->apresChangementDeRole($user);
            }
        });
        return redirect()->route('users.index')->with('success', 'Utilisateur mis à jour.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Impossible de supprimer votre propre compte.');
        }

        // Les écritures logistiques sont imputables et immuables : un compte qui en porte ne se supprime pas.
        $liaisonLogistique = 'Utilisateur lié à des opérations logistiques : désactivez le compte au lieu de le supprimer.';
        foreach (self::TABLES_LOGISTIQUES as $table) {
            if (DB::table($table)->where('user_id', $user->id)->exists()) {
                return back()->with('error', $liaisonLogistique);
            }
        }

        try {
            $user->delete();
        } catch (QueryException $e) {
            // Contrainte d'intégrité (écriture logistique concurrente) : même refus, pas d'erreur 500.
            if ($e->getCode() === '23000') {
                return back()->with('error', $liaisonLogistique);
            }
            throw $e;
        }

        return redirect()->route('users.index')->with('success', 'Utilisateur supprimé.');
    }
}
