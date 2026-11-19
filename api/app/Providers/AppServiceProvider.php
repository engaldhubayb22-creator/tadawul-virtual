<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Order entry and cancels: fast enough for one-click trading, slow
        // enough that one account cannot flood the book.
        RateLimiter::for('orders', fn (Request $request) => [
            Limit::perSecond(20)->by('orders-s:'.$request->user()?->id),
            Limit::perMinute(600)->by('orders-m:'.$request->user()?->id),
        ]);
    }
}
