<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Materiel;
use App\Services\Logistique\Journal;
use App\Services\Logistique\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaterielController extends Controller
{
    public function index(Request $request)
    {
        $query = Materiel::query();
        if (! $request->boolean('inactifs')) {
            $query->actifs();
        }
        if ($request->filled('categorie')) {
            $query->where('categorie', $request->categorie);
        }
        $materiels = $query->orderBy('categorie')->orderBy('ordre')->orderBy('libelle')->get();

        return view('logistique.materiels.index', compact('materiels'));
    }

    public function create()
    {
        return view('logistique.materiels.create');
    }

    public function store(Request $request)
    {
        $donnees = $this->valider($request);
        $donnees['actif'] = true;

        DB::transaction(function () use ($donnees) {
            $materiel = Materiel::create($donnees);
            Journal::noter('materiel', $materiel->id, 'creation', null, $materiel->only($materiel->getFillable()));
        });

        return redirect()->route('logistique.materiels.index')->with('success', 'Article créé.');
    }

    public function edit(Materiel $materiel)
    {
        return view('logistique.materiels.edit', compact('materiel'));
    }

    public function update(Request $request, Materiel $materiel)
    {
        $donnees = $this->valider($request);

        try {
            DB::transaction(function () use ($request, $donnees, $materiel) {
                // Relu sous verrou : le même verrou protège les écritures de stock, qui ne peuvent
                // donc pas s'engager entre le contrôle de désactivation et la mise à jour.
                $actuel = Materiel::whereKey($materiel->id)->lockForUpdate()->firstOrFail();
                $donnees['actif'] = $request->boolean('actif', $actuel->actif);

                if ($actuel->actif && ! $donnees['actif'] && $this->estEncoreEngage($actuel)) {
                    throw new RegleLogistique('Article encore en stock ou engagé : désactivation impossible.');
                }

                $avant = $actuel->only($actuel->getFillable());
                $actuel->update($donnees);
                Journal::noter('materiel', $actuel->id, 'modification', $avant, $actuel->only($actuel->getFillable()));
            });
        } catch (RegleLogistique $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.materiels.index')->with('success', 'Article mis à jour.');
    }

    /**
     * Physique (bon ou à réparer), réservé, attribué ou commandé à recevoir non nul.
     */
    private function estEncoreEngage(Materiel $materiel): bool
    {
        $stock = app(StockService::class);

        return $stock->physique($materiel, 'bon') !== 0
            || $stock->physique($materiel, 'a_reparer') !== 0
            || $stock->reserve($materiel) !== 0
            || $stock->attribue($materiel) !== 0
            || $stock->commandeARecevoir($materiel) !== 0;
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'libelle'                 => 'required|string|max:200',
            'categorie'               => 'required|in:revision,materiel_lourd,imprime',
            'regroupement'            => 'nullable|string|max:100',
            'ordre'                   => 'nullable|integer|min:0|max:65535',
            'unite_stock'             => 'nullable|string|max:30',
            'unite_distribution'      => 'nullable|string|max:30',
            'facteur_conditionnement' => 'required_with:unite_distribution|nullable|integer|min:2|max:1000000',
        ]);

        $donnees['ordre'] = $donnees['ordre'] ?? 0;
        $donnees['unite_stock'] = $donnees['unite_stock'] ?? 'pièce';
        $donnees['indivisible'] = $request->boolean('indivisible', true);
        $donnees['reutilisable'] = $request->boolean('reutilisable', false);

        return $donnees;
    }
}
