<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Services\Logistique\PieceJointeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Signatures et photos vues depuis le terrain : seulement celles des décharges de son périmètre. */
class PieceController extends Controller
{
    public function __construct(private readonly PieceJointeService $pieces) {}

    public function show(Request $request, string $empreinte): Response
    {
        $piece = $this->pieces->visiblePar($request->user(), $empreinte);
        abort_if($piece === null, 404);

        return $this->pieces->reponse($piece);
    }
}
