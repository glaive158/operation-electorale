<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use Illuminate\Http\Request;

/** Suivi des codes personnels de réception des autorités et réinitialisation par le responsable logistique. */
class CodeReceptionController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly CodeReceptionService $codes) {}

    public function index()
    {
        return view('logistique.codes.index', [
            'comptes' => User::whereIn('role', CodeReceptionService::ROLES_AUTORITE)->orderBy('name')->orderBy('prenom')->get(),
        ]);
    }

    public function reinitialiser(Request $request, User $user)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $this->codes->reinitialiser($user, $donnees['motif']),
            'Code réinitialisé : l\'autorité devra en choisir un nouveau à sa prochaine connexion.',
        );
    }
}
