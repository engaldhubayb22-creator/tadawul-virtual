<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\FinancingController;
use App\Http\Controllers\Api\ForumController;
use App\Http\Controllers\Api\IpoController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PledgeController;
use App\Http\Middleware\AdminOnly;
use Illuminate\Support\Facades\Route;

// The web app and the mobile app both use these routes.
Route::prefix('v1')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::get('market', [MarketController::class, 'index']);
    Route::get('market-trades', [MarketController::class, 'allTrades']);
    Route::get('market/{symbol}/profile', [MarketController::class, 'profile']);
    Route::get('market/{symbol}/history', [MarketController::class, 'history']);
    Route::get('news', [MarketController::class, 'news']);
    Route::get('leaderboard', [MarketController::class, 'leaderboard']);
    Route::get('ipos', [IpoController::class, 'index']);
    Route::get('chat/{room}', [CommunityController::class, 'chatHistory']);
    Route::get('forum/boards', [ForumController::class, 'index']);
    Route::get('forum', [ForumController::class, 'threads']);
    Route::get('forum/{id}', [ForumController::class, 'thread'])->whereNumber('id');
    Route::get('market/{symbol}/holders', [MarketController::class, 'holders']);
    Route::get('market/{symbol}/depth', [MarketController::class, 'depth']);
    Route::get('market/{symbol}/trades', [MarketController::class, 'trades']);
    Route::get('market/{symbol}/candles', [MarketController::class, 'candles']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('portfolio', [OrderController::class, 'portfolio']);
        Route::get('trades', [OrderController::class, 'trades']);
        Route::get('bank', [OrderController::class, 'bank']);
        Route::get('pledges', [PledgeController::class, 'show']);
        Route::post('pledges', [PledgeController::class, 'store'])->middleware('throttle:orders');
        Route::post('pledges/{id}/repay', [PledgeController::class, 'repay'])->middleware('throttle:orders');
        Route::post('ipos/{id}/subscribe', [IpoController::class, 'subscribe']);
        Route::post('chat/{room}', [CommunityController::class, 'chatSend'])->middleware('throttle:chat');
        Route::delete('chat/messages/{id}', [CommunityController::class, 'chatDelete']);
        Route::put('chat/mute/{alias}', [CommunityController::class, 'mute']);
        Route::post('forum', [ForumController::class, 'createThread'])->middleware('throttle:forum');
        Route::post('forum/{id}/posts', [ForumController::class, 'reply'])->middleware('throttle:forum');
        Route::put('forum/{id}', [ForumController::class, 'updateThread'])->whereNumber('id');
        Route::delete('forum/{id}', [ForumController::class, 'deleteThread'])->whereNumber('id');
        Route::put('forum/posts/{id}', [ForumController::class, 'updatePost']);
        Route::delete('forum/posts/{id}', [ForumController::class, 'deletePost']);
        Route::put('forum/signature', [ForumController::class, 'signature']);
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
            Route::put('companies/{symbol}/fundamentals', [AdminController::class, 'fundamentals']);
            Route::post('news', [AdminController::class, 'news']);
            Route::get('users', [AdminController::class, 'users']);
            Route::put('users/{id}/status', [AdminController::class, 'userStatus']);
            Route::put('users/{id}/role', [AdminController::class, 'userRole']);
            Route::put('users/{id}/password', [AdminController::class, 'userPassword']);
            Route::post('users/{id}/balance', [AdminController::class, 'userBalance']);
            Route::get('users/{id}/positions', [AdminController::class, 'userPositions']);
            Route::post('users/{id}/shares', [AdminController::class, 'userShares']);
            Route::post('shares/transfer', [AdminController::class, 'transferShares']);
            Route::get('companies', [AdminController::class, 'companies']);
            Route::post('forum/boards', [ForumController::class, 'createBoard']);
            Route::put('forum/boards/{id}', [ForumController::class, 'updateBoard']);
            Route::delete('forum/boards/{id}', [ForumController::class, 'deleteBoard']);
            Route::put('companies/{symbol}', [AdminController::class, 'companyUpdate']);
            Route::post('ipos', [IpoController::class, 'store']);
            Route::post('ipos/{id}/allocate', [IpoController::class, 'allocate']);
        });
    });
});
