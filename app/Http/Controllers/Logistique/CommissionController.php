<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\Cycle;
use App\Services\Logistique\CommissionService;
use App\Services\Logistique\LectureRecensement;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly CommissionService $commissions,
        private readonly LectureRecensement $recensement,
    ) {
    }

    public function index(Cycle $cycle)
    {
        $communes = $this->recensement->communes()->sortBy('nom')->values();

        return view('logistique.commissions.index', [
            'cycle' => $cycle,
            'commissions' => CommissionAdministrative::where('cycle_id', $cycle->id)
                ->orderBy('type')->orderBy('commune_id')->orderBy('pays')->orderBy('ville')->orderBy('id')
                ->paginate(100),
            'communes' => $communes,
            'communesParId' => $communes->keyBy('id'),
        ]);
    }

    public function store(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'type' => 'required|in:fixe,itinerante,etranger',
            'commune_id' => 'required_unless:type,etranger|nullable|integer|min:1',
            'pays' => 'required_if:type,etranger|nullable|string|max:100',
            'ville' => 'required_if:type,etranger|nullable|string|max:100',
            'libelle' => 'nullable|string|max:200',
        ]);
        if ($donnees['type'] === 'etranger') {
            $donnees['commune_id'] = null;
        } else {
            $donnees['pays'] = $donnees['ville'] = null;
        }

        return $this->executer(fn () => $this->commissions->creer($cycle, $donnees), 'Commission créée.');
    }

    public function fixes(Cycle $cycle)
    {
        return $this->executer(
            fn () => CommissionAdministrative::creerFixesParCommune($cycle),
            fn (int $n) => "{$n} commission(s) fixe(s) créée(s).",
        );
    }

    public function destroy(Request $request, Cycle $cycle, CommissionAdministrative $commission)
    {
        abort_unless($commission->cycle_id === $cycle->id, 404);
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(fn () => $this->commissions->supprimer($commission, $donnees['motif']), 'Commission supprimée.');
    }
}
