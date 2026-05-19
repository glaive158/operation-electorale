<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CarteElectoraleController extends Controller
{
    private static function fe()
    {
        return DB::connection('recensement');
    }

    public function index(Request $request)
    {
        $searched   = false;
        $stats      = ['nb_electeurs' => 0, 'nb_bureaux' => 0, 'nb_communes' => 0, 'detail' => collect(), 'mode' => 'national'];
        $breadcrumb = [];

        $regionId  = $request->input('region_id');
        $deptId    = $request->input('dept_id');
        $arrId     = $request->input('arr_id');
        $communeId = $request->input('commune_id');

        $niveau  = (int) $request->input('niveau', 1);
        $selCol1 = $request->input('sel_col1');
        $selCol2 = $request->input('sel_col2');
        $selCol3 = $request->input('sel_col3');
        $selCol4 = $request->input('sel_col4');

        $regions = static::fe()->table('regions')->orderBy('nom')->get(['id', 'nom']);

        // Pre-load all geo data for client-side cascade filtering (~800 rows total)
        $geoCache = Cache::remember('ce_geo_all', 3600, function () {
            $fe = DB::connection('recensement');
            return [
                'departements'    => $fe->table('departements')->orderBy('nom')->get(['id', 'nom', 'region_id'])->all(),
                'arrondissements' => $fe->table('arrondissements')->orderBy('nom')->get(['id', 'nom', 'departement_id'])->all(),
                'communes'        => $fe->table('communes')->orderBy('nom')->get(['id', 'nom', 'arrondissement_id'])->all(),
            ];
        });
        $allDepts    = $geoCache['departements'];
        $allArrs     = $geoCache['arrondissements'];
        $allCommunes = $geoCache['communes'];

        $filtres = array_filter([
            'region_id'  => $regionId,
            'dept_id'    => $deptId,
            'arr_id'     => $arrId,
            'commune_id' => $communeId,
        ]);

        $isEtranger = $regionId === 'etranger';
        $isDrill    = !empty($selCol1);

        if (!empty($filtres) || $isDrill) {
            $searched = true;
            $cacheKey = 'ce_stats_' . md5(serialize(compact('filtres', 'niveau', 'selCol1', 'selCol2', 'selCol3', 'selCol4')));

            if ($isEtranger || ($isDrill && $request->input('mode') === 'etranger')) {

                $stats = Cache::remember($cacheKey, 1800, function () use ($deptId, $arrId, $niveau, $selCol1, $selCol2) {
                    $q = DB::connection('recensement')->table('fichier_electoral')->where('origine', 'etranger');

                    if ($niveau === 1) {
                        if ($deptId) $q->where('departement', $deptId);
                        if ($arrId)  $q->where('commune', $arrId);
                        $detail = $q->select(
                                'departement as col1', 'commune as col2',
                                DB::raw('COUNT(*) as nb_electeurs'),
                                DB::raw('COUNT(DISTINCT code_bureau) as nb_bureaux'))
                            ->groupBy('departement', 'commune')
                            ->orderBy('departement')->orderBy('commune')->get();
                        return [
                            'nb_electeurs' => $detail->sum('nb_electeurs'),
                            'nb_bureaux'   => $detail->sum('nb_bureaux'),
                            'nb_communes'  => $detail->count(),
                            'detail'       => $detail->map(fn($r) => (array)$r)->all(), 'mode' => 'etranger', 'niveau' => 1,
                            'col_labels'   => ['Pays', 'Ville'], 'drillable' => true,
                        ];
                    } else {
                        $q->where('departement', $selCol1)->where('commune', $selCol2);
                        $detail = $q->select(
                                'lieu_vote as col1',
                                DB::raw('COUNT(*) as nb_electeurs'),
                                DB::raw('COUNT(DISTINCT code_bureau) as nb_bureaux'))
                            ->groupBy('lieu_vote')->orderBy('lieu_vote')->get();
                        return [
                            'nb_electeurs' => $detail->sum('nb_electeurs'),
                            'nb_bureaux'   => $detail->sum('nb_bureaux'),
                            'nb_communes'  => $detail->count(),
                            'detail'       => $detail->map(fn($r) => (array)$r)->all(), 'mode' => 'etranger', 'niveau' => 2,
                            'col_labels'   => ['Lieu de vote'], 'drillable' => false,
                        ];
                    }
                });

            } else {

                $fe         = static::fe();
                $regionNom  = $regionId  ? optional($fe->table('regions')->find($regionId))->nom        : null;
                $deptNom    = $deptId    ? optional($fe->table('departements')->find($deptId))->nom      : null;
                $arrNom     = $arrId     ? optional($fe->table('arrondissements')->find($arrId))->nom    : null;
                $communeNom = $communeId ? optional($fe->table('communes')->find($communeId))->nom       : null;

                $stats = Cache::remember($cacheKey, 1800, function () use ($regionNom, $deptNom, $arrNom, $communeNom, $niveau, $selCol1, $selCol2, $selCol3, $selCol4) {
                    $fe = DB::connection('recensement');
                    $q  = $fe->table('fichier_electoral')->where('origine', 'national');

                    if ($niveau === 1) {
                        if ($regionNom)  $q->where('departement', $regionNom);
                        if ($deptNom)    $q->where('arrondissement', $deptNom);
                        if ($arrNom) {
                            $communesArr = $fe->table('communes')
                                ->join('arrondissements', 'communes.arrondissement_id', '=', 'arrondissements.id')
                                ->where('arrondissements.nom', $arrNom)->pluck('communes.nom');
                            $q->whereIn('commune', $communesArr);
                        }
                        if ($communeNom) $q->where('commune', $communeNom);

                        $detail = $q->select(
                                'departement as col1', 'arrondissement as col2',
                                DB::raw('COUNT(DISTINCT commune) as nb_communes'),
                                DB::raw('COUNT(*) as nb_electeurs'),
                                DB::raw('COUNT(DISTINCT code_bureau) as nb_bureaux'))
                            ->groupBy('departement', 'arrondissement')
                            ->orderBy('departement')->orderBy('arrondissement')->get();
                        return [
                            'nb_electeurs' => $detail->sum('nb_electeurs'),
                            'nb_bureaux'   => $detail->sum('nb_bureaux'),
                            'nb_communes'  => $detail->sum('nb_communes'),
                            'detail'       => $detail->map(fn($r) => (array)$r)->all(), 'mode' => 'national', 'niveau' => 1,
                            'col_labels'   => ['Région', 'Département'], 'drillable' => true,
                        ];
                    } elseif ($niveau === 2) {
                        $q->where('departement', $selCol1)->where('arrondissement', $selCol2);
                        $detail = $q->select(
                                'commune as col1',
                                DB::raw('COUNT(*) as nb_electeurs'),
                                DB::raw('COUNT(DISTINCT code_bureau) as nb_bureaux'))
                            ->groupBy('commune')->orderBy('commune')->get();
                        return [
                            'nb_electeurs' => $detail->sum('nb_electeurs'),
                            'nb_bureaux'   => $detail->sum('nb_bureaux'),
                            'nb_communes'  => $detail->count(),
                            'detail'       => $detail->map(fn($r) => (array)$r)->all(), 'mode' => 'national', 'niveau' => 2,
                            'col_labels'   => ['Commune'], 'drillable' => true,
                        ];
                    } elseif ($niveau === 3) {
                        $q->where('departement', $selCol1)->where('arrondissement', $selCol2)->where('commune', $selCol3);
                        $detail = $q->select(
                                'lieu_vote as col1',
                                DB::raw('COUNT(*) as nb_electeurs'),
                                DB::raw('COUNT(DISTINCT code_bureau) as nb_bureaux'))
                            ->groupBy('lieu_vote')->orderBy('lieu_vote')->get();
                        return [
                            'nb_electeurs' => $detail->sum('nb_electeurs'),
                            'nb_bureaux'   => $detail->sum('nb_bureaux'),
                            'nb_communes'  => $detail->count(),
                            'detail'       => $detail->map(fn($r) => (array)$r)->all(), 'mode' => 'national', 'niveau' => 3,
                            'col_labels'   => ['Lieu de vote'], 'drillable' => true,
                        ];
                    } else {
                        $q->where('departement', $selCol1)->where('arrondissement', $selCol2)->where('commune', $selCol3)->where('lieu_vote', $selCol4);
                        $detail = $q->select(
                                'code_bureau',
                                DB::raw('SUBSTRING(code_bureau, -2) as col1'),
                                DB::raw('COUNT(*) as nb_electeurs'))
                            ->groupBy('code_bureau')
                            ->orderBy('code_bureau')
                            ->get();
                        return [
                            'nb_electeurs' => $detail->sum('nb_electeurs'),
                            'nb_bureaux'   => $detail->count(),
                            'nb_communes'  => 0,
                            'detail'       => $detail->map(fn($r) => (array)$r)->all(), 'mode' => 'national', 'niveau' => 4,
                            'col_labels'   => ['Bureau'], 'drillable' => true,
                        ];
                    }
                });
            }

            $stats['detail'] = collect($stats['detail'] ?? [])->map(fn($r) => (object)$r);

            if ($isDrill) {
                $modeParam  = $isEtranger ? 'etranger' : 'national';
                $baseParams = http_build_query(array_filter(['region_id' => $regionId, 'dept_id' => $deptId, 'arr_id' => $arrId, 'commune_id' => $communeId, 'mode' => $modeParam]));

                if ($isEtranger) {
                    $breadcrumb[] = ['label' => 'Tous les pays', 'url' => route('carte-electorale') . '?' . $baseParams];
                    if ($niveau >= 2) $breadcrumb[] = ['label' => $selCol1 . ' — ' . $selCol2, 'url' => null];
                } else {
                    $breadcrumb[] = ['label' => 'Vue régions/départements', 'url' => route('carte-electorale') . '?' . $baseParams];
                    if ($niveau >= 2) $breadcrumb[] = ['label' => $selCol1 . ' / ' . $selCol2, 'url' => $niveau > 2 ? route('carte-electorale') . '?' . $baseParams . '&niveau=2&sel_col1=' . urlencode($selCol1) . '&sel_col2=' . urlencode($selCol2) : null];
                    if ($niveau >= 3) $breadcrumb[] = ['label' => $selCol3, 'url' => $niveau > 3 ? route('carte-electorale') . '?' . $baseParams . '&niveau=3&sel_col1=' . urlencode($selCol1) . '&sel_col2=' . urlencode($selCol2) . '&sel_col3=' . urlencode($selCol3) : null];
                    if ($niveau >= 4) $breadcrumb[] = ['label' => $selCol4, 'url' => null];
                }
            }
        }

        return view('fichier-electoral.carte', compact('searched', 'filtres', 'regions', 'stats', 'breadcrumb', 'allDepts', 'allArrs', 'allCommunes'));
    }

    public function apiPays()
    {
        $pays = Cache::remember('fe_api_pays_etranger', 3600, function () {
            return DB::connection('recensement')->table('fichier_electoral')
                ->where('origine', 'etranger')
                ->whereNotNull('departement')->distinct()->orderBy('departement')->pluck('departement');
        });
        return response()->json($pays->map(fn($p) => ['id' => $p, 'nom' => $p])->values());
    }

    public function apiVilles($pays)
    {
        $villes = DB::connection('recensement')->table('fichier_electoral')
            ->where('origine', 'etranger')
            ->where('departement', $pays)->whereNotNull('commune')
            ->distinct()->orderBy('commune')->pluck('commune');
        return response()->json($villes->map(fn($v) => ['id' => $v, 'nom' => $v])->values());
    }
}
