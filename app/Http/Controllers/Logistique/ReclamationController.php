<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Reclamation;
use App\Services\Logistique\ReclamationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Réclamations des autorités de terrain : consultation, prise en charge et résolution. */
class ReclamationController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly ReclamationService $reclamations) {}

    public function index(Request $request, Cycle $cycle)
    {
        $filtre = fn (string $nom, array $valides) => is_string($request->query($nom)) && in_array($request->query($nom), $valides, true)
            ? $request->query($nom) : null;
        $statut = $filtre('statut', array_keys(Reclamation::STATUTS));
        $motif = $filtre('motif', array_keys(Reclamation::MOTIFS));

        $reclamations = Reclamation::with(['decharge.destinataire', 'materiel'])
            ->whereIn('decharge_id', Decharge::where('cycle_id', $cycle->id)->select('id'))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($motif, fn ($q) => $q->where('motif', $motif))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('logistique.reclamations.index', [
            'cycle' => $cycle,
            'reclamations' => $reclamations,
            'statut' => $statut,
            'motif' => $motif,
        ]);
    }

    public function show(Reclamation $reclamation)
    {
        $reclamation->load(['decharge.cycle', 'decharge.destinataire', 'materiel', 'ouvertePar', 'traiteePar']);
        $besoin = BesoinRemplacement::where('reclamation_id', $reclamation->id)->first();

        return view('logistique.reclamations.show', [
            'reclamation' => $reclamation,
            'decharge' => $reclamation->decharge,
            'cycle' => $reclamation->decharge->cycle,
            'declarations' => Declaration::where('decharge_id', $reclamation->decharge_id)->orderBy('id')->get(),
            'besoin' => $besoin,
            'remplacements' => $besoin === null ? collect() : Decharge::where('besoin_remplacement_id', $besoin->id)->orderBy('id')->get(),
        ]);
    }

    public function prendre(Reclamation $reclamation)
    {
        return $this->executer(fn () => $this->reclamations->prendreEnCharge($reclamation), 'Réclamation prise en charge.');
    }

    public function resoudre(Request $request, Reclamation $reclamation)
    {
        $donnees = $request->validate([
            'issue' => ['required', Rule::in(array_keys(Reclamation::ISSUES))],
            'motif' => 'required|string|max:500',
            'quantite' => 'nullable|integer|min:0|max:10000000',
            'declaration_id' => 'nullable|integer|exists:declarations,id',
        ]);
        $declaration = isset($donnees['declaration_id']) ? Declaration::find($donnees['declaration_id']) : null;

        return $this->executer(
            fn () => $this->reclamations->resoudre(
                $reclamation, $donnees['issue'], $donnees['motif'],
                isset($donnees['quantite']) ? (int) $donnees['quantite'] : null, $declaration,
            ),
            $donnees['issue'] === 'remplacement_autorise'
                ? 'Remplacement autorisé : un brouillon de décharge de remplacement est préparé.'
                : 'Réclamation résolue.',
        );
    }
}
