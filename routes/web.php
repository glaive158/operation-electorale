<?php

use App\Http\Controllers\AuditCommunesController;
use App\Http\Controllers\AuditElectoralController;
use App\Http\Controllers\AuditRevisionsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarteElectoraleController;
use App\Http\Controllers\ChargeElectoraleController;
use App\Http\Controllers\ComparaisonFichiersController;
use App\Http\Controllers\ComparaisonLieuxController;
use App\Http\Controllers\ImpactDeplacementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FichierElectoralController;
use App\Http\Controllers\ListeEmargementController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/* ── Auth ── */
Route::get('login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login']);
Route::post('logout',[AuthController::class, 'logout'])->name('logout')->middleware('auth');

/* ── Protected ── */
Route::middleware(['auth'])->group(function () {

    Route::get('/',          [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index']);

    /* NIN lookup — accessible à tous (utilisé dans le formulaire create) */
    Route::get('/fichier-electoral/nin/{nin}', [FichierElectoralController::class, 'byNin'])->name('fichier-electoral.nin');

    /* Fichier électoral — interdit aux commissions */
    Route::middleware('role:admin,gouverneur,prefet,sous_prefet')->group(function () {
        Route::get('/fichier-electoral',           [FichierElectoralController::class, 'index'])->name('fichier-electoral');
        Route::get('/fichier-electoral/{id}',      [FichierElectoralController::class, 'show'])->name('fichier-electoral.show');

        /* Carte électorale */
        Route::get('/carte-electorale',              [CarteElectoraleController::class, 'index'])->name('carte-electorale');
        Route::get('/carte-electorale/api/pays',     [CarteElectoraleController::class, 'apiPays']);
        Route::get('/carte-electorale/api/villes/{pays}', [CarteElectoraleController::class, 'apiVilles']);

        /* Charge électorale */
        Route::get('/charge-electorale',             [ChargeElectoraleController::class, 'index'])->name('charge-electorale');
        Route::get('/charge-electorale/bureau/{codeBureau}', [ChargeElectoraleController::class, 'showBureau'])->name('charge-electorale.bureau');

        /* Liste d'émargement */
        Route::get('/liste-emargement',              [ListeEmargementController::class, 'index'])->name('liste-emargement');
        Route::post('/liste-emargement/generate',    [ListeEmargementController::class, 'generate'])->name('liste-emargement.generate');
        Route::post('/liste-emargement/generate-stream', [ListeEmargementController::class, 'generateStream'])->name('liste-emargement.generate-stream');
        Route::post('/liste-emargement/generate-zip', [ListeEmargementController::class, 'generateZip'])->name('liste-emargement.generate-zip');

        /* Comparaison lieux de vote (ancien vs nouveau) */
        Route::get('/comparaison-lieux',         [ComparaisonLieuxController::class, 'index'])->name('comparaison-lieux');
        Route::get('/comparaison-lieux/export',  [ComparaisonLieuxController::class, 'export'])->name('comparaison-lieux.export');

        /* Comparaison fichiers (3 tableaux côte à côte) */
        Route::get('/comparaison-fichiers', [ComparaisonFichiersController::class, 'index'])->name('comparaison-fichiers');

        /* Impact déplacement bureaux sur électeurs */
        Route::get('/impact-deplacement', [ImpactDeplacementController::class, 'index'])->name('impact-deplacement');

        /* Audit révisions 2025 */
        Route::get('/audit-revisions', [AuditRevisionsController::class, 'index'])->name('audit-revisions');

        /* Audit fichier électoral (disparus / nouveaux / changements bureau) */
        Route::get('/audit-electoral', [AuditElectoralController::class, 'index'])->name('audit-electoral');
        Route::get('/audit-electoral/export/{type}', [AuditElectoralController::class, 'export'])->name('audit-electoral.export');

        /* Audit 76 incohérences commune (cartenats vs CSV) */
        Route::get('/audit-communes', [AuditCommunesController::class, 'index'])->name('audit-communes');
        Route::get('/audit-communes/export', [AuditCommunesController::class, 'export'])->name('audit-communes.export');
        Route::get('/liste-emargement/api/lieux/{communeId}', [ListeEmargementController::class, 'apiLieuxVote']);
        Route::get('/liste-emargement/api/bureaux/{lieuVote}', [ListeEmargementController::class, 'apiBureaux']);
        Route::get('/liste-emargement/api/count',    [ListeEmargementController::class, 'apiCount']);
    });

    /* Cascade géographique (JSON) — accessible à tous pour le formulaire create */
    Route::get('/geo/region/{regionId}/departements',      [FichierElectoralController::class, 'geoRegionDepts']);
    Route::get('/geo/departement/{deptId}/arrondissements',[FichierElectoralController::class, 'geoDeptArrs']);
    Route::get('/geo/arrondissement/{arrId}/communes',     [FichierElectoralController::class, 'geoArrCommunes']);
    Route::get('/geo/commune/adresses',                    [FichierElectoralController::class, 'geoAdressesByCommune']);

    /* Opérations */
    Route::resource('operations', OperationController::class);
    Route::get('/operations/{operation}/imprimer',        [OperationController::class, 'imprimer'])->name('operations.imprimer');
    Route::get('/operations/{operation}/scanner',         [OperationController::class, 'scanner'])->name('operations.scanner');
    Route::post('/operations/{operation}/scanner',        [OperationController::class, 'scannerStore'])->name('operations.scanner.store');
    Route::patch('/operations/{operation}/valider', [OperationController::class, 'valider'])
         ->name('operations.valider')
         ->middleware('role:admin,prefet,sous_prefet,gouverneur');
    Route::patch('/operations/{operation}/rejeter', [OperationController::class, 'rejeter'])
         ->name('operations.rejeter')
         ->middleware('role:admin,prefet,sous_prefet,gouverneur');

    /* Users — admin only */
    Route::resource('users', UserController::class)->middleware('role:admin');

});
