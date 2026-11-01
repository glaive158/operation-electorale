<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\CorrespondanceGeo;
use App\Services\Logistique\CorrespondanceService;
use App\Services\Logistique\LectureRecensement;
use Illuminate\Http\Request;

/** Rapprochement des communes du fichier électoral avec celles du recensement. */
class CorrespondanceController extends Controller
{
    use ExecuteAction;

    private const STATUTS = ['proposee', 'validee', 'rejetee'];

    public function __construct(
        private readonly CorrespondanceService $correspondances,
        private readonly LectureRecensement $recensement,
    ) {
    }

    public function index(Request $request)
    {
        $statut = $request->query('statut', 'proposee');
        $statut = in_array($statut, self::STATUTS, true) ? $statut : null;

        $liste = CorrespondanceGeo::with('validePar')
            ->when($statut !== null, fn ($q) => $q->where('statut', $statut))
            ->orderBy('contexte_region')->orderBy('contexte_departement')->orderBy('libelle_commune')
            ->paginate(50)->withQueryString();

        $communes = $this->recensement->communes()->sortBy('nom')->values();

        return view('logistique.correspondances.index', [
            'correspondances' => $liste,
            'statut' => $statut,
            'communes' => $communes,
            'communesParId' => $communes->keyBy('id'),
            'compteurs' => CorrespondanceGeo::selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut'),
        ]);
    }

    public function rapprocher()
    {
        return $this->executer(
            fn () => $this->correspondances->rapprocher(),
            fn (array $r) => "{$r['creees']} correspondance(s) créée(s) : {$r['auto']} validée(s) automatiquement, {$r['a_valider']} à valider.",
        );
    }

    public function valider(Request $request, CorrespondanceGeo $correspondance)
    {
        $donnees = $request->validate(['commune_id' => 'required|integer|min:1']);

        return $this->executer(
            fn () => $this->correspondances->valider($correspondance, (int) $donnees['commune_id']),
            'Correspondance validée.',
        );
    }

    public function rejeter(Request $request, CorrespondanceGeo $correspondance)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(
            fn () => $this->correspondances->rejeter($correspondance, $donnees['motif']),
            'Correspondance rejetée.',
        );
    }
}
