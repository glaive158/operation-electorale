<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListeEmargementController extends Controller
{
    public function index()
    {
        $fe = DB::connection('recensement');

        $regions = $fe->table('regions')->orderBy('nom')->get(['id', 'nom']);

        // Pre-load all geo data for fast client-side filtering
        $departements = $fe->table('departements')->orderBy('nom')->get(['id', 'nom', 'region_id']);
        $arrondissements = $fe->table('arrondissements')->orderBy('nom')->get(['id', 'nom', 'departement_id']);
        $communes = $fe->table('communes')->orderBy('nom')->get(['id', 'nom', 'arrondissement_id']);

        return view('liste-emargement.index', compact('regions', 'departements', 'arrondissements', 'communes'));
    }

    public function generate(Request $request)
    {
        // Increase memory limit for PDF generation
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $regionId = $request->input('region_id');
        $deptId = $request->input('dept_id');
        $arrId = $request->input('arr_id');
        $communeId = $request->input('commune_id');
        $lieuVote = $request->input('lieu_vote');
        $codeBureau = $request->input('code_bureau');

        // Require at least commune for performance
        if (!$communeId && !$lieuVote && !$codeBureau) {
            return back()->with('error', 'Sélectionnez au moins une commune pour générer la liste.');
        }

        $fe = DB::connection('recensement');

        // Get location names
        $regionNom = $regionId ? optional($fe->table('regions')->find($regionId))->nom : null;
        $deptNom = $deptId ? optional($fe->table('departements')->find($deptId))->nom : null;
        $arrNom = $arrId ? optional($fe->table('arrondissements')->find($arrId))->nom : null;
        $communeNom = $communeId ? optional($fe->table('communes')->find($communeId))->nom : null;

        // Step 1: Get list of bureaux quickly from bureau_stats
        $qBureaux = $fe->table('bureau_stats');

        if ($regionNom) $qBureaux->where('departement', $regionNom);
        if ($deptNom) $qBureaux->where('arrondissement', $deptNom);
        if ($communeNom) $qBureaux->where('commune', $communeNom);
        if ($lieuVote) $qBureaux->where('lieu_vote', $lieuVote);
        if ($codeBureau) $qBureaux->where('code_bureau', $codeBureau);

        $bureauxList = $qBureaux->orderBy('code_bureau')->limit(100)->get();

        if ($bureauxList->isEmpty()) {
            return back()->with('error', 'Aucun bureau trouvé avec ces critères.');
        }

        // Step 2: Load voters for each bureau (one at a time for memory efficiency)
        $bureaux = $bureauxList->map(function ($bureau) use ($fe) {
            $electeurs = $fe->table('fichier_electoral')
                ->where('code_bureau', $bureau->code_bureau)
                ->where('origine', 'national')
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get();

            return [
                'code_bureau' => $bureau->code_bureau,
                'lieu_vote' => $bureau->lieu_vote,
                'commune' => $bureau->commune,
                'arrondissement' => $bureau->arrondissement,
                'departement' => $bureau->departement,
                'effectif' => $bureau->effectif,
                'electeurs' => $electeurs,
            ];
        });

        return view('liste-emargement.print', compact('bureaux'));
    }

    public function apiLieuxVote($communeId)
    {
        $communeNom = DB::connection('recensement')->table('communes')->find($communeId)->nom ?? null;
        if (!$communeNom) return response()->json([]);

        $lieux = DB::connection('recensement')->table('bureau_stats')
            ->where('commune', $communeNom)
            ->whereNotNull('lieu_vote')
            ->distinct()
            ->orderBy('lieu_vote')
            ->pluck('lieu_vote');

        return response()->json($lieux->map(fn($l) => ['id' => $l, 'nom' => $l])->values());
    }

    public function apiBureaux($lieuVote)
    {
        $bureaux = DB::connection('recensement')->table('bureau_stats')
            ->where('lieu_vote', $lieuVote)
            ->whereNotNull('code_bureau')
            ->orderBy('code_bureau')
            ->pluck('code_bureau');

        return response()->json($bureaux->map(fn($b) => ['id' => $b, 'nom' => $b])->values());
    }

    public function apiCount(Request $request)
    {
        $regionId = $request->input('region_id');
        $deptId = $request->input('dept_id');
        $arrId = $request->input('arr_id');
        $communeId = $request->input('commune_id');
        $lieuVote = $request->input('lieu_vote');

        $fe = DB::connection('recensement');

        $regionNom = $regionId ? optional($fe->table('regions')->find($regionId))->nom : null;
        $deptNom = $deptId ? optional($fe->table('departements')->find($deptId))->nom : null;
        $communeNom = $communeId ? optional($fe->table('communes')->find($communeId))->nom : null;

        $q = $fe->table('bureau_stats');

        if ($regionNom) $q->where('departement', $regionNom);
        if ($deptNom) $q->where('arrondissement', $deptNom);

        // Filter by arrondissement (geo) via communes list
        if ($arrId && !$communeId) {
            $communesArr = $fe->table('communes')->where('arrondissement_id', $arrId)->pluck('nom');
            $q->whereIn('commune', $communesArr);
        }

        if ($communeNom) $q->where('commune', $communeNom);
        if ($lieuVote) $q->where('lieu_vote', $lieuVote);

        $count = $q->count();
        $limit = $arrId && !$communeId ? 1500 : 100;

        return response()->json([
            'count' => $count,
            'limit' => $limit,
            'over_limit' => $count > $limit,
        ]);
    }

    /**
     * Streamed generation - low memory, handles up to ~1500 bureaux.
     * Filters: region, dept, arr (geo), commune, lieu_vote, code_bureau.
     */
    public function generateStream(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '256M');

        $regionId = $request->input('region_id');
        $deptId = $request->input('dept_id');
        $arrId = $request->input('arr_id');
        $communeId = $request->input('commune_id');
        $lieuVote = $request->input('lieu_vote');
        $codeBureau = $request->input('code_bureau');

        $fe = DB::connection('recensement');

        $regionNom = $regionId ? optional($fe->table('regions')->find($regionId))->nom : null;
        $deptNom = $deptId ? optional($fe->table('departements')->find($deptId))->nom : null;
        $arrNom = $arrId ? optional($fe->table('arrondissements')->find($arrId))->nom : null;
        $communeNom = $communeId ? optional($fe->table('communes')->find($communeId))->nom : null;

        // Build bureaux query
        $q = $fe->table('bureau_stats');
        if ($regionNom) $q->where('departement', $regionNom);
        if ($deptNom) $q->where('arrondissement', $deptNom);
        if ($arrId && !$communeId) {
            $communesArr = $fe->table('communes')->where('arrondissement_id', $arrId)->pluck('nom');
            $q->whereIn('commune', $communesArr);
        }
        if ($communeNom) $q->where('commune', $communeNom);
        if ($lieuVote) $q->where('lieu_vote', $lieuVote);
        if ($codeBureau) $q->where('code_bureau', $codeBureau);

        $total = (clone $q)->count();
        if ($total === 0) {
            return back()->with('error', 'Aucun bureau trouvé avec ces critères.');
        }
        if ($total > 1500) {
            return back()->with('error', "Trop de bureaux ($total). Filtrez par arrondissement ou commune.");
        }

        $bureaux = $q->orderBy('commune')->orderBy('lieu_vote')->orderBy('code_bureau')->get();

        return response()->stream(function () use ($bureaux, $fe) {
            // Disable output buffering compression to allow flushing
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) ob_end_flush();

            echo $this->renderHeader();
            $this->flushOut();

            $bureauxByCommune = $bureaux->groupBy('commune');
            foreach ($bureauxByCommune as $commune => $bureauxC) {
                echo $this->renderCoverCommune($commune, $bureauxC);
                $this->flushOut();

                $bureauxByLieu = $bureauxC->groupBy('lieu_vote');
                foreach ($bureauxByLieu as $lieu => $bureauxL) {
                    echo $this->renderCoverLieu($commune, $lieu, $bureauxL);
                    $this->flushOut();

                    foreach ($bureauxL as $bureau) {
                        echo $this->renderBureauCover((array) $bureau);
                        $this->flushOut();
                        $this->streamBureauPages($fe, (array) $bureau);
                        echo '<div class="blank-page"></div>';
                        $this->flushOut();
                    }
                    echo '<div class="blank-page"></div>';
                }
                echo '<div class="blank-page"></div>';
            }

            echo $this->renderFooter();
            $this->flushOut();
        }, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function flushOut(): void
    {
        if (ob_get_level() > 0) @ob_flush();
        @flush();
    }

    /**
     * ZIP generation: 1 HTML file per commune.
     * User extracts ZIP, opens commune file, prints, repeats.
     * Each file stays small enough for browser to render.
     */
    public function generateZip(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $regionId = $request->input('region_id');
        $deptId = $request->input('dept_id');
        $arrId = $request->input('arr_id');
        $communeId = $request->input('commune_id');
        $lieuVote = $request->input('lieu_vote');

        $fe = DB::connection('recensement');

        $regionNom = $regionId ? optional($fe->table('regions')->find($regionId))->nom : null;
        $deptNom = $deptId ? optional($fe->table('departements')->find($deptId))->nom : null;
        $arrNom = $arrId ? optional($fe->table('arrondissements')->find($arrId))->nom : null;
        $communeNom = $communeId ? optional($fe->table('communes')->find($communeId))->nom : null;

        $q = $fe->table('bureau_stats');
        if ($regionNom) $q->where('departement', $regionNom);
        if ($deptNom) $q->where('arrondissement', $deptNom);
        if ($arrId && !$communeId) {
            $communesArr = $fe->table('communes')->where('arrondissement_id', $arrId)->pluck('nom');
            $q->whereIn('commune', $communesArr);
        }
        if ($communeNom) $q->where('commune', $communeNom);
        if ($lieuVote) $q->where('lieu_vote', $lieuVote);

        $total = (clone $q)->count();
        if ($total === 0) {
            return back()->with('error', 'Aucun bureau trouvé avec ces critères.');
        }
        if ($total > 1500) {
            return back()->with('error', "Trop de bureaux ($total). Filtrez par arrondissement ou commune.");
        }

        $bureaux = $q->orderBy('commune')->orderBy('lieu_vote')->orderBy('code_bureau')->get();
        $bureauxByCommune = $bureaux->groupBy('commune');

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
        $zipPath = $tempDir . '/liste-emargement-' . uniqid() . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Impossible de créer le fichier ZIP.');
        }

        foreach ($bureauxByCommune as $commune => $bureauxC) {
            $html = $this->buildCommuneHtml($commune, $bureauxC, $fe);
            $filename = $this->slugify($commune) . '.html';
            $zip->addFromString($filename, $html);
            unset($html);
        }

        $zip->close();
        unset($bureaux, $bureauxByCommune);

        $zipName = 'liste-emargement-' . ($arrNom ?? $communeNom ?? 'export') . '-' . date('Ymd-His') . '.zip';
        $zipName = $this->slugify($zipName) . '.zip';

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);
    }

    private function buildCommuneHtml($commune, $bureauxC, $fe): string
    {
        $html = view('liste-emargement.stream-header')->render();
        $html .= $this->renderCoverCommune($commune, $bureauxC);

        $bureauxByLieu = $bureauxC->groupBy('lieu_vote');
        foreach ($bureauxByLieu as $lieu => $bureauxL) {
            $html .= $this->renderCoverLieu($commune, $lieu, $bureauxL);
            foreach ($bureauxL as $bureau) {
                $bureauArr = (array) $bureau;
                $html .= $this->renderBureauCover($bureauArr);
                $html .= $this->buildElecteursHtml($fe, $bureauArr);
                $html .= '<div class="blank-page"></div>';
            }
            $html .= '<div class="blank-page"></div>';
        }
        $html .= '<div class="blank-page"></div></body></html>';
        return $html;
    }

    private function buildElecteursHtml($fe, array $bureau): string
    {
        $perPage = 30;
        $electeurs = $fe->table('fichier_electoral')
            ->where('code_bureau', $bureau['code_bureau'])
            ->where('origine', 'national')
            ->orderBy('nom')->orderBy('prenom')
            ->get();

        $count = $electeurs->count();
        if ($count === 0) return '';

        $totalPages = max(1, (int) ceil($count / $perPage));
        $html = '';
        foreach ($electeurs->chunk($perPage)->values() as $pageIdx => $chunk) {
            $html .= $this->renderElecteursPage($bureau, $chunk->all(), $pageIdx, $totalPages, $perPage);
        }
        unset($electeurs);
        return $html;
    }

    private function slugify(string $text): string
    {
        $text = preg_replace('/[^A-Za-z0-9-]+/', '-', $text);
        $text = trim($text, '-');
        return $text ?: 'commune';
    }

    private function streamBureauPages($fe, array $bureau): void
    {
        $perPage = 30;

        // Load all voters for ONE bureau (max ~600 rows, ~1MB).
        // Avoid cursor(): unbuffered query gets killed when Blade render() hits DB.
        $electeurs = $fe->table('fichier_electoral')
            ->where('code_bureau', $bureau['code_bureau'])
            ->where('origine', 'national')
            ->orderBy('nom')->orderBy('prenom')
            ->get();

        $count = $electeurs->count();
        if ($count === 0) return;

        $totalPages = max(1, (int) ceil($count / $perPage));

        // Build all pages of this bureau then flush once → fewer flushes = browser breathes
        $html = '';
        foreach ($electeurs->chunk($perPage)->values() as $pageIdx => $chunk) {
            $html .= $this->renderElecteursPage($bureau, $chunk->all(), $pageIdx, $totalPages, $perPage);
        }
        echo $html;
        unset($electeurs, $html);
    }

    private function renderHeader(): string
    {
        return view('liste-emargement.stream-header')->render();
    }

    private function renderFooter(): string
    {
        return '</body></html>';
    }

    private function renderCoverCommune($commune, $bureauxC): string
    {
        return view('liste-emargement.partials.cover-commune', [
            'commune' => $commune,
            'nbBureaux' => $bureauxC->count(),
            'totalElecteurs' => $bureauxC->sum('effectif'),
        ])->render();
    }

    private function renderCoverLieu($commune, $lieu, $bureauxL): string
    {
        return view('liste-emargement.partials.cover-lieu', [
            'commune' => $commune,
            'lieu' => $lieu,
            'nbBureaux' => $bureauxL->count(),
            'totalElecteurs' => $bureauxL->sum('effectif'),
        ])->render();
    }

    private function renderBureauCover(array $bureau): string
    {
        return view('liste-emargement.partials.cover-bureau', compact('bureau'))->render();
    }

    private function renderElecteursPage(array $bureau, array $electeurs, int $pageIdx, int $totalPages, int $perPage): string
    {
        return view('liste-emargement.partials.electeurs-page', [
            'bureau' => $bureau,
            'electeurs' => $electeurs,
            'pageIdx' => $pageIdx,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ])->render();
    }
}
