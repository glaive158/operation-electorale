<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComparaisonLieuxController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'nouveaux');
        $search = trim($request->input('q', ''));

        $fe = DB::connection('recensement');

        $stats = [
            'nouveaux' => $this->queryNouveaux($fe)->count(),
            'supprimes' => $this->querySupprimes($fe)->count(),
            'deplaces' => $this->queryDeplaces($fe)->count(),
            'bureaux_nouveaux' => $this->statBureauxNouveaux($fe),
            'bureaux_supprimes' => $this->statBureauxSupprimes($fe),
            'bureaux_deplaces' => $this->statBureauxDeplaces($fe),
        ];

        if ($type === 'supprimes') {
            $q = $this->querySupprimes($fe);
            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('bs.arrondissement', 'LIKE', "%{$search}%")
                       ->orWhere('bs.commune', 'LIKE', "%{$search}%")
                       ->orWhere('bs.lieu_vote', 'LIKE', "%{$search}%");
                });
            }
            $rows = $q->orderBy('bs.arrondissement')->orderBy('bs.commune')->orderBy('bs.lieu_vote')->paginate(50)->appends($request->all());
        } elseif ($type === 'deplaces') {
            $rows = $this->getDeplacesPaginated($fe, $search, $request);
        } elseif ($type === 'bureaux-nouveaux') {
            $rows = $this->getBureauxNouveauxPaginated($fe, $search, $request);
        } elseif ($type === 'bureaux-supprimes') {
            $rows = $this->getBureauxSupprimesPaginated($fe, $search, $request);
        } elseif ($type === 'bureaux-deplaces') {
            $rows = $this->getBureauxDeplacesPaginated($fe, $search, $request);
        } else {
            $type = 'nouveaux';
            $q = $this->queryNouveaux($fe);
            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('t.departement', 'LIKE', "%{$search}%")
                       ->orWhere('t.commune', 'LIKE', "%{$search}%")
                       ->orWhere('t.lieu_vote', 'LIKE', "%{$search}%");
                });
            }
            $rows = $q->orderBy('t.departement')->orderBy('t.commune')->orderBy('t.lieu_vote')->paginate(50)->appends($request->all());
        }

        // Stats lieux déplacés (pour verdict + filtre seuil)
        $lieuxStats = ['by_seuil' => [], 'strict_count' => 0];
        if ($type === 'deplaces') {
            foreach ([1, 2, 3, 5, 10] as $s) {
                $lieuxStats['by_seuil'][$s] = (int) $fe->table('lieu_migration')
                    ->where('suivi_count', '>=', $s)
                    ->distinct()->count(DB::raw('lieu_vote'));
            }
            $lieuxStats['strict_count'] = (int) $fe->table('lieu_migration')
                ->where('suivi_count', '>=', 3)
                ->whereRaw('UPPER(TRIM(dept_old)) = UPPER(TRIM(dept_new))')
                ->whereRaw('ABS(effectif_old - effectif_new) / GREATEST(effectif_old, 1) < 0.20')
                ->count();
        }
        $minSuivis = max(0, (int) $request->input('min_suivis', 0));

        return view('comparaison-lieux.index', compact('rows', 'stats', 'type', 'search', 'lieuxStats', 'minSuivis'));
    }

    // ============ BUREAU COMPARISONS ============

    private function statBureauxNouveaux($fe): int
    {
        return (int) $fe->select('
            SELECT COUNT(*) as nb FROM csv_bureau_stats c
            LEFT JOIN bureau_stats bs
                ON UPPER(TRIM(bs.commune)) = UPPER(TRIM(c.commune))
               AND UPPER(TRIM(bs.lieu_vote)) = UPPER(TRIM(c.lieu_vote))
               AND SUBSTRING(bs.code_bureau, -2) = LPAD(c.bureau, 2, "0")
            WHERE bs.code_bureau IS NULL
        ')[0]->nb;
    }

    private function statBureauxSupprimes($fe): int
    {
        return (int) $fe->select('
            SELECT COUNT(*) as nb FROM bureau_stats bs
            LEFT JOIN csv_bureau_stats c
                ON UPPER(TRIM(bs.commune)) = UPPER(TRIM(c.commune))
               AND UPPER(TRIM(bs.lieu_vote)) = UPPER(TRIM(c.lieu_vote))
               AND SUBSTRING(bs.code_bureau, -2) = LPAD(c.bureau, 2, "0")
            WHERE c.bureau IS NULL
        ')[0]->nb;
    }

    private function statBureauxDeplaces($fe): int
    {
        // Use pre-computed table if exists, fallback to live query
        try {
            return (int) $fe->table('bureau_migration')->count();
        } catch (\Throwable $e) {
            return (int) $fe->select('
                SELECT COUNT(*) as nb FROM bureau_stats bs
                JOIN csv_bureau_stats c
                    ON UPPER(TRIM(bs.lieu_vote)) = UPPER(TRIM(c.lieu_vote))
                   AND SUBSTRING(bs.code_bureau, -2) = LPAD(c.bureau, 2, "0")
                WHERE UPPER(TRIM(bs.commune)) != UPPER(TRIM(c.commune))
            ')[0]->nb;
        }
    }

    private function getBureauxNouveauxPaginated($fe, $search, $request)
    {
        $q = $fe->table('csv_bureau_stats as c')
            ->leftJoin('bureau_stats as bs', function ($j) {
                $j->on(DB::raw('UPPER(TRIM(bs.commune))'), '=', DB::raw('UPPER(TRIM(c.commune))'))
                  ->on(DB::raw('UPPER(TRIM(bs.lieu_vote))'), '=', DB::raw('UPPER(TRIM(c.lieu_vote))'))
                  ->on(DB::raw('SUBSTRING(bs.code_bureau, -2)'), '=', DB::raw('LPAD(c.bureau, 2, "0")'));
            })
            ->whereNull('bs.code_bureau')
            ->select('c.departement', 'c.commune', 'c.lieu_vote', 'c.bureau', 'c.effectif');
        if ($search) {
            $q->where(function ($qq) use ($search) {
                $qq->where('c.departement', 'LIKE', "%{$search}%")
                   ->orWhere('c.commune', 'LIKE', "%{$search}%")
                   ->orWhere('c.lieu_vote', 'LIKE', "%{$search}%")
                   ->orWhere('c.bureau', 'LIKE', "%{$search}%");
            });
        }
        return $q->orderBy('c.departement')->orderBy('c.commune')->orderBy('c.lieu_vote')->orderBy('c.bureau')
            ->paginate(50)->appends($request->all());
    }

    private function getBureauxSupprimesPaginated($fe, $search, $request)
    {
        $q = $fe->table('bureau_stats as bs')
            ->leftJoin('csv_bureau_stats as c', function ($j) {
                $j->on(DB::raw('UPPER(TRIM(bs.commune))'), '=', DB::raw('UPPER(TRIM(c.commune))'))
                  ->on(DB::raw('UPPER(TRIM(bs.lieu_vote))'), '=', DB::raw('UPPER(TRIM(c.lieu_vote))'))
                  ->on(DB::raw('SUBSTRING(bs.code_bureau, -2)'), '=', DB::raw('LPAD(c.bureau, 2, "0")'));
            })
            ->whereNull('c.bureau')
            ->select('bs.code_bureau', 'bs.arrondissement as departement', 'bs.commune', 'bs.lieu_vote',
                     DB::raw('SUBSTRING(bs.code_bureau, -2) as bureau'), 'bs.effectif');
        if ($search) {
            $q->where(function ($qq) use ($search) {
                $qq->where('bs.arrondissement', 'LIKE', "%{$search}%")
                   ->orWhere('bs.commune', 'LIKE', "%{$search}%")
                   ->orWhere('bs.lieu_vote', 'LIKE', "%{$search}%");
            });
        }
        return $q->orderBy('bs.arrondissement')->orderBy('bs.commune')->orderBy('bs.lieu_vote')->orderBy('bs.code_bureau')
            ->paginate(50)->appends($request->all());
    }

    private function getBureauxDeplacesPaginated($fe, $search, $request)
    {
        // Pre-computed in `bureau_migration` table (built via build-bureau-migration.php)
        $q = $fe->table('bureau_migration');
        if ($search) {
            $q->where(function ($qq) use ($search) {
                $qq->where('commune_old', 'LIKE', "%{$search}%")
                   ->orWhere('commune_new', 'LIKE', "%{$search}%")
                   ->orWhere('lieu_vote', 'LIKE', "%{$search}%")
                   ->orWhere('dept_old', 'LIKE', "%{$search}%")
                   ->orWhere('dept_new', 'LIKE', "%{$search}%");
            });
        }
        $paginator = $q->orderBy('lieu_vote')->orderBy('code_bureau')
            ->paginate(50)->appends($request->all());

        // Map countOld for view compatibility (ratio = migration_count / effectif_old)
        $items = collect($paginator->items())->map(function ($item) {
            $item->countOld = $item->effectif_old;
            return $item;
        });

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items, $paginator->total(), $paginator->perPage(), $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private function queryNouveaux($fe)
    {
        return $fe->table('tmp_csv_lieux as t')
            ->leftJoin('bureau_stats as bs', function ($join) {
                $join->on(DB::raw('UPPER(TRIM(bs.commune))'), '=', DB::raw('UPPER(TRIM(t.commune))'))
                    ->on(DB::raw('UPPER(TRIM(bs.lieu_vote))'), '=', DB::raw('UPPER(TRIM(t.lieu_vote))'));
            })
            ->whereNull('bs.commune')
            ->select('t.departement', 't.commune', 't.lieu_vote');
    }

    private function querySupprimes($fe)
    {
        return $fe->table('bureau_stats as bs')
            ->leftJoin('tmp_csv_lieux as t', function ($join) {
                $join->on(DB::raw('UPPER(TRIM(bs.commune))'), '=', DB::raw('UPPER(TRIM(t.commune))'))
                    ->on(DB::raw('UPPER(TRIM(bs.lieu_vote))'), '=', DB::raw('UPPER(TRIM(t.lieu_vote))'));
            })
            ->whereNull('t.commune')
            ->select('bs.arrondissement as departement', 'bs.commune', 'bs.lieu_vote', DB::raw('SUM(bs.effectif) as effectif'))
            ->groupBy('bs.arrondissement', 'bs.commune', 'bs.lieu_vote');
    }

    private function queryDeplaces($fe)
    {
        // Lieux uniques en ancien (1 commune), apparaissant dans 1+ commune ≠ en nouveau
        return $fe->table(DB::raw('(
            SELECT lieu_vote,
                   MIN(UPPER(TRIM(commune))) as commune_old,
                   MIN(UPPER(TRIM(arrondissement))) as departement_old
            FROM bureau_stats
            GROUP BY lieu_vote
            HAVING COUNT(DISTINCT UPPER(TRIM(commune))) = 1
        ) old'))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tmp_csv_lieux as t')
                    ->whereColumn('t.lieu_vote', 'old.lieu_vote')
                    ->whereRaw('UPPER(TRIM(t.commune)) != old.commune_old');
            })
            ->select('old.lieu_vote', 'old.commune_old', 'old.departement_old');
    }

    private function getDeplacesPaginated($fe, $search, $request)
    {
        $minSuivis = max(0, (int) $request->input('min_suivis', 0));

        $q = $this->queryDeplaces($fe);
        if ($search) {
            $q->where(function ($qq) use ($search) {
                $qq->where('old.lieu_vote', 'LIKE', "%{$search}%")
                   ->orWhere('old.commune_old', 'LIKE', "%{$search}%")
                   ->orWhere('old.departement_old', 'LIKE', "%{$search}%");
            });
        }

        // Filter by suivi threshold via lieu_migration
        if ($minSuivis > 0) {
            $lieuxAvecSuivi = $fe->table('lieu_migration')
                ->where('suivi_count', '>=', $minSuivis)
                ->pluck('lieu_vote')->unique()->all();
            if (empty($lieuxAvecSuivi)) {
                $q->whereRaw('1 = 0'); // no result
            } else {
                $q->whereIn('old.lieu_vote', $lieuxAvecSuivi);
            }
        }

        $paginator = $q->orderBy('old.lieu_vote')->paginate(50)->appends($request->all());

        $lieux = collect($paginator->items())->pluck('lieu_vote')->all();

        // Couples (dept, commune) en nouveau
        $newByLieu = [];
        if (!empty($lieux)) {
            foreach ($fe->table('tmp_csv_lieux')->whereIn('lieu_vote', $lieux)
                ->select('lieu_vote', 'departement', 'commune')->distinct()->get() as $r) {
                $newByLieu[$r->lieu_vote][] = $r->departement . ' / ' . $r->commune;
            }
        }

        // Max suivi_count par lieu (depuis lieu_migration)
        $suiviByLieu = [];
        if (!empty($lieux)) {
            foreach ($fe->table('lieu_migration')->whereIn('lieu_vote', $lieux)
                ->select('lieu_vote', DB::raw('MAX(suivi_count) as max_suivi'), DB::raw('SUM(suivi_count) as total_suivi'))
                ->groupBy('lieu_vote')->get() as $r) {
                $suiviByLieu[$r->lieu_vote] = ['max' => (int) $r->max_suivi, 'total' => (int) $r->total_suivi];
            }
        }

        $items = collect($paginator->items())->map(function ($item) use ($newByLieu, $suiviByLieu) {
            $list = $newByLieu[$item->lieu_vote] ?? [];
            sort($list);
            $item->localisation_new = implode("\n", $list);
            $item->suivi_max = $suiviByLieu[$item->lieu_vote]['max'] ?? 0;
            $item->suivi_total = $suiviByLieu[$item->lieu_vote]['total'] ?? 0;
            return $item;
        });

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items, $paginator->total(), $paginator->perPage(), $paginator->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'nouveaux');
        $fe = DB::connection('recensement');

        $filename = "comparaison-lieux-{$type}-" . date('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($type, $fe) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 for Excel
            fwrite($out, "\xEF\xBB\xBF");

            if ($type === 'supprimes') {
                fputcsv($out, ['DEPARTEMENT', 'COMMUNE', 'LIEU_VOTE', 'EFFECTIF'], ';');
                $this->querySupprimes($fe)->orderBy('bs.arrondissement')->orderBy('bs.commune')->orderBy('bs.lieu_vote')
                    ->chunk(500, function ($rows) use ($out) {
                        foreach ($rows as $r) fputcsv($out, [$r->departement, $r->commune, $r->lieu_vote, $r->effectif], ';');
                    });
            } elseif ($type === 'deplaces') {
                fputcsv($out, ['LIEU_VOTE', 'DEPT_ANCIEN', 'COMMUNE_ANCIEN', 'NOUVELLES_LOCALISATIONS (dept/commune)'], ';');
                $this->queryDeplaces($fe)->orderBy('old.lieu_vote')
                    ->chunk(500, function ($rows) use ($out, $fe) {
                        $lieux = $rows->pluck('lieu_vote')->all();
                        $byLieu = [];
                        if (!empty($lieux)) {
                            foreach ($fe->table('tmp_csv_lieux')->whereIn('lieu_vote', $lieux)
                                ->select('lieu_vote', 'departement', 'commune')->distinct()->get() as $r) {
                                $byLieu[$r->lieu_vote][] = $r->departement.'/'.$r->commune;
                            }
                        }
                        foreach ($rows as $r) {
                            $list = $byLieu[$r->lieu_vote] ?? [];
                            sort($list);
                            fputcsv($out, [$r->lieu_vote, $r->departement_old, $r->commune_old, implode(' | ', $list)], ';');
                        }
                    });
            } else {
                fputcsv($out, ['DEPARTEMENT', 'COMMUNE', 'LIEU_VOTE'], ';');
                $this->queryNouveaux($fe)->orderBy('t.departement')->orderBy('t.commune')->orderBy('t.lieu_vote')
                    ->chunk(500, function ($rows) use ($out) {
                        foreach ($rows as $r) fputcsv($out, [$r->departement, $r->commune, $r->lieu_vote], ';');
                    });
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
