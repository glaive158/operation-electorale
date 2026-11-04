<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Services\Logistique\CircuitService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\IndicateurService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Suivi du cycle : indicateurs par article, puis par destinataire. */
class IndicateurController extends Controller
{
    private const PAR_PAGE = 50;

    public const ETATS = [
        'en_cours' => 'En cours',
        'solde_complet' => 'Soldé',
        'cloture_avec_ecart' => 'Clôturé avec écart',
    ];

    public function __construct(
        private readonly IndicateurService $indicateurs,
        private readonly DechargeService $decharges,
        private readonly CircuitService $circuits,
    ) {}

    public function index(Request $request, Cycle $cycle)
    {
        $etat = is_string($request->query('etat')) && array_key_exists($request->query('etat'), self::ETATS) ? $request->query('etat') : null;
        $departement = $request->integer('departement');

        // Une sous-préfecture suit le département de sa préfecture.
        $ids = $departement > 0
            ? $this->decharges->destinatairesDuPerimetre(['departement_id' => $departement], true)->modelKeys()
            : null;

        $lignes = $this->indicateurs->parDestinataire($cycle, $ids)
            ->when($etat, fn ($c) => $c->where('etat', $etat))
            ->values();

        $page = max(1, $request->integer('page', 1));
        $lignes = new LengthAwarePaginator(
            $lignes->forPage($page, self::PAR_PAGE)->values(), $lignes->count(), self::PAR_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('logistique.suivi.index', [
            'cycle' => $cycle,
            'articles' => $this->indicateurs->parArticle($cycle),
            'lignes' => $lignes,
            'etat' => $etat,
            'etats' => self::ETATS,
            'departement' => $departement,
            'departements' => $this->circuits->departements($cycle),
        ]);
    }
}
