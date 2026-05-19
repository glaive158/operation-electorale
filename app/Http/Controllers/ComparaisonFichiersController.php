<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ComparaisonFichiersController extends Controller
{
    public function index(Request $request)
    {
        $regionId  = $request->input('region_id');
        $deptId    = $request->input('dept_id');
        $arrId     = $request->input('arr_id');
        $communeId = $request->input('commune_id');

        $fe = DB::connection('recensement');

        $regionNom  = $regionId  ? optional($fe->table('regions')->find($regionId))->nom        : null;
        $deptNom    = $deptId    ? optional($fe->table('departements')->find($deptId))->nom     : null;
        $arrNom     = $arrId     ? optional($fe->table('arrondissements')->find($arrId))->nom   : null;
        $communeNom = $communeId ? optional($fe->table('communes')->find($communeId))->nom      : null;

        // Determine grouping level (= one step deeper than the deepest filter)
        if ($communeNom) {
            $level = 'lieu_vote';
            $levelLabel = 'Lieu de vote';
        } elseif ($arrNom || $deptNom) {
            $level = 'commune';
            $levelLabel = 'Commune';
        } elseif ($regionNom) {
            $level = 'departement';
            $levelLabel = 'Département';
        } else {
            $level = 'region';
            $levelLabel = 'Région';
        }

        // ===== ANCIEN (bureau_stats) =====
        // bureau_stats: departement=REGION, arrondissement=DEPT, commune, lieu_vote
        $qOld = $fe->table('bureau_stats');
        if ($regionNom) $qOld->where('departement', $regionNom);
        if ($deptNom)   $qOld->where('arrondissement', $deptNom);
        if ($arrId) {
            $communesArr = $fe->table('communes')->where('arrondissement_id', $arrId)->pluck('nom');
            $qOld->whereIn('commune', $communesArr);
        }
        if ($communeNom) $qOld->where('commune', $communeNom);

        $oldGroupCol = match ($level) {
            'region'      => 'departement',     // bureau_stats.departement = region label
            'departement' => 'arrondissement',  // bureau_stats.arrondissement = dept label
            'commune'     => 'commune',
            'lieu_vote'   => 'lieu_vote',
        };
        $rowsOld = $qOld->select(
            $oldGroupCol.' as label',
            DB::raw('COUNT(DISTINCT lieu_vote) as nb_lieux'),
            DB::raw('COUNT(*) as nb_bureaux'),
            DB::raw('SUM(effectif) as nb_electeurs')
        )->groupBy($oldGroupCol)->orderBy($oldGroupCol)->get();

        // ===== NOUVEAU (csv_bureau_stats) =====
        $qNew = $fe->table('csv_bureau_stats');
        if ($regionNom) $qNew->where('region', $regionNom);
        if ($deptNom)   $qNew->where('departement', $deptNom);
        if ($arrId) {
            $communesArr = $fe->table('communes')->where('arrondissement_id', $arrId)->pluck('nom');
            $qNew->whereIn('commune', $communesArr);
        }
        if ($communeNom) $qNew->where('commune', $communeNom);

        $newGroupCol = match ($level) {
            'region'      => 'region',
            'departement' => 'departement',
            'commune'     => 'commune',
            'lieu_vote'   => 'lieu_vote',
        };
        $rowsNew = $qNew->select(
            $newGroupCol.' as label',
            DB::raw('COUNT(DISTINCT lieu_vote) as nb_lieux'),
            DB::raw('COUNT(*) as nb_bureaux'),
            DB::raw('SUM(effectif) as nb_electeurs')
        )->groupBy($newGroupCol)->orderBy($newGroupCol)->get();

        // ===== DIFFÉRENCE (full outer-like join by label) =====
        $oldByLabel = $rowsOld->keyBy(fn ($r) => mb_strtoupper(trim($r->label ?? '')));
        $newByLabel = $rowsNew->keyBy(fn ($r) => mb_strtoupper(trim($r->label ?? '')));
        $allLabels  = $oldByLabel->keys()->merge($newByLabel->keys())->unique()->sort()->values();

        $diffRows = $allLabels->map(function ($label) use ($oldByLabel, $newByLabel) {
            $o = $oldByLabel->get($label);
            $n = $newByLabel->get($label);
            $oldL = (int) ($o->nb_lieux ?? 0);
            $oldB = (int) ($o->nb_bureaux ?? 0);
            $oldE = (int) ($o->nb_electeurs ?? 0);
            $newL = (int) ($n->nb_lieux ?? 0);
            $newB = (int) ($n->nb_bureaux ?? 0);
            $newE = (int) ($n->nb_electeurs ?? 0);
            return (object) [
                'label'           => $o->label ?? $n->label ?? $label,
                'old_lieux'       => $oldL,  'new_lieux'    => $newL,  'diff_lieux'    => $newL - $oldL,
                'old_bureaux'     => $oldB,  'new_bureaux'  => $newB,  'diff_bureaux'  => $newB - $oldB,
                'old_electeurs'   => $oldE,  'new_electeurs'=> $newE,  'diff_electeurs'=> $newE - $oldE,
                'status'          => !$o ? 'nouveau' : (!$n ? 'supprime' : 'modifie'),
            ];
        });

        $totals = [
            'old' => [
                'lieux'     => (int) $rowsOld->sum('nb_lieux'),
                'bureaux'   => (int) $rowsOld->sum('nb_bureaux'),
                'electeurs' => (int) $rowsOld->sum('nb_electeurs'),
            ],
            'new' => [
                'lieux'     => (int) $rowsNew->sum('nb_lieux'),
                'bureaux'   => (int) $rowsNew->sum('nb_bureaux'),
                'electeurs' => (int) $rowsNew->sum('nb_electeurs'),
            ],
        ];
        $totals['diff'] = [
            'lieux'     => $totals['new']['lieux']     - $totals['old']['lieux'],
            'bureaux'   => $totals['new']['bureaux']   - $totals['old']['bureaux'],
            'electeurs' => $totals['new']['electeurs'] - $totals['old']['electeurs'],
        ];

        // Cascade geo data (cached 1h) — store as plain arrays, not stdClass
        $geo = Cache::remember('cf_geo_all_v2', 3600, function () use ($fe) {
            return [
                'regions'         => $fe->table('regions')->orderBy('nom')->get(['id','nom'])->map(fn($r) => (array) $r)->all(),
                'departements'    => $fe->table('departements')->orderBy('nom')->get(['id','nom','region_id'])->map(fn($r) => (array) $r)->all(),
                'arrondissements' => $fe->table('arrondissements')->orderBy('nom')->get(['id','nom','departement_id'])->map(fn($r) => (array) $r)->all(),
                'communes'        => $fe->table('communes')->orderBy('nom')->get(['id','nom','arrondissement_id'])->map(fn($r) => (array) $r)->all(),
            ];
        });
        // Convert back to objects for view consistency (->id / ->nom syntax)
        $geo = array_map(fn ($arr) => array_map(fn ($a) => (object) $a, $arr), $geo);

        $filtres = [
            'region_id'  => $regionId,
            'dept_id'    => $deptId,
            'arr_id'     => $arrId,
            'commune_id' => $communeId,
            'region_nom' => $regionNom,
            'dept_nom'   => $deptNom,
            'arr_nom'    => $arrNom,
            'commune_nom'=> $communeNom,
        ];

        return view('comparaison-fichiers.index', [
            'rowsOld'    => $rowsOld,
            'rowsNew'    => $rowsNew,
            'diffRows'   => $diffRows,
            'totals'     => $totals,
            'levelLabel' => $levelLabel,
            'filtres'    => $filtres,
            'regions'    => $geo['regions'],
            'allDepts'   => $geo['departements'],
            'allArrs'    => $geo['arrondissements'],
            'allCommunes'=> $geo['communes'],
        ]);
    }
}
