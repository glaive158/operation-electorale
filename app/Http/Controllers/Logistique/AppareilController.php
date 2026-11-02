<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Services\Logistique\AppareilService;
use App\Services\Logistique\LibellesRemises;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

        $prefectures = Destinataire::where('type', 'prefecture')->orderBy('libelle')->get();

        return view('logistique.appareils.index', [
            'prefectures' => $prefectures,
            // Les jetons de tablette ne sont montrés qu'à ceux qui désignent.
            'connexions' => Gate::allows('logistique-administrer') ? $this->appareils->connexionsParPrefecture($prefectures) : collect(),
            'appareils' => Appareil::with('jeton.tokenable')->orderByDesc('id')->get()->groupBy('destinataire_id'),
            'remises' => $remises,
            'parties' => $this->libelles->pour($remises),
        ]);
    }

    public function designer(Request $request, Destinataire $destinataire)
    {
        $donnees = $request->validate([
            'identifiant' => 'required|uuid',
            'nom' => 'required|string|max:100',
            'jeton_id' => 'nullable|integer|min:1',
        ]);

        return $this->executer(
            fn () => $this->appareils->designer($destinataire, $donnees['identifiant'], $donnees['nom'], isset($donnees['jeton_id']) ? (int) $donnees['jeton_id'] : null),
            'Appareil désigné : il peut préparer les redistributions hors ligne. L\'appareil précédent, le cas échéant, est révoqué.',
        );
    }

    /** Depuis la fiche centrale d'une décharge : libère la réserve de la tablette (responsable, avec un motif). */
    public function libererReserve(Request $request, Decharge $decharge)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $this->appareils->libererReserveDecharge($decharge, $donnees['motif']),
            'Réserve de la tablette libérée : la quantité redevient mobilisable par le préfet.',
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
