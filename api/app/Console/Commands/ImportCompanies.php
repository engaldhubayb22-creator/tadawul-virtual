<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads listed companies from a CSV built from the exchange's published
 * figures. Columns (header row required):
 *
 *   symbol,name,sector,issued_shares,free_float_shares,price,equity,net_income
 *
 * price is in riyals (27.35); equity and net_income are in riyals and may be
 * left empty. Free-float shares are placed with the liquidity fund so they can
 * trade; the rest of the issued shares are founder shares and never trade.
 *
 * Run it while the engine is stopped: it writes positions directly.
 */
class ImportCompanies extends Command
{
    protected $signature = 'market:import {file : CSV file} {--dry-run : Check the file and change nothing}';

    protected $description = 'Import companies with their issued and free-float shares from a CSV file';

    private const COLUMNS = ['symbol', 'name', 'sector', 'issued_shares', 'free_float_shares', 'price', 'equity', 'net_income'];

    public function handle(): int
    {
        try {
            $rows = $this->read($this->argument('file'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info(count($rows).' companies are valid. Nothing was changed.');

            return self::SUCCESS;
        }

        $maker = DB::table('users')->where('alias', 'mm-bot')->value('id');
        $noise = DB::table('users')->where('alias', 'noise-bot')->value('id');
        if (! $maker) {
            $this->error('The liquidity fund account (mm-bot) does not exist. Run the seed tool first.');

            return self::FAILURE;
        }

        try {
            [$created, $updated] = DB::transaction(function () use ($rows, $maker, $noise) {
                $created = $updated = 0;
                foreach ($rows as $r) {
                    $this->apply($r, $maker, $noise) ? $created++ : $updated++;
                }

                return [$created, $updated];
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }
        $this->info("Imported: $created new, $updated updated.");

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function read(string $file): array
    {
        if (! is_readable($file) || ($h = fopen($file, 'r')) === false) {
            throw new RuntimeException("Cannot read $file.");
        }
        $header = array_map(fn ($c) => strtolower(trim((string) $c, " \t\u{FEFF}")), fgetcsv($h) ?: []);
        if ($header !== self::COLUMNS) {
            throw new RuntimeException('The header row must be: '.implode(',', self::COLUMNS));
        }
        $rows = [];
        $seen = [];
        for ($line = 2; ($cells = fgetcsv($h)) !== false; $line++) {
            if ($cells === [null]) {
                continue;
            }
            if (count($cells) !== count(self::COLUMNS)) {
                throw new RuntimeException("Line $line: expected ".count(self::COLUMNS).' columns.');
            }
            $r = array_combine(self::COLUMNS, array_map('trim', $cells));
            $int = function (string $key, bool $optional = false) use ($r, $line) {
                $v = str_replace(',', '', $r[$key]);
                if ($v === '' && $optional) {
                    return null;
                }
                if (! preg_match('/^-?\d+$/', $v)) {
                    throw new RuntimeException("Line $line: $key must be a whole number.");
                }

                return (int) $v;
            };
            if (! preg_match('/^\d{4}$/', $r['symbol']) || $r['name'] === '' || $r['sector'] === '') {
                throw new RuntimeException("Line $line: symbol must be four digits, and name and sector are required.");
            }
            if (isset($seen[$r['symbol']])) {
                throw new RuntimeException("Line $line: symbol {$r['symbol']} appears twice.");
            }
            $seen[$r['symbol']] = true;
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $r['price']) || (float) $r['price'] <= 0) {
                throw new RuntimeException("Line $line: price must be a positive amount in riyals.");
            }
            $issued = $int('issued_shares');
            $float = $int('free_float_shares');
            if ($issued <= 0 || $float <= 0 || $float > $issued) {
                throw new RuntimeException("Line $line: free float must be between 1 and the issued shares.");
            }
            $equity = $int('equity', true);
            $rows[] = [
                'symbol' => $r['symbol'], 'name' => $r['name'], 'sector' => $r['sector'],
                'issued' => $issued, 'float' => $float,
                'price' => (int) round((float) $r['price'] * 100),
                'equity' => $equity === null ? null : $equity * 100,
                'net_income' => ($n = $int('net_income', true)) === null ? null : $n * 100,
            ];
        }
        fclose($h);
        if ($rows === []) {
            throw new RuntimeException('The file has no companies.');
        }

        return $rows;
    }

    /** Returns true when the company was created, false when it was updated. */
    private function apply(array $r, int $maker, ?int $noise): bool
    {
        DB::table('sectors')->insertOrIgnore(['name' => $r['sector']]);
        $sector = DB::table('sectors')->where('name', $r['sector'])->value('id');
        // A company with no shares outside the market cannot be stored, so a
        // 100% free float keeps one founder share out of circulation.
        $float = min($r['float'], $r['issued'] - 1);
        $fields = [
            'name' => $r['name'], 'sector_id' => $sector,
            'shares_outstanding' => $r['issued'], 'founder_shares' => $r['issued'] - $float,
        ] + array_filter(['equity' => $r['equity'], 'net_income' => $r['net_income']], fn ($v) => $v !== null);

        $company = DB::table('companies')->where('symbol', $r['symbol'])->lockForUpdate()->first();
        if ($company === null) {
            $id = DB::table('companies')->insertGetId($fields + [
                'symbol' => $r['symbol'], 'ref_price' => $r['price'], 'tick_size' => self::tick($r['price']),
                'status' => 'trading', 'listed_at' => DB::raw("current_date - 30"),
            ]);
            $noiseQty = $noise ? min(1_000_000, intdiv($float, 20)) : 0;
            foreach ([[$maker, $float - $noiseQty], [$noise, $noiseQty]] as [$user, $qty]) {
                if ($user && $qty > 0) {
                    DB::table('positions')->insert(['user_id' => $user, 'company_id' => $id,
                        'qty' => $qty, 'cost_total' => $qty * $r['price']]);
                }
            }

            return true;
        }

        // Existing company: the price is left alone, and a change in free float
        // is taken from or added to the liquidity fund's holding.
        $delta = $float - ((int) $company->shares_outstanding - (int) $company->founder_shares);
        if ($delta !== 0) {
            $pos = DB::table('positions')->where(['user_id' => $maker, 'company_id' => $company->id])->lockForUpdate()->first();
            $free = $pos ? (int) $pos->qty - (int) $pos->reserved_qty : 0;
            if ($delta < 0 && $free < -$delta) {
                throw new RuntimeException("{$r['symbol']}: the free float falls by ".(-$delta)." shares but the liquidity fund has only $free free.");
            }
            $avg = $pos && $pos->qty > 0 ? intdiv((int) $pos->cost_total, (int) $pos->qty) : (int) $company->ref_price;
            DB::table('positions')->updateOrInsert(['user_id' => $maker, 'company_id' => $company->id], [
                'qty' => ($pos ? (int) $pos->qty : 0) + $delta,
                'cost_total' => max(0, ($pos ? (int) $pos->cost_total : 0) + $delta * $avg),
            ]);
        }
        DB::table('companies')->where('id', $company->id)->update($fields);

        return false;
    }

    /** Same price bands as the engine. */
    private static function tick(int $price): int
    {
        return match (true) {
            $price < 2500 => 1, $price < 5000 => 2, $price < 10000 => 5,
            $price < 25000 => 10, $price < 50000 => 20, default => 50,
        };
    }
}
