<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Materiel;
use App\Services\Logistique\Journal;
use Illuminate\Http\Request;

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
        $materiel = Materiel::create($donnees);
        Journal::noter('materiel', $materiel->id, 'creation', null, $materiel->only($materiel->getFillable()));

        return redirect()->route('logistique.materiels.index')->with('success', 'Article créé.');
    }

    public function edit(Materiel $materiel)
    {
        return view('logistique.materiels.edit', compact('materiel'));
    }

    public function update(Request $request, Materiel $materiel)
    {
        $donnees = $this->valider($request);
        $donnees['actif'] = $request->boolean('actif', $materiel->actif);
        $avant = $materiel->only($materiel->getFillable());
        $materiel->update($donnees);
        Journal::noter('materiel', $materiel->id, 'modification', $avant, $materiel->only($materiel->getFillable()));

        return redirect()->route('logistique.materiels.index')->with('success', 'Article mis à jour.');
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'libelle'                 => 'required|string|max:200',
            'categorie'               => 'required|in:revision,materiel_lourd,imprime',
            'regroupement'            => 'nullable|string|max:100',
            'ordre'                   => 'nullable|integer|min:0',
            'unite_stock'             => 'nullable|string|max:30',
            'unite_distribution'      => 'nullable|string|max:30',
            'facteur_conditionnement' => 'required_with:unite_distribution|nullable|integer|min:2',
        ]);

        $donnees['ordre'] = $donnees['ordre'] ?? 0;
        $donnees['unite_stock'] = $donnees['unite_stock'] ?? 'pièce';
        $donnees['indivisible'] = $request->boolean('indivisible', true);
        $donnees['reutilisable'] = $request->boolean('reutilisable', false);

        return $donnees;
    }
}
