<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Decharge;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\RemiseService;
use Illuminate\Http\Request;

/** Affectation de l'agent et chargement d'une décharge validée. */
class RemiseController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly DechargeService $decharges,
        private readonly RemiseService $remises,
    ) {}

    public function affecter(Request $request, Decharge $decharge)
    {
        $donnees = $request->validate([
            'agent_id' => 'nullable|integer|exists:users,id',
            'motif' => 'required|string|max:500',
        ]);
        $agent = isset($donnees['agent_id']) ? User::find($donnees['agent_id']) : null;

        return $this->executer(
            fn () => $this->decharges->affecterAgent($decharge, $agent, $donnees['motif']),
            $agent === null ? 'Affectation de l\'agent retirée.' : 'Agent affecté.',
        );
    }

    public function charger(Request $request, Decharge $decharge)
    {
        $donnees = $request->validate([
            'transporteur_nom' => 'nullable|string|max:150',
            'transporteur_qualite' => 'nullable|string|max:150',
            'transporteur_societe' => 'nullable|string|max:150',
            'transporteur_bordereau' => 'nullable|string|max:50',
        ]);
        $transporteur = array_filter([
            'nom' => $donnees['transporteur_nom'] ?? null,
            'qualite' => $donnees['transporteur_qualite'] ?? null,
            'societe' => $donnees['transporteur_societe'] ?? null,
            'bordereau' => $donnees['transporteur_bordereau'] ?? null,
        ], fn ($valeur) => $valeur !== null);

        return $this->executerVers(
            fn () => $this->remises->charger($decharge, $transporteur),
            fn () => route('logistique.decharges.show', $decharge),
            'Décharge chargée : le matériel est en transit.',
        );
    }
}
