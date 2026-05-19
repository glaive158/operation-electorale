<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditCommunesController extends Controller
{
    private function baseQuery($fe)
    {
        return $fe->table('cartenats as c')
            ->join('csv_fichier_electoral as x', 'c.numcni', '=', 'x.numcni')
            ->whereRaw(
                "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(c.commune),' ',''),'(',''),')',''),'-','')) "
                ." <> UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(x.commune),'\"',''),' ',''),'(',''),')',''),'-',''))"
            )
            ->select(
                'c.numcni',
                'c.nom',
                'c.prenom',
                'c.datenaiss',
                'c.commune as commune_carte',
                'x.commune as commune_csv',
                'c.lieuvote as lieuvote_carte',
                'x.lieu_vote as lieuvote_csv',
                'c.numelec as numelec_carte',
                'x.numelec as numelec_csv',
                'c.etat'
            );
    }

    public function index(Request $request)
    {
        $fe = DB::connection('recensement');
        $communeCsv = $request->input('commune_csv', '');

        $q = $this->baseQuery($fe);
        if ($communeCsv !== '') {
            $q->where('x.commune', $communeCsv);
        }

        $resultats = $q->orderBy('x.commune')->orderBy('c.commune')->orderBy('c.nom')->get();

        $communesCsv = $this->baseQuery($fe)
            ->select('x.commune', DB::raw('COUNT(*) as nb'))
            ->groupBy('x.commune')
            ->orderByDesc('nb')
            ->get();

        $total = $this->baseQuery($fe)->count();

        return view('audit-communes.index', compact('resultats', 'communesCsv', 'communeCsv', 'total'));
    }

    public function export(Request $request)
    {
        $fe = DB::connection('recensement');
        $communeCsv = $request->input('commune_csv', '');

        $q = $this->baseQuery($fe);
        if ($communeCsv !== '') {
            $q->where('x.commune', $communeCsv);
        }
        $rows = $q->orderBy('x.commune')->orderBy('c.commune')->orderBy('c.nom')->cursor();

        $filename = 'audit_communes_76_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['NIN', 'Nom', 'Prénom', 'DDN', 'Commune Carte', 'Commune CSV', 'Lieu Vote Carte', 'Lieu Vote CSV', 'NumElec Carte', 'NumElec CSV', 'État'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->numcni, $r->nom, $r->prenom, $r->datenaiss,
                    $r->commune_carte, $r->commune_csv,
                    $r->lieuvote_carte, $r->lieuvote_csv,
                    $r->numelec_carte, $r->numelec_csv,
                    $r->etat,
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
