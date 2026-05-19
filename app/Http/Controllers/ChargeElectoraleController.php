<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChargeElectoraleController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'tous'); // sous, sur, tous
        $seuilMin = (int) $request->input('seuil_min', 10);
        $seuilMax = (int) $request->input('seuil_max', 600);

        $regionId = $request->input('region_id');
        $deptId = $request->input('dept_id');

        // Temporarily disable cache for debugging
        $data = (function () use ($type, $seuilMin, $seuilMax, $regionId, $deptId) {
            $fe = DB::connection('recensement');

            $regionNom = null;
            $deptNom = null;
            if ($regionId) {
                $regionNom = DB::connection('recensement')->table('regions')->find($regionId)->nom ?? null;
            }
            if ($deptId) {
                $deptNom = DB::connection('recensement')->table('departements')->find($deptId)->nom ?? null;
            }

            // Sous-charge
            $sousCh = [];
            if ($type === 'sous' || $type === 'tous') {
                $q = $fe->table('bureau_stats')->where('effectif', '<', $seuilMin);
                if ($regionNom) $q->where('departement', $regionNom);
                if ($deptNom) $q->where('arrondissement', $deptNom);
                $sousCh = $q->orderBy('effectif')->limit(500)->get();
            }

            // Surcharge
            $surCh = [];
            if ($type === 'sur' || $type === 'tous') {
                $q = $fe->table('bureau_stats')->where('effectif', '>', $seuilMax);
                if ($regionNom) $q->where('departement', $regionNom);
                if ($deptNom) $q->where('arrondissement', $deptNom);
                $surCh = $q->orderByDesc('effectif')->limit(500)->get();
            }

            return [
                'sous_charge' => $sousCh,
                'sur_charge' => $surCh,
                'nb_sous' => count($sousCh),
                'nb_sur' => count($surCh),
            ];
        })();

        $regions = DB::connection('recensement')->table('regions')->orderBy('nom')->get(['id', 'nom']);

        return view('charge-electorale.index', compact('data', 'type', 'seuilMin', 'seuilMax', 'regions', 'regionId', 'deptId'));
    }

    public function showBureau($codeBureau)
    {
        $bureau = DB::connection('recensement')->table('bureau_stats')
            ->where('code_bureau', $codeBureau)
            ->first();

        if (!$bureau) {
            abort(404, 'Bureau non trouvé');
        }

        $electeurs = DB::connection('recensement')->table('fichier_electoral')
            ->where('code_bureau', $codeBureau)
            ->where('origine', 'national')
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        return view('charge-electorale.bureau', compact('bureau', 'electeurs'));
    }
}
