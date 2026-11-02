<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Services\Logistique\AffectationsTerrain;
use App\Services\Logistique\PerimetreTerrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffectationController extends Controller
{
    public function __construct(
        private readonly AffectationsTerrain $affectations,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->reponse($this->affectations->pour($request->user()));
    }

    /** Une décharge du périmètre ; inconnue ou hors périmètre : 404. */
    public function show(Request $request, string $identifiant): JsonResponse
    {
        $utilisateur = $request->user();
        $decharge = $this->perimetre->decharges($utilisateur)->where('identifiant', $identifiant)->firstOrFail();

        return $this->reponse($this->affectations->pour($utilisateur, $decharge));
    }

    /** @param  list<array<string, mixed>>  $elements */
    private function reponse(array $elements): JsonResponse
    {
        return response()->json(['genere_le' => now()->toIso8601String(), 'elements' => $elements]);
    }
}
