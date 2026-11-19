<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

// The web app and the mobile app both use these routes.
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::get('market', [MarketController::class, 'index']);
    Route::get('market/{symbol}/depth', [MarketController::class, 'depth']);
    Route::get('market/{symbol}/trades', [MarketController::class, 'trades']);
    Route::get('market/{symbol}/candles', [MarketController::class, 'candles']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('portfolio', [OrderController::class, 'portfolio']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders');
        Route::delete('orders/{id}', [OrderController::class, 'destroy'])->middleware('throttle:orders');
    });
});
