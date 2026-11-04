<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Services\Logistique\CircuitService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\LectureRecensement;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Ce qui reste à expédier, par destinataire et catégorie, avec la préparation des décharges. */
class ExpeditionController extends Controller
{
    private const PAR_PAGE = 50;

    public function __construct(
        private readonly EngagementService $engagements,
        private readonly DechargeService $decharges,
        private readonly CircuitService $circuits,
        private readonly LectureRecensement $recensement,
    ) {
    }

    public function index(Request $request, Cycle $cycle)
    {
        $categorie = is_string($request->query('categorie')) && array_key_exists($request->query('categorie'), Decharge::CATEGORIES)
            ? $request->query('categorie') : null;
        $departement = $request->integer('departement');

        $destinataires = ($departement > 0
            ? $this->decharges->destinatairesDuPerimetre(['departement_id' => $departement])
            : Destinataire::actifs()->get()
        )->keyBy('id');

        $ordre = array_flip(array_keys(Decharge::CATEGORIES));
        $lignes = $this->engagements->synthese($cycle, $categorie)
            ->filter(fn (array $l) => $destinataires->has($l['destinataire_id']))
            ->when($request->boolean('reste'), fn ($c) => $c->filter(fn (array $l) => $l['reste'] > 0))
            ->map(fn (array $l) => $l + ['destinataire' => $destinataires[$l['destinataire_id']]])
            ->sort(fn (array $a, array $b) => [$a['destinataire']->libelle, $ordre[$a['categorie']] ?? 99] <=> [$b['destinataire']->libelle, $ordre[$b['categorie']] ?? 99])
            ->values();

        $page = max(1, $request->integer('page', 1));
        $lignes = new LengthAwarePaginator(
            $lignes->forPage($page, self::PAR_PAGE)->values(), $lignes->count(), self::PAR_PAGE, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('logistique.expeditions.index', [
            'cycle' => $cycle,
            'lignes' => $lignes,
            'brouillons' => Decharge::where('cycle_id', $cycle->id)->where('statut_document', 'brouillon')
                ->get(['id', 'destinataire_id', 'categorie'])
                ->mapWithKeys(fn (Decharge $d) => [$d->destinataire_id.'|'.$d->categorie => $d->id]),
            'categorie' => $categorie,
            'departement' => $departement,
            'departements' => $this->circuits->departements($cycle),
            'regions' => $this->recensement->departements()->unique('region_id')->sortBy('region_nom')->values(),
        ]);
    }
}
