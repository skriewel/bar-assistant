<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kami\Cocktail\Http\Controllers\CocktailTapController;

$tapApiMiddleware = ['auth:sanctum'];
if (config('bar-assistant.mail_require_confirmation') === true) {
    $tapApiMiddleware[] = 'verified';
}

Route::middleware($tapApiMiddleware)->group(function () {
    Route::get('taps/stats', [CocktailTapController::class, 'stats'])->middleware(['ability:cocktails.read']);

    Route::prefix('cocktails/{id}/taps')->group(function () {
        Route::get('/', [CocktailTapController::class, 'index'])->middleware(['ability:cocktails.read']);
        Route::post('/', [CocktailTapController::class, 'store'])->middleware(['ability:cocktails.write']);
        Route::patch('/{tapId}', [CocktailTapController::class, 'update'])->middleware(['ability:cocktails.write']);
        Route::delete('/{tapId}', [CocktailTapController::class, 'destroy'])->middleware(['ability:cocktails.write']);
    });
});
