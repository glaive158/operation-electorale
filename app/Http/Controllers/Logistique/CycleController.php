<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use Illuminate\Http\Request;

class CycleController extends Controller
{
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
        Cycle::create($this->valider($request));

        return redirect()->route('logistique.cycles.index')->with('success', 'Cycle créé.');
    }

    public function edit(Cycle $cycle)
    {
        return view('logistique.cycles.edit', compact('cycle'));
    }

    public function update(Request $request, Cycle $cycle)
    {
        $cycle->update($this->valider($request));

        return redirect()->route('logistique.cycles.index')->with('success', 'Cycle mis à jour.');
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
