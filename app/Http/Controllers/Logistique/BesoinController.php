<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\PlanDotation;
use App\Services\Logistique\BesoinService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BesoinController extends Controller
{
    public function __construct(private readonly BesoinService $besoin)
    {
    }

    public function show(Cycle $cycle)
    {
        return view('logistique.besoin.show', [
            'cycle' => $cycle,
            'plan' => PlanDotation::courantApprouve($cycle),
            'tableau' => $this->besoin->tableau($cycle),
        ]);
    }

    public function commander(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'reference' => 'required|string|max:50',
            'fournisseur' => 'required|string|max:200',
            'date_commande' => 'required|date_format:Y-m-d',
            'quantites' => 'required|array',
            'quantites.*' => 'nullable|integer|min:0|max:10000000',
            'motif' => 'nullable|string|max:500',
        ]);

        try {
            $commande = $this->besoin->convertirEnCommande($cycle, [
                'reference' => $donnees['reference'],
                'fournisseur' => $donnees['fournisseur'],
                'date_commande' => $donnees['date_commande'],
            ], array_filter($donnees['quantites'], fn ($q) => $q !== null), $donnees['motif'] ?? null);
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.commandes.show', $commande)->with('success', 'Proposition convertie en commande brouillon.');
    }
}
