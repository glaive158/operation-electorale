<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\CircuitDepartement;
use App\Models\Logistique\Cycle;
use App\Services\Logistique\CircuitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Circuit de livraison du cycle et dérogations par département. */
class CircuitController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly CircuitService $circuits)
    {
    }

    public function show(Cycle $cycle)
    {
        return view('logistique.circuit.show', [
            'cycle' => $cycle,
            'departements' => $this->circuits->departements($cycle),
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
}
