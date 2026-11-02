<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Services\Logistique\PieceJointeService;
use Symfony\Component\HttpFoundation\Response;

/** Signatures et photos de réception vues depuis le poste central. */
class PieceController extends Controller
{
    public function __construct(private readonly PieceJointeService $pieces) {}

    public function show(string $empreinte): Response
    {
        $piece = $this->pieces->trouver($empreinte);
        abort_if($piece === null, 404);

        return $this->pieces->reponse($piece);
    }
}
