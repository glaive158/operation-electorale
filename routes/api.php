<?php

use App\Http\Controllers\Api\Terrain\AffectationController;
use App\Http\Controllers\Api\Terrain\AppareilController;
use App\Http\Controllers\Api\Terrain\ClePreuveController;
use App\Http\Controllers\Api\Terrain\ContestationController;
use App\Http\Controllers\Api\Terrain\DeclarationController;
use App\Http\Controllers\Api\Terrain\JetonController;
use App\Http\Controllers\Api\Terrain\PieceController;
use App\Http\Controllers\Api\Terrain\RedistributionController;
use App\Http\Controllers\Api\Terrain\RestitutionController;
use Illuminate\Support\Facades\Route;

Route::prefix('terrain')->group(function () {
    Route::post('jetons', [JetonController::class, 'store'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'abilities:terrain', 'can:logistique-terrain', 'throttle:60,1'])->group(function () {
        Route::delete('jetons/courant', [JetonController::class, 'destroy']);

        Route::get('cle-preuve', [ClePreuveController::class, 'show']);

        Route::get('affectations', [AffectationController::class, 'index']);
        Route::get('decharges/{identifiant}', [AffectationController::class, 'show']);

        Route::post('pieces', [PieceController::class, 'store']);
        Route::match(['head'], 'pieces/{empreinte}', [PieceController::class, 'show'])->where('empreinte', '[0-9a-f]{64}');
        Route::post('remises/{remise}/declarations', [DeclarationController::class, 'store'])->whereNumber('remise');
        Route::get('appareil', [AppareilController::class, 'show']);
        Route::get('decharges/{decharge}/reserve', [AppareilController::class, 'reserve'])->whereNumber('decharge');
        Route::post('decharges/{decharge}/reserve', [AppareilController::class, 'reserver'])->whereNumber('decharge');
        Route::delete('decharges/{decharge}/reserve', [AppareilController::class, 'liberer'])->whereNumber('decharge');
        Route::post('decharges/{decharge}/redistributions', [RedistributionController::class, 'store'])->whereNumber('decharge');
        Route::post('declarations/{declaration}/contestation', [ContestationController::class, 'store'])->whereNumber('declaration');
        Route::post('restitutions/{restitution}/declarations', [RestitutionController::class, 'declarer'])->whereNumber('restitution');
        Route::post('restitutions/{restitution}/prise-en-charge', [RestitutionController::class, 'prendreEnCharge'])->whereNumber('restitution');
    });
});
