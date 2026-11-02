<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Services\Logistique\PreuveService;
use Illuminate\Http\JsonResponse;
use LogicException;

class ClePreuveController extends Controller
{
    /** Clé publique de chiffrement du bloc de preuve : la tablette la met en cache et vérifie son empreinte. */
    public function show(PreuveService $preuve): JsonResponse
    {
        try {
            $pem = $preuve->clePublique();
        } catch (LogicException) {
            return response()->json(['message' => 'Clé de preuve non configurée sur le serveur.'], 503);
        }

        return response()->json(['pem' => $pem, 'empreinte' => $preuve->empreinteClePublique($pem)]);
    }
}
