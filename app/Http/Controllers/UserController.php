<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
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
            'role'              => 'required|in:admin,gouverneur,prefet,sous_prefet,commission',
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

        User::create($data);
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
            'role'              => 'required|in:admin,gouverneur,prefet,sous_prefet,commission',
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

        $user->update($data);
        return redirect()->route('users.index')->with('success', 'Utilisateur mis à jour.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Impossible de supprimer votre propre compte.');
        }
        $user->delete();
        return redirect()->route('users.index')->with('success', 'Utilisateur supprimé.');
    }
}
