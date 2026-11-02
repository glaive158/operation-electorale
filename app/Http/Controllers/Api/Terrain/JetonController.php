<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class JetonController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'appareil' => ['required', 'string', 'max:100'],
        ]);

        $utilisateur = User::where('email', $donnees['email'])->first();

        // Mot de passe vérifié même si le compte n'existe pas, pour ne pas révéler son existence par le temps de réponse.
        $valide = Hash::check($donnees['password'], $utilisateur?->password ?? Hash::make(str()->random(16)));

        if (! $utilisateur || ! $valide) {
            return response()->json(['message' => 'Identifiants invalides.'], 422);
        }

        if (! Gate::forUser($utilisateur)->allows('logistique-terrain')) {
            return response()->json(['message' => 'Accès terrain refusé.'], 403);
        }

        $expire = now()->addDays((int) config('logistique.jeton_jours', 30));
        $jeton = $utilisateur->createToken($donnees['appareil'], ['terrain'], $expire);

        return response()->json([
            'token' => $jeton->plainTextToken,
            'utilisateur' => [
                'id' => $utilisateur->id,
                'nom' => trim($utilisateur->prenom.' '.$utilisateur->name),
                'role' => $utilisateur->role,
            ],
            'expire_le' => $expire->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }
}
