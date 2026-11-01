<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Services\Logistique\Journal;
use App\Services\Logistique\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CycleController extends Controller
{
    use ExecuteAction;

    private const CYCLE_CLOS = 'Cycle clôturé : modification impossible.';

    public function index()
    {
        $cycles = Cycle::orderByDesc('date_scrutin')->orderByDesc('id')->get();

        return view('logistique.cycles.index', compact('cycles'));
    }

    public function create()
    {
        return view('logistique.cycles.create');
    }

    public function store(Request $request)
    {
        $donnees = $this->valider($request);

        DB::transaction(function () use ($donnees) {
            $cycle = Cycle::create($donnees);
            Journal::noter('cycle', $cycle->id, 'creation', null, $cycle->only($cycle->getFillable()));
        });

        return redirect()->route('logistique.cycles.index')->with('success', 'Cycle créé.');
    }

    public function edit(Cycle $cycle)
    {
        if (! $cycle->estOuvert()) {
            return redirect()->route('logistique.cycles.index')->with('error', self::CYCLE_CLOS);
        }

        return view('logistique.cycles.edit', compact('cycle'));
    }

    public function update(Request $request, Cycle $cycle)
    {
        if (! $cycle->estOuvert()) {
            return redirect()->route('logistique.cycles.index')->with('error', self::CYCLE_CLOS);
        }

        $donnees = $this->valider($request);

        $modifie = DB::transaction(function () use ($cycle, $donnees) {
            // Relu sous verrou : une clôture concurrente (verrou exclusif) a pu passer depuis le chargement.
            $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if (! $actuel->estOuvert()) {
                return false;
            }

            $avant = $actuel->only($actuel->getFillable());
            $actuel->update($donnees);
            Journal::noter('cycle', $actuel->id, 'modification', $avant, $actuel->only($actuel->getFillable()));

            return true;
        });

        if (! $modifie) {
            return redirect()->route('logistique.cycles.index')->with('error', self::CYCLE_CLOS);
        }

        return redirect()->route('logistique.cycles.index')->with('success', 'Cycle mis à jour.');
    }

    public function cloturer(Request $request, Cycle $cycle, StockService $stock)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $stock->cloturerCycle($cycle, $donnees['motif']),
            'Cycle clôturé.',
        );
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'libelle'               => 'required|string|max:150',
            'type'                  => 'required|in:revision,scrutin',
            'date_scrutin'          => 'nullable|date',
            'majoration_distribuee' => 'required|numeric|between:0,100',
            'reserve_centrale'      => 'required|numeric|between:0,100',
            'circuit_defaut'        => 'required|in:via_prefet,direct',
        ]);
    }
}
