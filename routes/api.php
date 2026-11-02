<?php

use App\Http\Controllers\Api\Terrain\JetonController;
use Illuminate\Support\Facades\Route;

Route::prefix('terrain')->group(function () {
    Route::post('jetons', [JetonController::class, 'store'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'abilities:terrain', 'can:logistique-terrain', 'throttle:60,1'])->group(function () {
        Route::delete('jetons/courant', [JetonController::class, 'destroy']);
    });
});
