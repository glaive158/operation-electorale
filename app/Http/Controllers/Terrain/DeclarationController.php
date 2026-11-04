<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\ExecuteAction;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Remise;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\PerimetreTerrain;
use Illuminate\Http\Request;

/** Déclaration de réception de l'autorité destinataire, depuis son compte (mode « compte »). */
class DeclarationController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly DeclarationService $declarations,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function store(Request $request, Remise $remise)
    {
        $decharge = Decharge::findOrFail($remise->decharge_id);
        abort_unless($this->perimetre->peutVoir($request->user(), $decharge), 404);

        $donnees = $request->validate([
            'cle' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'signataire_nom' => 'required|string|max:150',
            'signataire_qualite' => 'required|string|max:150',
            'lignes' => 'required|array|min:1',
            'lignes.*.recu' => 'required|integer|min:0|max:10000000',
            'lignes.*.defectueux' => 'required|integer|min:0|max:10000000',
            'lignes.*.observation' => 'nullable|string|max:500',
        ]);

        $lignes = [];
        foreach ($donnees['lignes'] as $materielId => $ligne) {
            $lignes[(int) $materielId] = [
                'recu' => (int) $ligne['recu'],
                'defectueux' => (int) $ligne['defectueux'],
                'observation' => $ligne['observation'] ?? null,
            ];
        }

        return $this->executerVers(
            fn () => $this->declarations->declarer(
                $remise, 'compte', ['nom' => $donnees['signataire_nom'], 'qualite' => $donnees['signataire_qualite']],
                $lignes, $donnees['cle'], null,
            ),
            fn () => route('terrain.decharges.show', $decharge),
            'Réception déclarée.',
        );
    }
}
