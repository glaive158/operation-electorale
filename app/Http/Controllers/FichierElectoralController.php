<?php

namespace App\Http\Controllers;

use App\Services\FichierElectoralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FichierElectoralController extends Controller
{
    public function __construct(private FichierElectoralService $fe) {}

    private static function feDb()
    {
        return DB::connection('recensement');
    }

    public function index(Request $request)
    {
        $bloc      = (int) $request->input('bloc', 0);
        $resultats = null;
        $searched  = false;

        $filtres = array_filter([
            'nom'         => $request->input('nom'),
            'prenom'      => $request->input('prenom'),
            'datenaiss'   => $request->input('datenaiss'),
            'prenom_pere' => $request->input('prenom_pere'),
            'prenom_mere' => $request->input('prenom_mere'),
            'nom_mere'    => $request->input('nom_mere'),
            'lieunaiss'   => $request->input('lieunaiss'),
            'numcni'      => $request->input('numcni'),
            'numelec'     => $request->input('numelec'),
            'region_nom'  => $request->input('region_nom'),
            'dept_nom'    => $request->input('dept_nom'),
            'arr_nom'     => $request->input('arr_nom'),
            'commune_nom' => $request->input('commune_nom'),
            'region_id'   => $request->input('region_id'),
            'dept_id'     => $request->input('dept_id'),
            'arr_id'      => $request->input('arr_id'),
        ]);

        $regions = static::feDb()->table('regions')->orderBy('nom')->get(['id', 'nom']);

        if ($bloc === 1 && (!empty($filtres['nom']) || !empty($filtres['prenom']) || !empty($filtres['datenaiss']))) {
            $searched = true;
            $q = static::feDb()->table('fichier_electoral');

            if (!empty($filtres['nom']))
                $q->where('nom', 'like', strtoupper($filtres['nom']) . '%');

            if (!empty($filtres['prenom']))
                $q->where('prenom', 'like', strtoupper($filtres['prenom']) . '%');

            if (!empty($filtres['datenaiss']))
                $q->where('datenaiss', $filtres['datenaiss']);

            if (!empty($filtres['prenom_pere']))
                $q->where('prenom_pere', 'like', '%' . strtoupper($filtres['prenom_pere']) . '%');

            if (!empty($filtres['prenom_mere']))
                $q->where('prenom_mere', 'like', '%' . strtoupper($filtres['prenom_mere']) . '%');

            if (!empty($filtres['nom_mere']))
                $q->where('nom_mere', 'like', '%' . strtoupper($filtres['nom_mere']) . '%');

            if (!empty($filtres['lieunaiss']))
                $q->where('lieunaiss', 'like', strtoupper($filtres['lieunaiss']) . '%');

            if (!empty($filtres['region_nom']))
                $q->where('departement', $filtres['region_nom']);

            if (!empty($filtres['dept_nom']))
                $q->where('arrondissement', $filtres['dept_nom']);

            if (!empty($filtres['arr_nom'])) {
                $communesArr = static::feDb()->table('communes')
                    ->join('arrondissements', 'communes.arrondissement_id', '=', 'arrondissements.id')
                    ->where('arrondissements.nom', $filtres['arr_nom'])
                    ->pluck('communes.nom');
                $q->whereIn('commune', $communesArr);
            }

            if (!empty($filtres['commune_nom']))
                $q->where('commune', $filtres['commune_nom']);

            $resultats = $q->orderBy('nom')->orderBy('prenom')->simplePaginate(50)->withQueryString();
        }

        if ($bloc === 2 && (!empty($filtres['numcni']) || !empty($filtres['numelec']))) {
            $searched = true;
            $q = static::feDb()->table('fichier_electoral');

            if (!empty($filtres['numcni']))
                $q->where('numcni', $filtres['numcni']);

            if (!empty($filtres['numelec']))
                $q->where('numelec', $filtres['numelec']);

            $resultats = $q->orderBy('nom')->orderBy('prenom')->simplePaginate(50)->withQueryString();
        }

        return view('fichier-electoral.index', compact('resultats', 'searched', 'filtres', 'bloc', 'regions'));
    }

    public function show($id)
    {
        $electeur = static::feDb()->table('fichier_electoral')->where('id', $id)->first();
        abort_if(!$electeur, 404);
        return view('fichier-electoral.show', compact('electeur'));
    }

    public function byNin(string $nin)
    {
        $e = static::feDb()->table('fichier_electoral')
            ->where('numcni', $nin)->where('origine', 'national')->first();

        if (!$e) return response()->json(['found' => false]);

        $commune = static::feDb()->table('communes')->where('nom', $e->commune)->first();

        return response()->json([
            'found'       => true,
            'numcni'      => $e->numcni,
            'nom'         => $e->nom,
            'prenom'      => $e->prenom,
            'datenaiss'   => $e->datenaiss,
            'lieunaiss'   => $e->lieunaiss,
            'region'      => $e->departement,
            'departement' => $e->arrondissement,
            'commune'     => $e->commune,
            'commune_id'  => $commune?->id,
            'lieu_vote'   => $e->lieu_vote,
            'numelec'     => $e->numelec,
            'prenom_pere' => $e->prenom_pere,
        ]);
    }

    public function geoRegionDepts($regionId)
    {
        $depts = Cache::remember("fe_geo_depts_{$regionId}", 3600, function () use ($regionId) {
            return DB::connection('recensement')->table('departements')
                ->where('region_id', $regionId)->orderBy('nom')->get(['id', 'nom']);
        });
        return response()->json($depts);
    }

    public function geoDeptArrs($deptId)
    {
        $arrs = Cache::remember("fe_geo_arrs_{$deptId}", 3600, function () use ($deptId) {
            return DB::connection('recensement')->table('arrondissements')
                ->where('departement_id', $deptId)->orderBy('nom')->get(['id', 'nom']);
        });
        return response()->json($arrs);
    }

    public function geoArrCommunes($arrId)
    {
        $communes = Cache::remember("fe_geo_communes_{$arrId}", 3600, function () use ($arrId) {
            return DB::connection('recensement')->table('communes')
                ->where('arrondissement_id', $arrId)->orderBy('nom')->get(['id', 'nom']);
        });
        return response()->json($communes);
    }

    public function geoAdressesByCommune(Request $request)
    {
        $communeNom = $request->input('commune_nom');
        if (!$communeNom) return response()->json([]);

        $adresses = DB::connection('recensement')
            ->table('adresses_electorales')
            ->whereRaw('UPPER(commune_nom) = ?', [strtoupper($communeNom)])
            ->orderBy('adresse')
            ->pluck('adresse');

        return response()->json($adresses);
    }
}
