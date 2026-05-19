<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditElectoralController extends Controller
{
    private const TABS = ['disparus', 'nouveaux', 'changements'];

    private const CAS_LABELS = [
        'introuvable'      => '❌ Introuvable (57)',
        'radie'            => '✅ Radié (44)',
        'revision_demandee'=> '📋 Révision demandée (222)',
        'numelec_change'   => '🔄 N° électeur changé (9)',
        'nin_change'       => '⚠️ NIN changé (1)',
    ];

    public function index(Request $request)
    {
        $tab  = in_array($request->input('tab'), self::TABS) ? $request->input('tab') : 'disparus';
        $dept = $request->input('dept') ?? '';
        $cas  = $request->input('cas') ?? '';

        $fe = DB::connection('recensement');

        $departements = $fe->table('audit_disparus_detail')
            ->distinct()->orderBy('departement')->pluck('departement');

        $counts = [
            'disparus'    => $fe->table('audit_disparus_detail')->count(),
            'nouveaux'    => $fe->table('audit_nouveaux')->count(),
            'changements' => $fe->table('audit_changement_bureau')->count(),
        ];

        $casCounts = $fe->table('audit_disparus_detail')
            ->select('cas', $fe->raw('COUNT(*) as nb'))
            ->groupBy('cas')
            ->pluck('nb', 'cas');

        $resultats = match ($tab) {
            'disparus'    => $this->queryDisparus($fe, $dept, $cas),
            'nouveaux'    => $this->queryNouveaux($fe, $dept),
            'changements' => $this->queryChangements($fe, $dept),
        };

        return view('audit-electoral.index', compact(
            'tab', 'dept', 'cas', 'departements', 'resultats', 'counts', 'casCounts'
        ));
    }

    public function export(Request $request, string $type)
    {
        abort_if(!in_array($type, self::TABS), 404);

        $fe   = DB::connection('recensement');
        $dept = $request->input('dept') ?? '';
        $cas  = $request->input('cas') ?? '';

        $rows = match ($type) {
            'disparus'    => $this->queryDisparus($fe, $dept, $cas, export: true),
            'nouveaux'    => $this->queryNouveaux($fe, $dept, export: true),
            'changements' => $this->queryChangements($fe, $dept, export: true),
        };

        $filename = "audit_{$type}_" . now()->format('Ymd_His') . ".csv";

        return response()->streamDownload(function () use ($rows, $type) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $headers = match ($type) {
                'disparus'    => ['N° Électeur','NIN','Nom','Prénom','Date Naiss.','Département','Commune','Lieu de vote','Code Bureau','Cas','N° Demande','Nouveau N° Électeur','Nouveau NIN','Nouvelle Commune','Nouveau Lieu','Nouveau Bureau','Rech. Nom+DDN','Rech. Nom+Prénom','Rech. NIN'],
                'nouveaux'    => ['N° Électeur','NIN','Nom','Prénom','Date Naiss.','Bureau','Commune','Département','Région','Lieu de vote'],
                'changements' => ['N° Électeur','NIN','Nom','Prénom','Date Naiss.','Anc. Bureau','Anc. Commune','Anc. Lieu','Anc. Dept','Nouv. Bureau','Nouv. Commune','Nouv. Lieu','Nouv. Dept','Région'],
            };
            fputcsv($out, $headers, ';');

            foreach ($rows as $r) {
                $row = match ($type) {
                    'disparus'    => [$r->ancien_numelec,$r->ancien_numcni,$r->nom,$r->prenom,$r->datenaiss,$r->departement,$r->commune,$r->lieu_vote,$r->code_bureau,$r->cas,$r->num_demande,$r->nouveau_numelec,$r->nouveau_numcni,$r->nouvelle_commune,$r->nouveau_lieu,$r->nouveau_bureau,$r->s2_nom_ddn,$r->s3_nom_prenom,$r->s4_numcni],
                    'nouveaux'    => [$r->numelec,$r->numcni,$r->nom,$r->prenom,$r->datenaiss,$r->code_bureau,$r->commune,$r->departement,$r->region,$r->lieu_vote],
                    'changements' => [$r->numelec,$r->numcni,$r->nom,$r->prenom,$r->datenaiss,$r->ancien_code_bureau,$r->ancienne_commune,$r->ancien_lieu,$r->ancien_dept,$r->nouveau_bureau_num,$r->nouvelle_commune,$r->nouveau_lieu,$r->nouveau_dept,$r->region],
                };
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function queryDisparus($fe, ?string $dept, ?string $cas = '', bool $export = false)
    {
        $q = $fe->table('audit_disparus_detail');
        if ($dept) $q->where('departement', $dept);
        if ($cas)  $q->where('cas', $cas);
        $q->orderBy('cas')->orderBy('departement')->orderBy('commune')->orderBy('nom');
        return $export ? $q->cursor() : $q->paginate(50)->withQueryString();
    }

    private function queryNouveaux($fe, ?string $dept, bool $export = false)
    {
        $q = $fe->table('audit_nouveaux');
        if ($dept) $q->where('departement', $dept);
        $q->orderBy('departement')->orderBy('commune')->orderBy('nom');
        return $export ? $q->cursor() : $q->paginate(50)->withQueryString();
    }

    private function queryChangements($fe, ?string $dept, bool $export = false)
    {
        $q = $fe->table('audit_changement_bureau');
        if ($dept) $q->where('ancien_dept', $dept);
        $q->orderBy('ancien_dept')->orderBy('ancienne_commune')->orderBy('nom');
        return $export ? $q->cursor() : $q->paginate(50)->withQueryString();
    }
}
