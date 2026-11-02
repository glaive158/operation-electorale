<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\ExecuteAction;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Remise;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\RemiseService;
use Illuminate\Http\Request;

/** Le préfet remet une part de sa réception au sous-préfet, ou annule une redistribution non déclarée. */
class RedistributionController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly RemiseService $remises,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function store(Request $request, Decharge $decharge)
    {
        abort_unless($this->perimetre->peutVoir($request->user(), $decharge), 404);

        $donnees = $request->validate([
            'lignes' => 'required|array|min:1',
            'lignes.*' => 'nullable|integer|min:0|max:10000000',
        ]);
        // Une case laissée vide vaut zéro.
        $lignes = collect($donnees['lignes'])->filter(fn ($quantite) => $quantite !== null && $quantite !== '')
            ->map(fn ($quantite) => (int) $quantite)->all();

        return $this->executerVers(
            fn () => $this->remises->preparerRedistribution($decharge, $lignes),
            fn () => route('terrain.decharges.show', $decharge),
            'Redistribution préparée : le sous-préfet peut déclarer sa réception.',
        );
    }

    public function annuler(Request $request, Remise $remise)
    {
        $decharge = Decharge::findOrFail($remise->decharge_id);
        abort_unless($this->perimetre->peutVoir($request->user(), $decharge), 404);

        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executerVers(
            fn () => $this->remises->annulerRedistribution($remise, $donnees['motif']),
            fn () => route('terrain.decharges.show', $decharge),
            'Redistribution annulée : le reliquat est libéré.',
        );
    }
}
