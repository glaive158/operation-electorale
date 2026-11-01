<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Services\Logistique\CleService;
use Illuminate\Http\Request;

/** Clés de répartition : sur quoi chaque article se calcule, à quel ratio. */
class CleController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly CleService $cles)
    {
    }

    public function index(Cycle $cycle)
    {
        return view('logistique.cles.index', [
            'cycle' => $cycle,
            'materiels' => Materiel::actifs()->orderBy('categorie')->orderBy('ordre')->orderBy('libelle')->get(),
            'cles' => CleRepartition::with('cycle')->where('cycle_id', $cycle->id)->get()->keyBy('materiel_id'),
            'autresCycles' => Cycle::where('id', '!=', $cycle->id)->orderByDesc('id')->get(),
        ]);
    }

    public function update(Request $request, Cycle $cycle, Materiel $materiel)
    {
        $donnees = $request->validate([
            'base' => 'required|in:'.implode(',', CleRepartition::BASES),
            'ratio' => 'required|numeric|gt:0|max:100000',
            'majoration_distribuee' => 'nullable|numeric|min:0|max:100',
            'reserve_centrale' => 'nullable|numeric|min:0|max:100',
            'minimum' => 'nullable|integer|min:0|max:10000000',
            'arrondi' => 'required|in:'.implode(',', CleRepartition::ARRONDIS),
            'cle_affichee' => 'nullable|string|max:150',
        ]);

        // Ratio et pourcentages restent des chaînes : jamais de flottant. Une majoration ou une réserve
        // laissée vide revient à la valeur du cycle.
        return $this->executer(fn () => $this->cles->enregistrer($cycle, $materiel, [
            'base' => $donnees['base'],
            'ratio' => (string) $request->input('ratio'),
            'majoration_distribuee' => $donnees['majoration_distribuee'] === null ? null : (string) $request->input('majoration_distribuee'),
            'reserve_centrale' => $donnees['reserve_centrale'] === null ? null : (string) $request->input('reserve_centrale'),
            'minimum' => (int) ($donnees['minimum'] ?? 0),
            'arrondi' => $donnees['arrondi'],
            'cle_affichee' => $donnees['cle_affichee'] ?? null,
            'par_entite' => $request->boolean('par_entite'),
            'minimum_par_commune' => $request->boolean('minimum_par_commune'),
            'arrondi_conditionnement' => $request->boolean('arrondi_conditionnement'),
        ]), 'Clé enregistrée.');
    }

    public function copier(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate(['source_id' => 'required|integer|exists:cycles_electoraux,id']);

        return $this->executer(
            fn () => $this->cles->copier(Cycle::findOrFail($donnees['source_id']), $cycle),
            fn (int $n) => "{$n} clé(s) copiée(s).",
        );
    }
}
