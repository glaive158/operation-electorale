<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Services\Logistique\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly StockService $stock)
    {
    }

    public function index(Request $request)
    {
        $materiels = Materiel::actifs()
            ->when($request->filled('categorie'), fn ($q) => $q->where('categorie', $request->categorie))
            ->orderBy('categorie')->orderBy('ordre')->orderBy('libelle')
            ->get();

        $lignes = $materiels->map(fn (Materiel $m) => [
            'materiel'           => $m,
            'physique'           => $this->stock->physique($m),
            'reserve'            => $this->stock->reserve($m),
            'attribue'           => $this->stock->attribue($m),
            'libre_non_attribue' => $this->stock->libreNonAttribue($m),
            'a_reparer'          => $this->stock->physique($m, 'a_reparer'),
            'commande'           => $this->stock->commandeARecevoir($m),
        ]);

        $actifs = Materiel::actifs()->orderBy('categorie')->orderBy('ordre')->orderBy('libelle')->get();

        return view('logistique.stock.index', compact('lignes', 'actifs'));
    }

    public function mouvements(Materiel $materiel)
    {
        $mouvements = MouvementStock::where('materiel_id', $materiel->id)
            ->with('materiel')
            ->orderByDesc('id')
            ->paginate(50);

        $compenses = MouvementStock::where('materiel_id', $materiel->id)
            ->whereNotNull('mouvement_compense_id')
            ->pluck('mouvement_compense_id')
            ->all();

        $cycles = Cycle::ouverts()->orderByDesc('id')->get()->map(fn (Cycle $c) => [
            'cycle'    => $c,
            'attribue' => $this->stock->attribue($materiel, $c),
            'reserve'  => $this->stock->reserve($materiel, $c),
        ]);

        $indicateurs = [
            'physique'           => $this->stock->physique($materiel),
            'reserve'            => $this->stock->reserve($materiel),
            'attribue'           => $this->stock->attribue($materiel),
            'libre_non_attribue' => $this->stock->libreNonAttribue($materiel),
            'a_reparer'          => $this->stock->physique($materiel, 'a_reparer'),
        ];

        return view('logistique.stock.mouvements', compact('materiel', 'mouvements', 'compenses', 'cycles', 'indicateurs'));
    }

    public function inventaire(Request $request)
    {
        $donnees = $request->validate([
            'materiel_id' => ['required', 'integer', Rule::exists('materiels', 'id')->where('actif', true)],
            'quantite'    => 'required|integer|min:1|max:100000000',
            'motif'       => 'required|string|max:500',
        ]);
        $materiel = Materiel::findOrFail($donnees['materiel_id']);

        return $this->executer(
            fn () => $this->stock->inventaireInitial($materiel, (int) $donnees['quantite'], $donnees['motif']),
            'Inventaire initial enregistré.',
        );
    }

    public function compenser(Request $request, MouvementStock $mouvement)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $this->stock->compenser($mouvement, $donnees['motif']),
            'Mouvement compensé.',
        );
    }

    public function attribuer(Request $request, Cycle $cycle, Materiel $materiel)
    {
        $donnees = $request->validate([
            'quantite' => 'required|integer|min:0|max:100000000',
            'motif'    => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->stock->attribuer($cycle, $materiel, (int) $donnees['quantite'], $donnees['motif']),
            'Attribution enregistrée.',
        );
    }
}
