<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImpactDeplacementController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'overview');
        $search = trim($request->input('q', ''));
        $minSuivis = max(1, (int) $request->input('min_suivis', 1));

        $fe = DB::connection('recensement');

        // Stats globales pre-stockees en DB (table impact_stats) — instantane
        $rows = $fe->table('impact_stats')->get();
        $byKey = $rows->keyBy('cle');
        $stats = [
            'computed_at'      => optional($rows->first())->computed_at ?? 'jamais',
            'nb_bureaux'       => (int) ($byKey['nb_bureaux']->valeur ?? 0),
            'total_electeurs'  => (int) ($byKey['total_electeurs']->valeur ?? 0),
            'reste_commune'    => (int) ($byKey['reste_commune']->valeur ?? 0),
            'suivi_bureau'     => (int) ($byKey['suivi_bureau']->valeur ?? 0),
            'autre_commune'    => (int) ($byKey['autre_commune']->valeur ?? 0),
            'disparus'         => (int) ($byKey['disparus']->valeur ?? 0),
        ];

        $rows = collect();
        if ($type === 'suspects') {
            // Filter: only suspects whose old bureau has suivi_count >= threshold
            $bureauxMasse = $minSuivis > 1
                ? $fe->table('bureau_migration')->where('suivi_count', '>=', $minSuivis)->pluck('code_bureau')
                : null;

            $q = $fe->table('electeurs_suspects')
                ->select('numelec', 'nom', 'prenom', 'datenaiss', 'code_bureau',
                         'commune_old', 'lieu_old', 'commune_new', 'lieu_new', 'bureau_new');
            if ($bureauxMasse) {
                $q->whereIn('code_bureau', $bureauxMasse);
            }
            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('numelec', 'LIKE', "%{$search}%")
                       ->orWhere('nom', 'LIKE', "%{$search}%")
                       ->orWhere('prenom', 'LIKE', "%{$search}%")
                       ->orWhere('commune_old', 'LIKE', "%{$search}%")
                       ->orWhere('commune_new', 'LIKE', "%{$search}%");
                });
            }
            $rows = $q->orderBy('commune_old')->orderBy('code_bureau')
                ->paginate(50)->appends($request->all());
        } elseif ($type === 'bureaux') {
            $q = $fe->table('bureau_migration as bm')
                ->select(
                    'bm.code_bureau', 'bm.dept_old', 'bm.commune_old', 'bm.lieu_vote',
                    'bm.bureau', 'bm.dept_new', 'bm.commune_new',
                    'bm.effectif_old', 'bm.migration_count', 'bm.migration_pct',
                    'bm.suivi_count'
                )
                ->where('bm.suivi_count', '>=', $minSuivis)
                ->orderBy('bm.suivi_count', 'desc')->orderBy('bm.lieu_vote');
            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('bm.commune_old', 'LIKE', "%{$search}%")
                       ->orWhere('bm.commune_new', 'LIKE', "%{$search}%")
                       ->orWhere('bm.lieu_vote', 'LIKE', "%{$search}%");
                });
            }
            $rows = $q->paginate(50)->appends($request->all());
        }

        // Counts per threshold for UI selector
        $bureauxBySeuil = [];
        foreach ([1, 2, 3, 5, 10] as $s) {
            $bureauxBySeuil[$s] = (int) $fe->table('bureau_migration')->where('suivi_count', '>=', $s)->count();
        }

        return view('impact-deplacement.index', compact('stats', 'type', 'rows', 'search', 'minSuivis', 'bureauxBySeuil'));
    }
}
