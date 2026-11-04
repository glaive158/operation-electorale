<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Models\Logistique\PieceJointe;
use App\Services\Logistique\PieceJointeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Envoi des signatures et photos par la tablette : reprenable, dédoublonné par l'empreinte du contenu. */
class PieceController extends Controller
{
    public function __construct(private readonly PieceJointeService $pieces) {}

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fichier' => ['required', 'file'],
            'empreinte' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);

        ['piece' => $piece, 'creee' => $creee] = $this->pieces->enregistrer($request->file('fichier'), $donnees['empreinte']);

        return response()->json(['piece' => $this->representation($piece)], $creee ? 201 : 200);
    }

    /** 200 si l'appelant a envoyé (ou prouvé posséder) la pièce, 404 sinon : la tablette ne renvoie que ce qui manque. */
    public function show(Request $request, string $empreinte): Response
    {
        abort_unless($this->pieces->possede($request->user(), $empreinte), 404);

        return response('', 200);
    }

    /** @return array{empreinte: string, taille: int, type_mime: string} */
    private function representation(PieceJointe $piece): array
    {
        return ['empreinte' => $piece->empreinte, 'taille' => $piece->taille, 'type_mime' => $piece->type_mime];
    }
}
