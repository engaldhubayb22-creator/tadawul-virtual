<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Closes the trading day by hand: today's prices become the close, every
 * dated market record moves back one day, and the engine opens today again
 * as a fresh session whose reference is that close.
 *
 * Money records (the ledger, loans, pledges) keep their real dates.
 */
class DayRoll
{
    /** Moments in time, moved back 24 hours in one pass: nothing is unique on them. */
    private const MOMENTS = [
        'trades' => ['executed_at'],
        'orders' => ['created_at', 'updated_at'],
        'news' => ['created_at'],
        'maker_alerts' => ['created_at', 'resolved_at'],
    ];

    /** Moments that are part of a key, moved in two steps. These tables are small. */
    private const KEYED_MOMENTS = ['candles' => 'bucket', 'maker_bars' => 'bucket'];

    /** Calendar days, moved back one day in two steps: they are part of a key. */
    private const DAYS = [
        'daily_stats' => 'trade_date',
        'maker_days' => 'trade_date',
        'maker_alerts' => 'day',
        'companies' => 'listed_at',
    ];

    /** Far enough that a row in transit never lands on another row's key. */
    private const AWAY = 200000;

    /** Ask for the day to be closed; the scheduler does it within a minute. */
    public static function request(): void
    {
        self::put('day_roll_pending', true);
    }

    public static function pending(): bool
    {
        return (bool) json_decode(DB::table('settings')->where('key', 'day_roll_pending')->value('value') ?? 'false');
    }

    /** What the last closing did, or null. */
    public static function last(): ?array
    {
        $raw = DB::table('settings')->where('key', 'day_roll_done')->value('value');

        return $raw ? json_decode($raw, true) : null;
    }

    private static function put(string $key, mixed $value): void
    {
        DB::statement('INSERT INTO settings (key, value) VALUES (?, ?::jsonb)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()', [$key, json_encode($value)]);
    }

    /** @return array{closed: int, day: string, seconds: float} */
    public static function run(): array
    {
        $started = microtime(true);
        $result = DB::transaction(function () {
            // Hold the engine off these tables for the whole move, rather than
            // meeting it row by row. Give up quickly if it is mid-batch; the
            // caller tries again.
            DB::statement("SET LOCAL lock_timeout = '10s'");
            DB::statement('LOCK TABLE orders, trades, candles, daily_stats, news, maker_bars, maker_days, maker_alerts
                IN SHARE ROW EXCLUSIVE MODE');

            $tz = json_decode(DB::table('settings')->where('key', 'market_timezone')->value('value'));
            $today = DB::selectOne('SELECT (now() AT TIME ZONE ?)::date AS d', [$tz])->d;

            // Today closes at the last traded price.
            $closed = DB::update('
                UPDATE daily_stats d
                SET close = COALESCE(
                    (SELECT t.price FROM trades t WHERE t.company_id = d.company_id ORDER BY t.id DESC LIMIT 1),
                    d.prev_close)
                WHERE d.close IS NULL');

            foreach (self::MOMENTS as $table => $columns) {
                $set = implode(', ', array_map(fn ($c) => "$c = $c - interval '1 day'", $columns));
                DB::update("UPDATE $table SET $set");
            }
            // Two steps, so no row passes through a key another row still holds.
            foreach (self::KEYED_MOMENTS as $table => $column) {
                foreach ([-self::AWAY, self::AWAY - 1] as $days) {
                    DB::update("UPDATE $table SET $column = $column + make_interval(days => $days)");
                }
            }
            foreach (self::DAYS as $table => $column) {
                foreach ([-self::AWAY, self::AWAY - 1] as $days) {
                    DB::update("UPDATE $table SET $column = $column + ($days) WHERE $column IS NOT NULL");
                }
            }

            // The engine sees this change and opens today again: it expires
            // yesterday's day orders, sets new limits and rebuilds its books.
            DB::statement("
                INSERT INTO settings (key, value) VALUES ('day_roll', to_jsonb(1))
                ON CONFLICT (key) DO UPDATE SET value = to_jsonb((settings.value #>> '{}')::bigint + 1), updated_at = now()");

            return ['closed' => $closed, 'day' => $today];
        });
        $result['seconds'] = round(microtime(true) - $started, 1);
        self::put('day_roll_pending', false);
        self::put('day_roll_done', $result + ['at' => now()->toIso8601String()]);

        return $result;
    }
}
