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
    /** Columns holding a moment in time, moved back 24 hours. */
    private const MOMENTS = [
        'trades' => ['executed_at'],
        'orders' => ['created_at', 'updated_at'],
        'news' => ['created_at'],
        'candles' => ['bucket'],
        'maker_bars' => ['bucket'],
        'maker_alerts' => ['created_at', 'resolved_at'],
    ];

    /** Columns holding a calendar day, moved back one day. */
    private const DAYS = [
        'daily_stats' => 'trade_date',
        'maker_days' => 'trade_date',
        'maker_alerts' => 'day',
        'companies' => 'listed_at',
    ];

    /** Far enough that a row in transit never lands on another row's key. */
    private const AWAY = 200000;

    /** @return array{closed: int, day: string} */
    public static function run(): array
    {
        return DB::transaction(function () {
            $tz = json_decode(DB::table('settings')->where('key', 'market_timezone')->value('value'));
            $today = DB::selectOne('SELECT (now() AT TIME ZONE ?)::date AS d', [$tz])->d;

            // Today closes at the last traded price.
            $closed = DB::update('
                UPDATE daily_stats d
                SET close = COALESCE(
                    (SELECT t.price FROM trades t WHERE t.company_id = d.company_id ORDER BY t.id DESC LIMIT 1),
                    d.prev_close)
                WHERE d.close IS NULL');

            // Two steps, so no row passes through a day another row still holds.
            foreach (self::MOMENTS as $table => $columns) {
                foreach ([-self::AWAY, self::AWAY - 1] as $days) {
                    $set = implode(', ', array_map(fn ($c) => "$c = $c + make_interval(days => $days)", $columns));
                    DB::update("UPDATE $table SET $set");
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
    }
}
