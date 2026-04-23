<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Services\FichierElectoralService;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(FichierElectoralService $fe)
    {
        $user = auth()->user();

        /* Stats opérations locales */
        $query = Operation::query();
        if ($user->isCommission())   $query->where('commune_commission_id', $user->commune_id);
        elseif ($user->isSousPrefet()) $query->where('arrondissement_id', $user->arrondissement_id);
        elseif ($user->isPrefet())   $query->where('departement_id', $user->departement_id);
        elseif ($user->isGouverneur()) $query->where('region_id', $user->region_id);

        /* Dernières opérations — clone AVANT selectRaw */
        $dernieres = (clone $query)->with('user')->latest()->limit(8)->get();

        $statsOps = $query->selectRaw('
            COUNT(*) as total,
            SUM(statut = "en_attente") as en_attente,
            SUM(statut = "validee") as validees,
            SUM(statut = "rejetee") as rejetees,
            SUM(type = "inscription") as inscriptions,
            SUM(type = "modification") as modifications,
            SUM(type = "changement") as changements,
            SUM(type = "radiation") as radiations
        ')->first();

        /* Stats fichier electoral (admin/gouverneur only, cached) */
        $statsElecteurs = null;
        if ($user->isAdmin() || $user->isGouverneur()) {
            try { $statsElecteurs = $fe->statsSummary(); } catch (\Throwable) {}
        }

        /* Évolution sur 7 jours */
        $evolution = DB::table('operations')
            ->selectRaw('DATE(created_at) as jour, COUNT(*) as nb, type')
            ->when(!$user->isAdmin(), fn($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->groupBy('jour','type')
            ->orderBy('jour')
            ->get()
            ->groupBy('type');

        return view('dashboard.index', compact('statsOps','dernieres','statsElecteurs','evolution'));
    }
}
