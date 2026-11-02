<?php

namespace App\Http\Controllers\Terrain;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Services\Logistique\CodeReceptionService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Choix du code personnel de réception (et, au premier accès, du nouveau mot de passe) par l'autorité de terrain. */
class CodeController extends Controller
{
    /** Champs qui ne reviennent jamais dans le formulaire, ni en session. */
    private const SECRETS = ['password', 'nouveau_mot_de_passe', 'nouveau_mot_de_passe_confirmation', 'code', 'code_confirmation'];

    public function __construct(private readonly CodeReceptionService $codes) {}

    public function edit(Request $request)
    {
        $this->exigerAutorite($request);

        return view('terrain.code', [
            'premierAcces' => (bool) $request->user()->doit_choisir_code,
            'aUnCode' => $request->user()->code_reception_hash !== null,
        ]);
    }

    public function update(Request $request)
    {
        $this->exigerAutorite($request);
        $utilisateur = $request->user();
        $premierAcces = (bool) $utilisateur->doit_choisir_code;

        $donnees = $request->validate([
            'password' => 'required|string',
            'nouveau_mot_de_passe' => [$premierAcces ? 'required' : 'nullable', 'string', 'min:10', 'max:200', 'confirmed'],
            'code' => ['required', 'digits:6', 'confirmed'],
        ], [
            'password.required' => 'Saisissez votre mot de passe actuel.',
            'nouveau_mot_de_passe.required' => 'Choisissez un nouveau mot de passe.',
            'nouveau_mot_de_passe.min' => 'Le nouveau mot de passe doit comporter au moins 10 caractères.',
            'nouveau_mot_de_passe.max' => 'Le nouveau mot de passe est trop long.',
            'nouveau_mot_de_passe.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'code.required' => 'Choisissez un code à 6 chiffres.',
            'code.digits' => 'Le code doit comporter exactement 6 chiffres.',
            'code.confirmed' => 'La confirmation du code ne correspond pas.',
        ]);

        try {
            $this->codes->choisir($utilisateur, $donnees['code'], $donnees['password'], $donnees['nouveau_mot_de_passe'] ?? null);
        } catch (RegleLogistique|InvalidArgumentException $e) {
            // Rien de ce qui a été saisi n'est remis dans le formulaire ni conservé en session.
            return back()->withInput($request->except(self::SECRETS))->with('error', $e->getMessage());
        }

        return redirect()->route('terrain.decharges.index')->with('success', 'Votre code de réception est enregistré.');
    }

    /** Les agents de transport ne signent pas de réception : pas de code pour eux. */
    private function exigerAutorite(Request $request): void
    {
        abort_unless($request->user()->hasRole(CodeReceptionService::ROLES_AUTORITE), 403);
    }
}
