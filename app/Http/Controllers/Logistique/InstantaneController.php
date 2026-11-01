<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\InstantaneEffectifs;
use App\Services\Logistique\InstantaneService;
use Illuminate\Http\Request;

class InstantaneController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly InstantaneService $instantanes)
    {
    }

    public function index(Cycle $cycle)
    {
        return view('logistique.instantanes.index', [
            'cycle' => $cycle,
            'instantanes' => InstantaneEffectifs::with('auteur')->where('cycle_id', $cycle->id)->orderByDesc('version')->get(),
        ]);
    }

    public function show(Request $request, InstantaneEffectifs $instantane)
    {
        $lignes = $instantane->lignes()->with('destinataire')
            ->when($request->boolean('non_rapprochees'), fn ($q) => $q->where('rapprochee', false))
            ->orderBy('contexte_region')->orderBy('contexte_departement')->orderBy('libelle_commune')
            ->orderBy('pays')->orderBy('ville')->orderBy('id')
            ->paginate(100)->withQueryString();

        return view('logistique.instantanes.show', [
            'instantane' => $instantane->load('auteur'),
            'cycle' => $instantane->cycle,
            'lignes' => $lignes,
        ]);
    }

    public function store(Cycle $cycle)
    {
        try {
            $instantane = $this->instantanes->demarrer($cycle);
        } catch (RegleLogistique $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.instantanes.show', $instantane)->with('success', 'Capture des effectifs lancée.');
    }

    public function figer(InstantaneEffectifs $instantane)
    {
        return $this->executer(fn () => $this->instantanes->figer($instantane), 'Instantané figé : il peut servir au calcul des dotations.');
    }
}
