<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Services\Logistique\AppareilService;
use App\Services\Logistique\LibellesRemises;
use Illuminate\Http\Request;

/**
 * Appareil désigné de chaque préfecture (celui qui prépare les redistributions hors ligne) et redistributions
 * synchronisées en attente d'arbitrage du responsable.
 */
class AppareilController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly AppareilService $appareils,
        private readonly LibellesRemises $libelles,
    ) {}

    public function index()
    {
        $remises = Remise::where('etape', 'redistribution')->where('statut', 'a_arbitrer')
            ->with(['decharge.destinataire', 'lignes.materiel', 'appareil', 'preparePar'])->orderBy('id')->get();

        return view('logistique.appareils.index', [
            'prefectures' => Destinataire::where('type', 'prefecture')->orderBy('libelle')->get(),
            'appareils' => Appareil::orderByDesc('id')->get()->groupBy('destinataire_id'),
            'remises' => $remises,
            'parties' => $this->libelles->pour($remises),
        ]);
    }

    public function designer(Request $request, Destinataire $destinataire)
    {
        $donnees = $request->validate([
            'identifiant' => 'required|uuid',
            'nom' => 'required|string|max:100',
        ]);

        return $this->executer(
            fn () => $this->appareils->designer($destinataire, $donnees['identifiant'], $donnees['nom']),
            'Appareil désigné : il peut préparer les redistributions hors ligne. L\'appareil précédent, le cas échéant, est révoqué.',
        );
    }

    public function revoquer(Request $request, Appareil $appareil)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $this->appareils->revoquer($appareil, $donnees['motif']),
            'Appareil révoqué : sa réserve est libérée et ses redistributions seront à arbitrer.',
        );
    }
}
