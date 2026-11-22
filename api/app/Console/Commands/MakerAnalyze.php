<?php

namespace App\Console\Commands;

use App\Services\Ledger;
use App\Services\Maker\Brain;
use Illuminate\Console\Command;

class MakerAnalyze extends Command
{
    protected $signature = 'maker:analyze';

    protected $description = 'Market maker analysis: read the market and every share, and write the maker\'s plan (runs every five minutes)';

    public function handle(): int
    {
        if (Ledger::setting('maker_brain') === 'off') {
            $this->info('maker brain is off');

            return self::SUCCESS;
        }
        $done = Brain::run();
        $this->info("companies: {$done['companies']}, market: {$done['market']}, new alerts: {$done['alerts']}");

        return self::SUCCESS;
    }
}
