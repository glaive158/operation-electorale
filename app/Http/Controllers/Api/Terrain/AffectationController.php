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

    /** Les éléments de remise et, à côté, les restitutions de l'utilisateur (P6-R9 : `elements` inchangé). */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        return $this->reponse($this->affectations->pour($utilisateur), $this->affectations->restitutions($utilisateur));
    }

    /** Une décharge du périmètre ; inconnue ou hors périmètre : 404. */
    public function show(Request $request, string $identifiant): JsonResponse
    {
        $utilisateur = $request->user();
        $decharge = $this->perimetre->decharges($utilisateur)->where('identifiant', $identifiant)->firstOrFail();

        return $this->reponse($this->affectations->pour($utilisateur, $decharge));
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  list<array<string, mixed>>|null  $restitutions  absentes du détail d'une décharge
     */
    private function reponse(array $elements, ?array $restitutions = null): JsonResponse
    {
        $corps = ['genere_le' => now()->toIso8601String(), 'elements' => $elements];
        if ($restitutions !== null) {
            $corps['restitutions'] = $restitutions;
        }

        return response()->json($corps);
    }
}
