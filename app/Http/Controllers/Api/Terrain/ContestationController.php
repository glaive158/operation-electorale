<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Declaration;
use App\Services\Logistique\AvisReceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contestation d'une réception par son signataire (« ce n'est pas moi »), depuis l'avis de la PWA. */
class ContestationController extends Controller
{
    public function __construct(private readonly AvisReceptionService $avis) {}

    public function store(Request $request, Declaration $declaration): JsonResponse
    {
        // Une réception qui n'est pas la sienne n'existe pas pour l'appelant.
        abort_unless($declaration->signataire_user_id !== null && (int) $declaration->signataire_user_id === (int) $request->user()->id, 404);

        $donnees = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        $this->avis->contester($declaration, $donnees['motif']);

        return response()->json(['declaration' => [
            'id' => $declaration->id,
            'statut' => $declaration->statut,
            'verification_identite' => $declaration->verification_identite,
            'contestation' => $declaration->contestation,
            'anomalies' => array_values($declaration->anomalies ?? []),
        ]]);
    }
}
