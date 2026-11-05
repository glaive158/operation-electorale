<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\CircuitDepartement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Services\Logistique\CircuitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Circuit de livraison du cycle, dérogations par département et réglages par sous-préfecture. */
class CircuitController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly CircuitService $circuits) {}

    public function show(Cycle $cycle)
    {
        return view('logistique.circuit.show', [
            'cycle' => $cycle,
            'departements' => $this->circuits->departements($cycle),
            // Sans préfecture parente ou sans département : regroupées sous la clé 0.
            'sousPrefectures' => $this->circuits->sousPrefectures($cycle)->groupBy(fn (array $l) => $l['departement_id'] ?? 0),
        ]);
    }

    public function update(Request $request, Cycle $cycle, int $departement)
    {
        $donnees = $request->validate([
            'circuit' => ['nullable', Rule::in(CircuitDepartement::CIRCUITS)],
            'motif' => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->circuits->definir($cycle, $departement, $donnees['circuit'] ?? null, $donnees['motif']),
            'Circuit du département enregistré.',
        );
    }

    public function sousPrefecture(Request $request, Cycle $cycle, Destinataire $destinataire)
    {
        $donnees = $request->validate([
            'circuit' => ['nullable', Rule::in(CircuitDepartement::CIRCUITS)],
            'motif' => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->circuits->definirSousPrefecture($cycle, $destinataire, $donnees['circuit'] ?? null, $donnees['motif']),
            'Circuit de la sous-préfecture enregistré.',
        );
    }
}
