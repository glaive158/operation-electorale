<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarteElectoraleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FichierElectoralController;
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
