<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Margin calls and liquidations. Needs `php artisan schedule:work` (or cron) running.
\Illuminate\Support\Facades\Schedule::command('margin:check')->everyMinute()->withoutOverlapping();

// The market maker's analysis and plan. In shadow mode it places no orders.
\Illuminate\Support\Facades\Schedule::command('maker:analyze')->everyFiveMinutes()->withoutOverlapping();

// Closing the trading day by hand, when the admin asked for it.
\Illuminate\Support\Facades\Schedule::command('market:roll-day')->everyMinute()->withoutOverlapping();
