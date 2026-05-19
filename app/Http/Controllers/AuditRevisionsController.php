<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditRevisionsController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'overview');
        $search = trim($request->input('q', ''));

        $fe = DB::connection('recensement');

        // Stats globales
        $stats = [
            'inscription'  => $this->statsType($fe, 'Inscription'),
            'modification' => $this->statsType($fe, 'Modification'),
            'radiation'    => $this->statsType($fe, 'Radiation'),
            'changement'   => $this->statsType($fe, 'Changement de statut'),
        ];

        $rows = collect();
        $ficheElecteur = null;

        if ($type === 'recherche' && $search) {
            $ficheElecteur = $this->queryFicheElecteur($fe, $search);
        } elseif (in_array($type, ['inscription_ko', 'modification_ko', 'radiation_ko'])) {
            $typeMap = [
                'inscription_ko'  => ['Inscription',  true],
                'modification_ko' => ['Modification', true],
                'radiation_ko'    => ['Radiation',    false],
            ];
            [$typeDemande, $expectedAbsent] = $typeMap[$type];

            // EXISTS/NOT EXISTS: plus rapide que LEFT JOIN + IS NULL sur 7M lignes
            $q = $fe->table('revisions_demandees as rd')
                ->where('rd.type_demande', $typeDemande);

            if ($expectedAbsent) {
                $q->whereNotExists(function ($sub) use ($fe) {
                    $sub->select($fe->raw(1))
                        ->from('csv_fichier_electoral as cf')
                        ->whereColumn('cf.numcni', 'rd.numcni');
                });
            } else {
                $q->whereExists(function ($sub) use ($fe) {
                    $sub->select($fe->raw(1))
                        ->from('csv_fichier_electoral as cf')
                        ->whereColumn('cf.numcni', 'rd.numcni');
                });
            }

            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('rd.numcni', $search)
                       ->orWhere('rd.nom', 'LIKE', $search . '%')
                       ->orWhere('rd.commune', 'LIKE', $search . '%');
                });
            }

            $rows = $q->select('rd.region','rd.departement','rd.commune','rd.num_demande',
                               'rd.numcni','rd.prenom','rd.nom','rd.datenaiss','rd.lieu_vote','rd.bureau')
                ->orderBy('rd.region')->orderBy('rd.departement')->orderBy('rd.commune')
                ->simplePaginate(50)->withQueryString();

        } elseif ($type === 'radiation_all') {
            $q = $fe->table('revisions_demandees as rd')
                ->leftJoin('csv_fichier_electoral as cf', 'cf.numcni', '=', 'rd.numcni')
                ->where('rd.type_demande', 'Radiation');

            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('rd.numcni', $search)
                       ->orWhere('rd.nom', 'LIKE', $search . '%')
                       ->orWhere('rd.commune', 'LIKE', $search . '%');
                });
            }

            // Statut calculé en SQL — supprime le N+1
            $rows = $q->select(
                    'rd.region','rd.departement','rd.commune','rd.num_demande',
                    'rd.numcni','rd.prenom','rd.nom','rd.datenaiss',
                    $fe->raw("CASE WHEN cf.numcni IS NOT NULL THEN 'TOUJOURS PRESENT' ELSE 'BIEN RADIE' END as statut")
                )
                ->orderBy('rd.region')->orderBy('rd.departement')->orderBy('rd.commune')
                ->simplePaginate(50)->withQueryString();

        } elseif ($type === 'modification_audit') {
            $q = $fe->table('revisions_demandees as rd')
                ->join('csv_fichier_electoral as cf', 'cf.numcni', '=', 'rd.numcni')
                ->where('rd.type_demande', 'Modification');

            if ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('rd.numcni', $search)
                       ->orWhere('rd.nom', 'LIKE', $search . '%');
                });
            }

            $rows = $q->select(
                    'rd.commune as commune_demandee','rd.lieu_vote as lieu_demande','rd.bureau as bureau_demande',
                    'rd.numcni','rd.prenom','rd.nom','rd.num_demande',
                    'cf.commune as commune_csv','cf.lieu_vote as lieu_csv','cf.bureau as bureau_csv',
                    $fe->raw('CASE
                        WHEN UPPER(TRIM(rd.lieu_vote))=UPPER(TRIM(cf.lieu_vote))
                         AND LPAD(rd.bureau,2,"0")=LPAD(cf.bureau,2,"0")
                        THEN "OK" ELSE "KO"
                    END as match_status')
                )
                ->orderBy('rd.region')->orderBy('rd.commune')
                ->simplePaginate(50)->withQueryString();
        }

        return view('audit-revisions.index', compact('stats', 'type', 'rows', 'search', 'ficheElecteur'));
    }

    private function queryFicheElecteur($fe, string $search): ?array
    {
        // Résoudre le numcni : chercher par numcni direct OU par numelec
        $numcni = null;

        // 1. Chercher par numelec dans CSV
        $byNumelec = $fe->table('csv_fichier_electoral')->where('numelec', $search)->first();
        if ($byNumelec) {
            $numcni = $byNumelec->numcni;
        }

        // 2. Sinon chercher directement par numcni dans revisions_demandees
        if (!$numcni) {
            $exists = $fe->table('revisions_demandees')->where('numcni', $search)->exists();
            if ($exists) $numcni = $search;
        }

        // 3. Sinon numelec dans cartenats
        if (!$numcni) {
            $byCarte = $fe->table('cartenats')->where('numelec', $search)->first();
            if ($byCarte) $numcni = $byCarte->numcni;
        }

        if (!$numcni) return null;

        // Fiche nouveau CSV
        $csv = $fe->table('csv_fichier_electoral')->where('numcni', $numcni)->first();

        // Fiche ancien fichier électoral (FIC)
        $ancien = $fe->table('fichier_electoral')->where('numcni', $numcni)->first();

        // Toutes les demandes
        $demandes = $fe->table('revisions_demandees')
            ->where('numcni', $numcni)
            ->orderBy('num_demande')
            ->get();

        return compact('numcni', 'csv', 'ancien', 'demandes');
    }

    private function statsType($fe, string $typeDemande): array
    {
        // Lit depuis table pré-calculée audit_revisions_stats (instantané)
        $r = $fe->table('audit_revisions_stats')->where('type_demande', $typeDemande)->first();
        if ($r) {
            return ['total' => (int) $r->total, 'present' => (int) $r->present, 'absent' => (int) $r->absent];
        }
        // Fallback: compute live (slow)
        $r = $fe->select("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN cf.numelec IS NOT NULL THEN 1 ELSE 0 END) AS present,
                   SUM(CASE WHEN cf.numelec IS NULL THEN 1 ELSE 0 END) AS absent
            FROM revisions_demandees rd
            LEFT JOIN csv_fichier_electoral cf ON cf.numcni = rd.numcni
            WHERE rd.type_demande = ?
        ", [$typeDemande])[0];
        return ['total' => (int) $r->total, 'present' => (int) $r->present, 'absent' => (int) $r->absent];
    }
}
