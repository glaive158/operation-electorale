<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Premier accès à l'espace terrain : tant que l'autorité n'a pas choisi son code personnel de réception,
 * toute page terrain la mène au choix du code (les pages du code elles-mêmes restent ouvertes).
 */
class ExigerChoixCodeReception
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if ($utilisateur !== null && $utilisateur->doit_choisir_code && ! $request->routeIs('terrain.code.*')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Choisissez d\'abord votre code personnel de réception.'], 403);
            }

            return redirect()->route('terrain.code.edit');
        }

        return $next($request);
    }
}
