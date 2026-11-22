<?php

namespace App\Console\Commands;

use App\Services\DayRoll;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class MarketRollDay extends Command
{
    protected $signature = 'market:roll-day {--now : close the day even if the admin did not ask}';

    protected $description = 'Close the trading day by hand when the admin asked: today becomes yesterday and a new session opens';

    public function handle(): int
    {
        if (! $this->option('now') && ! DayRoll::pending()) {
            return self::SUCCESS;
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                $done = DayRoll::run();
                $this->info("day {$done['day']} closed in {$done['seconds']}s");

                return self::SUCCESS;
            } catch (QueryException $e) {
                // The engine held the tables, or the two met in a deadlock: nothing moved.
                if ($attempt === 5) {
                    $this->error('could not close the day: '.$e->getMessage());

                    return self::FAILURE;
                }
                sleep(2);
            }
        }
    }
}
