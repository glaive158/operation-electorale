<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Services\Logistique\PlanDotationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class PlanDotationController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly PlanDotationService $plans)
    {
    }

    public function index(Cycle $cycle)
    {
        return view('logistique.plans.index', [
            'cycle' => $cycle,
            'plans' => PlanDotation::with(['instantane', 'calculePar'])->where('cycle_id', $cycle->id)->orderByDesc('version')->get(),
            'instantanes' => InstantaneEffectifs::where('cycle_id', $cycle->id)->where('statut', 'fige')->orderByDesc('version')->get(),
        ]);
    }

    public function store(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'instantane_id' => ['required', 'integer', Rule::exists('instantanes_effectifs', 'id')->where('cycle_id', $cycle->id)],
        ]);

        try {
            $plan = $this->plans->calculer($cycle, InstantaneEffectifs::findOrFail($donnees['instantane_id']));
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.plans.show', $plan)->with('success', "Plan v{$plan->version} calculé en brouillon.");
    }

    public function show(Request $request, PlanDotation $plan)
    {
        $lignes = PlanDotationLigne::with(['destinataire', 'materiel'])->where('plan_dotation_id', $plan->id)
            ->when($request->filled('destinataire'), fn ($q) => $q->where('destinataire_id', (int) $request->query('destinataire')))
            ->when($request->filled('materiel'), fn ($q) => $q->where('materiel_id', (int) $request->query('materiel')))
            ->when($request->boolean('anomalies'), fn ($q) => $q->whereNotNull('anomalie'))
            ->orderBy('destinataire_id')->orderBy('materiel_id')
            ->paginate(100)->withQueryString();

        $anomaliesLignes = PlanDotationLigne::where('plan_dotation_id', $plan->id)->whereNotNull('anomalie')->count();
        $idsDestinataires = PlanDotationLigne::where('plan_dotation_id', $plan->id)->distinct()->pluck('destinataire_id');
        $idsMateriels = PlanDotationLigne::where('plan_dotation_id', $plan->id)->distinct()->pluck('materiel_id');

        return view('logistique.plans.show', [
            'plan' => $plan->load(['instantane', 'calculePar', 'approuvePar']),
            'cycle' => $plan->cycle,
            'lignes' => $lignes,
            'nbAnomalies' => count($plan->anomalies ?? []) + $anomaliesLignes,
            'destinataires' => Destinataire::whereIn('id', $idsDestinataires)->orderBy('libelle')->get(['id', 'libelle']),
            'materiels' => Materiel::whereIn('id', $idsMateriels)->orderBy('ordre')->orderBy('libelle')->get(['id', 'libelle']),
        ]);
    }

    public function corriger(Request $request, PlanDotationLigne $ligne)
    {
        $donnees = $request->validate([
            'valeur' => 'required|integer|min:0|max:10000000',
            'motif' => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->plans->corriger($ligne, (int) $donnees['valeur'], $donnees['motif']),
            'Correction enregistrée.',
        );
    }

    public function approuver(PlanDotation $plan)
    {
        return $this->executer(fn () => $this->plans->approuver($plan), 'Plan approuvé : il devient le plan en vigueur.');
    }
}
