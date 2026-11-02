<?php

use App\Http\Controllers\Api\Terrain\AffectationController;
use App\Http\Controllers\Api\Terrain\ClePreuveController;
use App\Http\Controllers\Api\Terrain\ContestationController;
use App\Http\Controllers\Api\Terrain\DeclarationController;
use App\Http\Controllers\Api\Terrain\JetonController;
use App\Http\Controllers\Api\Terrain\PieceController;
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
        Route::post('declarations/{declaration}/contestation', [ContestationController::class, 'store'])->whereNumber('declaration');
    });
});
