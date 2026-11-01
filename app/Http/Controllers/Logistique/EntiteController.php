<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\EntiteBulletin;
use App\Services\Logistique\CleService;
use App\Services\Logistique\LectureRecensement;
use Illuminate\Http\Request;

/** Candidats, listes et options du cycle : chacun porte son bulletin de vote. */
class EntiteController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly CleService $cles,
        private readonly LectureRecensement $recensement,
    ) {
    }

    public function index(Cycle $cycle)
    {
        $communes = $this->recensement->communes()->sortBy('nom')->values();

        return view('logistique.entites.index', [
            'cycle' => $cycle,
            'entites' => EntiteBulletin::with(['materiel', 'perimetres'])->where('cycle_id', $cycle->id)->orderBy('ordre')->orderBy('nom')->get(),
            'departements' => $this->recensement->departements()->sortBy('nom')->values(),
            'communes' => $communes,
            'departementsParId' => $this->recensement->departements()->keyBy('id'),
            'communesParId' => $communes->keyBy('id'),
        ]);
    }

    public function store(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'type' => 'required|in:candidat,liste,option',
            'nom' => 'required|string|max:180',
            'ordre' => 'nullable|integer|min:0|max:65535',
            'departements' => 'nullable|array',
            'departements.*' => 'integer|min:1',
            'communes' => 'nullable|array',
            'communes.*' => 'integer|min:1',
            'pays' => 'nullable|string|max:2000',
        ]);

        $pays = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($donnees['pays'] ?? '')) ?: [])));

        return $this->executer(fn () => $this->cles->ajouterEntite($cycle, [
            'type' => $donnees['type'],
            'nom' => $donnees['nom'],
            'ordre' => (int) ($donnees['ordre'] ?? 0),
            'national' => $request->boolean('national'),
            'perimetre' => [
                'departements' => array_map('intval', $donnees['departements'] ?? []),
                'communes' => array_map('intval', $donnees['communes'] ?? []),
                'pays' => $pays,
            ],
        ]), 'Entité ajoutée avec son bulletin de vote.');
    }

    public function update(Request $request, Cycle $cycle, EntiteBulletin $entite)
    {
        abort_unless($entite->cycle_id === $cycle->id, 404);
        $donnees = $request->validate(['nom' => 'required|string|max:180']);

        return $this->executer(fn () => $this->cles->renommerEntite($entite, $donnees['nom']), 'Entité renommée.');
    }
}
