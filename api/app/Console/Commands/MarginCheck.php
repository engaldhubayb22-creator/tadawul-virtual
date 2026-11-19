<?php

namespace App\Console\Commands;

use App\Services\Financing;
use Illuminate\Console\Command;

class MarginCheck extends Command
{
    protected $signature = 'margin:check';

    protected $description = 'Margin calls, liquidations and overdue financings (runs every minute)';

    public function handle(): int
    {
        $done = Financing::monitor();
        if (array_sum($done) > 0) {
            $this->info(json_encode($done));
        }

        return self::SUCCESS;
    }
}
