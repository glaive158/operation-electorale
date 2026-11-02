<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\ExecuteAction;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Reclamation;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\ReclamationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Réclamation d'une autorité ou d'un agent sur une décharge partie de son périmètre. */
class ReclamationController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly ReclamationService $reclamations,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function store(Request $request, Decharge $decharge)
    {
        abort_unless($this->perimetre->peutVoir($request->user(), $decharge), 404);

        $donnees = $request->validate([
            'materiel_id' => 'required|integer|exists:materiels,id',
            'motif' => ['required', Rule::in(array_keys(Reclamation::MOTIFS))],
            'quantite' => 'required|integer|min:1|max:10000000',
            'message' => 'required|string|max:1000',
        ]);

        return $this->executerVers(
            fn () => $this->reclamations->ouvrir(
                $decharge, Materiel::findOrFail($donnees['materiel_id']), $donnees['motif'], (int) $donnees['quantite'], $donnees['message'],
            ),
            fn () => route('terrain.decharges.show', $decharge),
            'Réclamation enregistrée : le service logistique va la traiter.',
        );
    }
}
