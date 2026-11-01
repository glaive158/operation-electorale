<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\PosteEtranger;
use App\Services\Logistique\DecoupageService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PosteController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly DecoupageService $decoupage)
    {
    }

    public function index()
    {
        $postes = PosteEtranger::orderBy('pays')->orderBy('ville')->orderBy('nom')->paginate(100);

        return view('logistique.postes.index', compact('postes'));
    }

    public function create()
    {
        return view('logistique.postes.create');
    }

    public function store(Request $request)
    {
        $donnees = $this->valider($request);

        try {
            $this->decoupage->creerPoste($donnees);
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.postes.index')
            ->with('success', 'Poste créé. Lancer la génération des destinataires pour le rendre affectable.');
    }

    public function edit(PosteEtranger $poste)
    {
        return view('logistique.postes.edit', compact('poste'));
    }

    public function update(Request $request, PosteEtranger $poste)
    {
        $donnees = $this->valider($request);
        $donnees['actif'] = $request->boolean('actif');

        try {
            $this->decoupage->modifierPoste($poste, $donnees);
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.postes.index')->with('success', 'Poste mis à jour.');
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'pays' => 'required|string|max:100',
            'ville' => 'required|string|max:100',
            'type' => 'required|in:consulat,ambassade',
            'nom' => 'required|string|max:200',
        ]);
    }
}
