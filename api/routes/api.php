<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\FinancingController;
use App\Http\Controllers\Api\IpoController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Middleware\AdminOnly;
use Illuminate\Support\Facades\Route;

// The web app and the mobile app both use these routes.
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::get('market', [MarketController::class, 'index']);
    Route::get('news', [MarketController::class, 'news']);
    Route::get('leaderboard', [MarketController::class, 'leaderboard']);
    Route::get('ipos', [IpoController::class, 'index']);
    Route::get('chat/{room}', [CommunityController::class, 'chatHistory']);
    Route::get('forum', [CommunityController::class, 'threads']);
    Route::get('forum/{id}', [CommunityController::class, 'thread']);
    Route::get('market/{symbol}/holders', [MarketController::class, 'holders']);
    Route::get('market/{symbol}/depth', [MarketController::class, 'depth']);
    Route::get('market/{symbol}/trades', [MarketController::class, 'trades']);
    Route::get('market/{symbol}/candles', [MarketController::class, 'candles']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('portfolio', [OrderController::class, 'portfolio']);
        Route::post('ipos/{id}/subscribe', [IpoController::class, 'subscribe']);
        Route::post('chat/{room}', [CommunityController::class, 'chatSend'])->middleware('throttle:chat');
        Route::delete('chat/messages/{id}', [CommunityController::class, 'chatDelete']);
        Route::put('chat/mute/{alias}', [CommunityController::class, 'mute']);
        Route::post('forum', [CommunityController::class, 'createThread'])->middleware('throttle:forum');
        Route::post('forum/{id}/posts', [CommunityController::class, 'reply'])->middleware('throttle:forum');
        Route::delete('forum/{id}', [CommunityController::class, 'deleteThread']);
        Route::get('financing', [FinancingController::class, 'show']);
        Route::post('financing', [FinancingController::class, 'store']);
        Route::post('financing/repay', [FinancingController::class, 'repay']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders');
        Route::delete('orders/{id}', [OrderController::class, 'destroy'])->middleware('throttle:orders');

        Route::prefix('admin')->middleware(AdminOnly::class)->group(function () {
            Route::get('overview', [AdminController::class, 'overview']);
            Route::put('settings', [AdminController::class, 'settings']);
            Route::put('bots/{symbol}', [AdminController::class, 'bot']);
            Route::put('companies/{symbol}/status', [AdminController::class, 'companyStatus']);
            Route::post('news', [AdminController::class, 'news']);
            Route::post('ipos', [IpoController::class, 'store']);
            Route::post('ipos/{id}/allocate', [IpoController::class, 'allocate']);
        });
    });
});
