<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\ExecuteAction;
use App\Models\Logistique\Declaration;
use App\Services\Logistique\AvisReceptionService;
use Illuminate\Http\Request;

/** « Ce n'est pas moi » : le signataire d'une réception la conteste depuis son compte. */
class ContestationController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly AvisReceptionService $avis) {}

    public function store(Request $request, Declaration $declaration)
    {
        // Une réception qui n'est pas la sienne n'existe pas pour l'appelant.
        abort_unless($declaration->signataire_user_id !== null && (int) $declaration->signataire_user_id === (int) $request->user()->id, 404);

        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $this->avis->contester($declaration, $donnees['motif']),
            'Réception contestée : le service logistique va la vérifier.',
        );
    }
}
