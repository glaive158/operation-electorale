<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistique\IndicateurController as IndicateurCentral;
use App\Models\Logistique\Cycle;
use App\Services\Logistique\IndicateurService;
use App\Services\Logistique\PerimetreTerrain;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Suivi du cycle limité aux destinataires du périmètre de l'utilisateur, en lecture seule. */
class IndicateurController extends Controller
{
    private const PAR_PAGE = 50;

    public function __construct(
        private readonly IndicateurService $indicateurs,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function index(Request $request)
    {
        $cycles = Cycle::orderByDesc('id')->get();
        // Le cycle demandé, sinon le dernier cycle ouvert, sinon le plus récent.
        $cycle = $cycles->firstWhere('id', $request->integer('cycle'))
            ?? $cycles->firstWhere('statut', 'ouvert')
            ?? $cycles->first();

        $lignes = collect();
        $articles = collect();
        if ($cycle !== null) {
            // Toujours une liste : un périmètre vide ne doit jamais valoir « tout le cycle ».
            $ids = $this->perimetre->destinataireIds($request->user())->all();
            $lignes = $this->indicateurs->parDestinataire($cycle, $ids)->values();
            $articles = $this->indicateurs->parArticle($cycle, $ids);
        }

        $page = max(1, $request->integer('page', 1));
        $lignes = new LengthAwarePaginator(
            $lignes->forPage($page, self::PAR_PAGE)->values(), $lignes->count(), self::PAR_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('terrain.suivi', [
            'cycle' => $cycle,
            'cycles' => $cycles,
            'articles' => $articles,
            'lignes' => $lignes,
            'etats' => IndicateurCentral::ETATS,
        ]);
    }
}
