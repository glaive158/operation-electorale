<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\DechargePdfService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/** Arbitrage des déclarations à vérifier, levée des contestations et clôture d'un écart, réservés au responsable logistique. */
class ArbitrageController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly ArbitrageService $arbitrages,
        private readonly DechargePdfService $pdf,
    ) {}

    public function retenir(Request $request, Declaration $declaration)
    {
        $motif = $request->validate(['motif' => 'required|string|max:500'])['motif'];

        return $this->arbitrer(fn () => $this->arbitrages->retenir($declaration, $motif), $declaration, 'Déclaration retenue.');
    }

    public function rejeter(Request $request, Declaration $declaration)
    {
        $motif = $request->validate(['motif' => 'required|string|max:500'])['motif'];

        return $this->arbitrer(fn () => $this->arbitrages->rejeter($declaration, $motif), $declaration, 'Déclaration rejetée.');
    }

    /** Lève la contestation d'un signataire, une fois la vérification faite auprès de lui. */
    public function leverContestation(Request $request, Declaration $declaration)
    {
        $motif = $request->validate(['motif' => 'required|string|max:500'])['motif'];

        return $this->executerVers(
            fn () => $this->arbitrages->leverContestation($declaration, $motif),
            fn () => route('logistique.decharges.show', $declaration->decharge_id),
            'Contestation levée.',
        );
    }

    public function cloturer(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'destinataire_id' => 'required|integer|exists:destinataires,id',
            'materiel_id' => 'required|integer|exists:materiels,id',
            'abandon' => 'nullable|integer|min:0|max:10000000',
            'expedition_annulee' => 'nullable|integer|min:0|max:10000000',
            'motif' => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->arbitrages->cloturerAvecEcart(
                $cycle, Destinataire::findOrFail($donnees['destinataire_id']), Materiel::findOrFail($donnees['materiel_id']),
                (int) ($donnees['abandon'] ?? 0), (int) ($donnees['expedition_annulee'] ?? 0), $donnees['motif'],
            ),
            'Écart accepté : la clôture est enregistrée.',
        );
    }

    /**
     * Arbitre, puis émet l'exemplaire de réception si la décharge est désormais reçue par son destinataire :
     * l'arbitrage fait foi, un échec d'émission n'y change rien et se refait depuis la fiche de la décharge.
     */
    private function arbitrer(callable $action, Declaration $declaration, string $succes)
    {
        try {
            $action();
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $retour = back()->with('success', $succes);
        $decharge = Decharge::find($declaration->decharge_id);
        // Un état non émissible (réception partielle, redistribution en cours) est attendu : on n'émet pas, sans avertir.
        if ($decharge === null || ! $this->pdf->receptionEmissible($decharge)) {
            return $retour;
        }

        try {
            $this->pdf->emettre($decharge, 'reception');
        } catch (Throwable $e) {
            if (! $e instanceof RegleLogistique) {
                report($e);
            }

            return $retour->with('warning', 'L\'exemplaire de réception n\'a pas pu être émis : l\'arbitrage est enregistré, utilisez « Émettre le PDF » depuis la fiche de la décharge.');
        }

        return $retour;
    }
}
