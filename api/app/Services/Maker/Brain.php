<?php

namespace App\Services\Maker;

use App\Services\Ledger;
use App\Services\Shares;
use Illuminate\Support\Facades\DB;

/**
 * The market maker's analysis. Every run it reads the market as a whole and
 * each share on its own, decides what kind of move each is in, applies the
 * fund's rules on top, and writes the orders the maker would place.
 *
 * It places nothing itself. In shadow mode the plan is only shown to the
 * admin; the maker's own process is what would act on it.
 */
class Brain
{
    /** Hourly bars needed before the reading means anything: the cloud needs 52 + 26, plus a few to compare against. */
    public const WARMUP = 82;

    public const REGIMES = ['up_strong', 'up_tired', 'range', 'down', 'down_tired', 'unclear', 'warming'];

    /** bid and ask size against the usual, how far from the price (in ATRs), and whether bids follow the price up. */
    private const TEMPLATES = [
        'up_strong' => ['bid' => 1.2, 'ask' => 0.8, 'bid_gap' => 0.25, 'ask_gap' => 0.75, 'chase' => true],
        'up_tired' => ['bid' => 0.6, 'ask' => 1.0, 'bid_gap' => 0.5, 'ask_gap' => 0.5, 'chase' => false],
        'range' => ['bid' => 1.0, 'ask' => 1.0, 'bid_gap' => 0.4, 'ask_gap' => 0.4, 'chase' => false],
        'down' => ['bid' => 0.7, 'ask' => 1.0, 'bid_gap' => 1.0, 'ask_gap' => 0.4, 'chase' => false],
        'down_tired' => ['bid' => 1.0, 'ask' => 0.9, 'bid_gap' => 0.6, 'ask_gap' => 0.5, 'chase' => false],
        'unclear' => ['bid' => 0.5, 'ask' => 0.5, 'bid_gap' => 0.6, 'ask_gap' => 0.6, 'chase' => false],
        'warming' => ['bid' => 1.0, 'ask' => 1.0, 'bid_gap' => 0.4, 'ask_gap' => 0.4, 'chase' => false],
    ];

    private const OPPOSITE = ['up_strong' => 'down', 'up_tired' => 'down_tired', 'down' => 'up_strong', 'down_tired' => 'up_tired'];

    private array $s;      // settings

    private ?int $fund;

    private string $tz;

    private string $today; // session date in the market's time zone

    /** One full pass. Returns how many companies were read and how many alerts were raised. */
    public static function run(): array
    {
        return (new self)->pass();
    }

    private function pass(): array
    {
        $this->s = [];
        foreach (['confirm_runs', 'reserve_bps', 'sell_cap_bps', 'wave_cap_bps', 'wave_days', 'wave_rise_bps',
            'accum_holder_bps', 'accum_volume_bps', 'accum_buy_bps'] as $k) {
            $this->s[$k] = (int) Ledger::setting('maker_'.$k);
        }
        $this->fund = Shares::fund();
        $this->tz = (string) (Ledger::setting('market_timezone') ?: 'Asia/Riyadh');
        $this->today = now($this->tz)->toDateString();
        $alertsBefore = DB::table('maker_alerts')->count();

        $this->syncBars(15);
        $this->syncBars(60);
        $hourly = $this->loadBars(60, 150);
        $quarter = $this->loadBars(15, 60);

        $companies = DB::select(<<<'SQL'
            SELECT c.id, c.symbol, c.name, c.status, c.shares_outstanding - c.founder_shares AS free_float, c.maker_reserve_open,
                   COALESCE((SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
                            (SELECT prev_close FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1),
                            c.ref_price) AS last,
                   COALESCE(b.spread_bps, 10) AS spread_bps, COALESCE(b.levels, 5) AS levels,
                   COALESCE(b.qty_min, 100) AS qty_min, COALESCE(b.qty_max, 2000) AS qty_max,
                   COALESCE(p.qty, 0) AS fund_qty,
                   d.low_limit, d.high_limit
            FROM companies c
            LEFT JOIN bot_configs b ON b.company_id = c.id
            LEFT JOIN positions p ON p.company_id = c.id AND p.user_id = :fund
            LEFT JOIN daily_stats d ON d.company_id = c.id AND d.trade_date = :today
            WHERE c.status IN ('trading', 'halted')
            ORDER BY c.symbol
            SQL, ['fund' => $this->fund ?? 0, 'today' => $this->today]);

        // The market first: its reading is the context for every share.
        $index = $this->indexBars($companies, $hourly);
        $market = $this->readMarket($index, $companies, $hourly);

        $flows = $this->flows();
        $buyers = $this->memberBuyers();
        $holders = $this->bigHolders();
        $sessions = $this->sessions();
        $news = $this->recentNews();
        $overrides = $this->overrides();
        $previous = DB::table('maker_state')->get()->keyBy('company_id');
        $this->openDays($companies);
        $starts = DB::table('maker_days')->where('trade_date', $this->today)->pluck('start_qty', 'company_id');

        foreach ($companies as $c) {
            $bars = $hourly[$c->id] ?? [];
            $reading = $this->read($bars);
            $reading['m15'] = $this->timing($quarter[$c->id] ?? []);
            $reading['relation'] = $this->relation($bars, $index);

            $flow = $flows[$c->id] ?? ['volume' => 0, 'member_buy' => 0, 'member_sell' => 0, 'buyers' => 0, 'sellers' => 0,
                'fund_sold' => 0, 'fund_bought' => 0, 'vwap' => null];
            $flow['member_net'] = $flow['member_buy'] - $flow['member_sell'];
            $flow['member_share_bps'] = $flow['volume'] > 0 ? intdiv(max($flow['member_buy'], $flow['member_sell']) * 10000, $flow['volume']) : 0;
            $flow['member_backed'] = $flow['member_net'] > 0 && $flow['member_share_bps'] >= 1000;

            // A new reading has to last a few runs before the maker acts on it, so it does not flip with every bar.
            $prev = $previous[$c->id] ?? null;
            $prevData = $prev ? json_decode($prev->data, true) : [];
            [$auto, $pending] = $this->settle($reading['regime'], $prev->auto_regime ?? null, $prevData['pending'] ?? null);

            $accumulation = $this->accumulation($c, $flow, $buyers[$c->id] ?? [], $holders[$c->id] ?? []);
            $wave = $this->wave($sessions[$c->id] ?? [], $news[$c->id] ?? []);
            $override = $overrides[$c->id] ?? null;
            $regime = $this->applyOverride($auto, $override);

            $plan = $this->plan($c, $regime, $reading, $flow, $market, $accumulation, $wave, (int) ($starts[$c->id] ?? $c->fund_qty));

            $data = [
                'bars' => count($bars), 'last' => (int) $c->last, 'reading' => $reading, 'flow' => $flow,
                'accumulation' => $accumulation, 'wave' => $wave, 'pending' => $pending,
                'override' => $override ? ['mode' => $override->mode, 'expires_at' => $override->expires_at] : null,
            ];
            DB::table('maker_state')->upsert([[
                'company_id' => $c->id, 'regime' => $regime, 'auto_regime' => $auto,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'plan' => json_encode($plan, JSON_UNESCAPED_UNICODE),
                'reason' => $plan['reason'], 'computed_at' => DB::raw('now()'),
            ]], ['company_id'], ['regime', 'auto_regime', 'data', 'plan', 'reason', 'computed_at']);

            $this->alerts($c, $reading, $accumulation, $wave, $buyers[$c->id] ?? [], $holders[$c->id] ?? [], $flow);
        }

        return ['companies' => count($companies), 'alerts' => DB::table('maker_alerts')->count() - $alertsBefore, 'market' => $market['regime']];
    }

    // ------------------------------------------------------------ bars

    /** Bring the bars of one width up to date from the minute candles; only recent minutes are re-read. */
    private function syncBars(int $width): void
    {
        $since = DB::table('maker_bars')->where('width', $width)->max('bucket');
        DB::statement(<<<SQL
            INSERT INTO maker_bars (company_id, width, bucket, open, high, low, close, volume)
            SELECT company_id, $width, b, (array_agg(open ORDER BY bucket))[1], max(high), min(low),
                   (array_agg(close ORDER BY bucket DESC))[1], sum(volume)
            FROM (SELECT *, date_bin(interval '$width minutes', bucket, TIMESTAMPTZ '2000-01-01 00:00:00+00') AS b
                  FROM candles WHERE interval = '1m' AND bucket >= ?::timestamptz - interval '$width minutes') x
            GROUP BY company_id, b
            ON CONFLICT (company_id, width, bucket) DO UPDATE
            SET open = EXCLUDED.open, high = EXCLUDED.high, low = EXCLUDED.low, close = EXCLUDED.close, volume = EXCLUDED.volume
            SQL, [$since ?? '1970-01-01 00:00:00+00']);
    }

    /** The newest $limit bars per company, oldest first. @return array<int, list<object>> */
    private function loadBars(int $width, int $limit): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT company_id, bucket, open, high, low, close, volume FROM (
                SELECT *, row_number() OVER (PARTITION BY company_id ORDER BY bucket DESC) AS rn
                FROM maker_bars WHERE width = ? AND bucket > now() - interval '60 days') x
            WHERE rn <= ? ORDER BY company_id, bucket
            SQL, [$width, $limit]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->company_id][] = $r;
        }

        return $out;
    }

    /**
     * The market index as hourly bars: every company's price weighted by its
     * free float. A company with no trade in an hour carries its last price.
     */
    private function indexBars(array $companies, array $hourly): array
    {
        $buckets = [];
        foreach ($hourly as $bars) {
            foreach ($bars as $b) {
                $buckets[$b->bucket] = true;
            }
        }
        ksort($buckets);
        $index = [];
        foreach (array_keys($buckets) as $bucket) {
            $index[$bucket] = ['bucket' => $bucket, 'high' => 0.0, 'low' => 0.0, 'close' => 0.0];
        }
        foreach ($companies as $c) {
            $bars = $hourly[$c->id] ?? [];
            if ($bars === []) {
                continue;
            }
            $byBucket = [];
            foreach ($bars as $b) {
                $byBucket[$b->bucket] = $b;
            }
            $carry = (float) $bars[0]->open; // before its first bar a company stands at its first price
            foreach ($index as $bucket => &$row) {
                if (isset($byBucket[$bucket])) {
                    $b = $byBucket[$bucket];
                    $row['high'] += $c->free_float * $b->high;
                    $row['low'] += $c->free_float * $b->low;
                    $carry = (float) $b->close;
                } else {
                    $row['high'] += $c->free_float * $carry;
                    $row['low'] += $c->free_float * $carry;
                }
                $row['close'] += $c->free_float * $carry;
            }
            unset($row);
        }

        // Scale to 1,000 at the first bar: only the shape matters, and the numbers stay readable.
        $index = array_values($index);
        $base = $index[0]['close'] ?? 0;
        if ($base > 0) {
            foreach ($index as &$row) {
                foreach (['high', 'low', 'close'] as $k) {
                    $row[$k] = $row[$k] / $base * 1000;
                }
            }
            unset($row);
        }

        return $index;
    }

    // ------------------------------------------------------------ reading

    /**
     * Read one series of hourly bars: the indicators, and from them the kind
     * of move. The kind comes first; which indicator matters depends on it.
     */
    private function read(array $bars): array
    {
        $n = count($bars);
        if ($n < self::WARMUP) {
            return ['regime' => 'warming', 'ready_in' => self::WARMUP - $n];
        }
        $h = array_map(fn ($b) => (float) (is_array($b) ? $b['high'] : $b->high), $bars);
        $l = array_map(fn ($b) => (float) (is_array($b) ? $b['low'] : $b->low), $bars);
        $c = array_map(fn ($b) => (float) (is_array($b) ? $b['close'] : $b->close), $bars);
        $i = $n - 1;

        $ema20 = Indicators::ema($c, 20);
        $ema50 = Indicators::ema($c, 50);
        $atr = Indicators::atr($h, $l, $c)[$i] ?: max(1.0, $c[$i] * 0.001);
        $adx = Indicators::adx($h, $l, $c)[$i] ?? 0.0;
        $macd = Indicators::macd($c);
        $rsi = Indicators::rsi($c);
        $stoch = Indicators::stochastic($h, $l, $c);
        $cloud = Indicators::ichimoku($h, $l);
        $top = max($cloud['span_a'][$i], $cloud['span_b'][$i]);
        $bottom = min($cloud['span_a'][$i], $cloud['span_b'][$i]);

        // Slope of the averages over five bars, in ATRs, so it means the same on a 10 riyal share and a 500 riyal one.
        $slope20 = ($ema20[$i] - $ema20[$i - 5]) / $atr;
        $slope50 = ($ema50[$i] - $ema50[$i - 5]) / $atr;
        $position = $c[$i] > $top ? 'above' : ($c[$i] < $bottom ? 'below' : 'inside');
        $hist = $macd['hist'];
        $fading = $hist[$i] < $hist[$i - 1] && $hist[$i - 1] < $hist[$i - 2];   // momentum weakening
        $building = $hist[$i] > $hist[$i - 1] && $hist[$i - 1] > $hist[$i - 2];

        $up = $ema20[$i] > $ema50[$i] && $slope20 > 0.1 && $slope50 >= 0;
        $down = $ema20[$i] < $ema50[$i] && $slope20 < -0.1 && $slope50 <= 0;
        $regime = match (true) {
            $adx < 20 => 'range',   // no trend to speak of, whatever the averages say
            $up && $position === 'above' => ($fading && $c[$i] >= $c[$i - 3]) ? 'up_tired' : 'up_strong',
            $down && $position === 'below' => ($building && $c[$i] <= $c[$i - 3]) ? 'down_tired' : 'down',
            default => 'unclear',   // inside the cloud, or the averages and the cloud disagree
        };

        return [
            'regime' => $regime,
            'ema20' => round($ema20[$i], 2), 'ema50' => round($ema50[$i], 2),
            'slope20' => round($slope20, 2), 'slope50' => round($slope50, 2),
            'cloud' => ['position' => $position, 'top' => round($top, 2), 'bottom' => round($bottom, 2)],
            'macd' => ['line' => round($macd['macd'][$i], 2), 'signal' => round($macd['signal'][$i], 2), 'hist' => round($hist[$i], 2),
                'momentum' => $building ? 'building' : ($fading ? 'fading' : 'steady')],
            'rsi' => round($rsi[$i], 1),
            'stoch' => ['k' => round($stoch['k'][$i], 1), 'd' => round($stoch['d'][$i], 1)],
            'adx' => round($adx, 1), 'atr' => round($atr, 2), 'price' => round($c[$i], 2),
            'patterns' => $this->patterns($c, $ema20, $ema50, $rsi, $cloud, $atr),
        ];
    }

    /** The 15-minute view, used to time changes to standing orders. */
    private function timing(array $bars): ?array
    {
        if (count($bars) < 35) {
            return null;
        }
        $c = array_map(fn ($b) => (float) $b->close, $bars);
        $h = array_map(fn ($b) => (float) $b->high, $bars);
        $l = array_map(fn ($b) => (float) $b->low, $bars);
        $i = count($c) - 1;

        return [
            'rsi' => round(Indicators::rsi($c)[$i], 1),
            'hist' => round(Indicators::macd($c)['hist'][$i], 2),
            'atr' => (int) max(1, round(Indicators::atr($h, $l, $c)[$i])),
        ];
    }

    /** How closely the share has moved with the market lately, measured on hourly returns. It is re-measured every run. */
    private function relation(array $bars, array $index): array
    {
        $market = [];
        foreach ($index as $row) {
            $market[$row['bucket']] = $row['close'];
        }
        $stock = $mkt = [];
        for ($i = max(1, count($bars) - 100); $i < count($bars); $i++) {
            $a = $bars[$i - 1];
            $b = $bars[$i];
            if (isset($market[$a->bucket], $market[$b->bucket]) && $a->close > 0 && $market[$a->bucket] > 0) {
                $stock[] = $b->close / $a->close - 1;
                $mkt[] = $market[$b->bucket] / $market[$a->bucket] - 1;
            }
        }
        $r = Indicators::relation($stock, $mkt);

        return [
            'beta' => $r['beta'] === null ? null : round($r['beta'], 2),
            'corr' => $r['corr'] === null ? null : round($r['corr'], 2),
            'follows_market' => $r['corr'] !== null && $r['corr'] >= 0.5,
        ];
    }

    private function readMarket(array $index, array $companies, array $hourly): array
    {
        $reading = $this->read($index);
        $above = $counted = 0;
        foreach ($companies as $c) {
            $bars = $hourly[$c->id] ?? [];
            if (count($bars) >= 20) {
                $close = array_map(fn ($b) => (float) $b->close, $bars);
                $counted++;
                $above += end($close) > Indicators::ema($close, 20)[count($close) - 1] ? 1 : 0;
            }
        }
        $reading['breadth_bps'] = $counted ? intdiv($above * 10000, $counted) : null;
        unset($reading['patterns']);

        $prev = DB::table('maker_market')->where('id', 1)->first();
        [$auto, $pending] = $this->settle($reading['regime'], $prev->auto_regime ?? null, $prev ? (json_decode($prev->data, true)['pending'] ?? null) : null);
        $override = $this->overrides()[0] ?? null;
        $regime = $this->applyOverride($auto, $override);
        $data = ['reading' => $reading, 'pending' => $pending, 'bars' => count($index),
            'override' => $override ? ['mode' => $override->mode, 'expires_at' => $override->expires_at] : null];
        DB::table('maker_market')->upsert([[
            'id' => 1, 'regime' => $regime, 'auto_regime' => $auto, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'computed_at' => DB::raw('now()'),
        ]], ['id'], ['regime', 'auto_regime', 'data', 'computed_at']);

        return ['regime' => $regime];
    }

    /** Keep the current reading until a new one has shown up $confirm_runs times in a row. @return array{0:string,1:?array} */
    private function settle(string $seen, ?string $current, ?array $pending): array
    {
        $need = max(1, $this->s['confirm_runs']);
        if ($current === null || $current === 'warming' || $seen === $current || $seen === 'warming') {
            return [$seen, null];
        }
        $runs = ($pending['regime'] ?? null) === $seen ? $pending['runs'] + 1 : 1;

        return $runs >= $need ? [$seen, null] : [$current, ['regime' => $seen, 'runs' => $runs]];
    }

    private function applyOverride(string $auto, ?object $override): string
    {
        return match ($override->mode ?? 'follow') {
            'oppose' => self::OPPOSITE[$auto] ?? $auto,
            'up' => 'up_strong',
            'down' => 'down',
            'range' => 'range',
            default => $auto,
        };
    }

    /** Active overrides keyed by company id; the market's is under 0. */
    private function overrides(): array
    {
        $out = [];
        foreach (DB::select('SELECT DISTINCT ON (COALESCE(company_id, 0)) * FROM maker_overrides ORDER BY COALESCE(company_id, 0), id DESC') as $o) {
            if (strtotime($o->expires_at) > time()) {
                $out[$o->company_id ?? 0] = $o;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ patterns

    /** Shapes forming on the chart that the admin may want to act on or against. */
    private function patterns(array $c, array $ema20, array $ema50, array $rsi, array $cloud, float $atr): array
    {
        $i = count($c) - 1;
        $found = [];
        $gap = fn (int $j) => $ema20[$j] - $ema50[$j];
        // A cross counts once the averages have really parted; in a flat market they cross all the time.
        if ($gap($i) > 0.1 * $atr && $gap($i - 3) <= 0) {
            $found[] = 'golden_cross';
        } elseif ($gap($i) < -0.1 * $atr && $gap($i - 3) >= 0) {
            $found[] = 'death_cross';
        } elseif (abs($gap($i)) < 0.2 * $atr && abs($gap($i - 3)) > 0.4 * $atr) {
            $found[] = $gap($i) < 0 ? 'cross_near_up' : 'cross_near_down';
        }
        $edge = fn (int $j, string $f) => $f($cloud['span_a'][$j], $cloud['span_b'][$j]);
        if ($c[$i] > $edge($i, 'max') && $c[$i - 3] <= $edge($i - 3, 'max')) {
            $found[] = 'cloud_break_up';
        } elseif ($c[$i] < $edge($i, 'min') && $c[$i - 3] >= $edge($i - 3, 'min')) {
            $found[] = 'cloud_break_down';
        }
        // Divergence: the price makes a new extreme while the RSI does not.
        $recentHigh = max(array_slice($c, -5));
        $earlierHigh = max(array_slice($c, -25, 20));
        $recentLow = min(array_slice($c, -5));
        $earlierLow = min(array_slice($c, -25, 20));
        if ($recentHigh > $earlierHigh && max(array_slice($rsi, -5)) < max(array_slice($rsi, -25, 20)) - 3) {
            $found[] = 'bearish_divergence';
        } elseif ($recentLow < $earlierLow && min(array_slice($rsi, -5)) > min(array_slice($rsi, -25, 20)) + 3) {
            $found[] = 'bullish_divergence';
        }
        // Double top or bottom: two extremes at about the same price with a clear swing between them.
        $first = array_slice($c, -40, 28);
        $second = array_slice($c, -10);
        $between = array_slice($c, -24, 14);
        if (abs(max($second) - max($first)) < 0.015 * max($first) && min($between) < 0.97 * max($first) && $c[$i] < 0.99 * max($second)) {
            $found[] = 'double_top';
        } elseif (abs(min($second) - min($first)) < 0.015 * min($first) && max($between) > 1.03 * min($first) && $c[$i] > 1.01 * min($second)) {
            $found[] = 'double_bottom';
        }

        return $found;
    }

    // ------------------------------------------------------------ flows and holders

    private function dayStart(): string
    {
        return now($this->tz)->startOfDay()->toIso8601String();
    }

    /** Today's trading per company: total, the members' part of it, and what the fund sold and bought. */
    private function flows(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT t.company_id, sum(t.qty) AS volume, sum(t.price * t.qty) AS value,
                   COALESCE(sum(t.qty) FILTER (WHERE bu.role <> 'bot'), 0) AS member_buy,
                   COALESCE(sum(t.qty) FILTER (WHERE su.role <> 'bot'), 0) AS member_sell,
                   count(DISTINCT t.buy_user_id) FILTER (WHERE bu.role <> 'bot') AS buyers,
                   count(DISTINCT t.sell_user_id) FILTER (WHERE su.role <> 'bot') AS sellers,
                   COALESCE(sum(t.qty) FILTER (WHERE t.sell_user_id = :f1), 0) AS fund_sold,
                   COALESCE(sum(t.qty) FILTER (WHERE t.buy_user_id = :f2), 0) AS fund_bought
            FROM trades t JOIN users bu ON bu.id = t.buy_user_id JOIN users su ON su.id = t.sell_user_id
            WHERE t.executed_at >= :start
            GROUP BY t.company_id
            SQL, ['f1' => $this->fund ?? 0, 'f2' => $this->fund ?? 0, 'start' => $this->dayStart()]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->company_id] = [
                'volume' => (int) $r->volume, 'member_buy' => (int) $r->member_buy, 'member_sell' => (int) $r->member_sell,
                'buyers' => (int) $r->buyers, 'sellers' => (int) $r->sellers,
                'fund_sold' => (int) $r->fund_sold, 'fund_bought' => (int) $r->fund_bought,
                'vwap' => $r->volume > 0 ? intdiv((int) $r->value, (int) $r->volume) : null,
            ];
        }

        return $out;
    }

    /** What each member bought net today, per company, biggest first. */
    private function memberBuyers(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT company_id, user_id, alias, sum(qty) AS net FROM (
                SELECT t.company_id, t.buy_user_id AS user_id, u.alias, t.qty FROM trades t JOIN users u ON u.id = t.buy_user_id
                WHERE t.executed_at >= :s1 AND u.role <> 'bot'
                UNION ALL
                SELECT t.company_id, t.sell_user_id, u.alias, -t.qty FROM trades t JOIN users u ON u.id = t.sell_user_id
                WHERE t.executed_at >= :s2 AND u.role <> 'bot') x
            GROUP BY company_id, user_id, alias HAVING sum(qty) > 0
            ORDER BY company_id, net DESC
            SQL, ['s1' => $this->dayStart(), 's2' => $this->dayStart()]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->company_id][] = ['user_id' => (int) $r->user_id, 'alias' => $r->alias, 'net' => (int) $r->net];
        }

        return $out;
    }

    /** Members holding 5% or more of a company's free float. */
    private function bigHolders(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.company_id, p.user_id, u.alias, p.qty,
                   p.qty * 10000 / (c.shares_outstanding - c.founder_shares) AS share_bps
            FROM positions p JOIN users u ON u.id = p.user_id JOIN companies c ON c.id = p.company_id
            WHERE u.role <> 'bot' AND p.qty * 20 >= c.shares_outstanding - c.founder_shares
            ORDER BY p.company_id, p.qty DESC
            SQL);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->company_id][] = ['user_id' => (int) $r->user_id, 'alias' => $r->alias, 'qty' => (int) $r->qty, 'share_bps' => (int) $r->share_bps];
        }

        return $out;
    }

    /** The last completed sessions per company, newest first. */
    private function sessions(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT company_id, trade_date, prev_close, close FROM (
                SELECT *, row_number() OVER (PARTITION BY company_id ORDER BY trade_date DESC) AS rn
                FROM daily_stats WHERE close IS NOT NULL AND trade_date < ?) x
            WHERE rn <= 12 ORDER BY company_id, trade_date DESC
            SQL, [$this->today]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->company_id][] = $r;
        }

        return $out;
    }

    /** Positive news per company from the last twelve days: what can explain a run. */
    private function recentNews(): array
    {
        $out = [];
        foreach (DB::select("SELECT company_id, created_at::date AS day FROM news
            WHERE company_id IS NOT NULL AND impact_bps > 0 AND created_at > now() - interval '12 days'") as $r) {
            $out[$r->company_id][] = $r->day;
        }

        return $out;
    }

    /** Record what the fund holds in each company the first time it is seen in a session. */
    private function openDays(array $companies): void
    {
        $rows = array_map(fn ($c) => ['company_id' => $c->id, 'trade_date' => $this->today, 'start_qty' => (int) $c->fund_qty], $companies);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('maker_days')->insertOrIgnore($chunk);
        }
    }

    // ------------------------------------------------------------ fund rules

    /** Someone is collecting the share: a member holds a large part of the float, or took a large part of today's volume. */
    private function accumulation(object $c, array $flow, array $buyers, array $holders): ?array
    {
        $by = null;
        foreach ($holders as $h) {
            if ($h['share_bps'] >= $this->s['accum_holder_bps']) {
                $by = ['alias' => $h['alias'], 'user_id' => $h['user_id'], 'why' => 'holder', 'share_bps' => $h['share_bps']];
                break;
            }
        }
        // A large part of a tiny day means nothing: the buyer must also have taken a visible slice of the float (0.1%).
        if ($by === null && $flow['volume'] > 0 && $buyers !== [] && $buyers[0]['net'] * 1000 >= $c->free_float) {
            $share = intdiv($buyers[0]['net'] * 10000, $flow['volume']);
            if ($share >= $this->s['accum_volume_bps']) {
                $by = ['alias' => $buyers[0]['alias'], 'user_id' => $buyers[0]['user_id'], 'why' => 'volume', 'share_bps' => $share];
            }
        }
        if ($by === null) {
            return null;
        }
        // Aim to buy 20% more than was sold today, with resting bids only. Missing the target is acceptable.
        $target = (int) ceil($flow['fund_sold'] * $this->s['accum_buy_bps'] / 10000);

        return $by + ['buy_target' => $target, 'bought' => $flow['fund_bought'], 'still_to_buy' => max(0, $target - $flow['fund_bought'])];
    }

    /**
     * An unexplained run: more than `wave_days` rising sessions in a row that
     * add up to `wave_rise_bps` or more, with no positive news during them.
     */
    private function wave(array $sessions, array $newsDays): ?array
    {
        $days = 0;
        $last = $sessions[0]->close ?? null;
        $base = null;
        $start = null;
        foreach ($sessions as $s) {
            if ($s->close <= $s->prev_close) {
                break;
            }
            $days++;
            $base = (int) $s->prev_close;
            $start = $s->trade_date;
        }
        if ($days <= $this->s['wave_days'] || $base === null) {
            return null;
        }
        $rise = intdiv(((int) $last - $base) * 10000, $base);
        if ($rise < $this->s['wave_rise_bps']) {
            return null;
        }
        foreach ($newsDays as $day) {
            if ($day >= $start) {
                return null; // the admin's own news explains it
            }
        }

        return ['days' => $days, 'rise_bps' => $rise, 'from' => $base, 'since' => $start];
    }

    // ------------------------------------------------------------ plan

    private static function tick(int $price): int
    {
        return match (true) {
            $price < 2500 => 1, $price < 5000 => 2, $price < 10000 => 5,
            $price < 25000 => 10, $price < 50000 => 20, default => 50,
        };
    }

    /**
     * The orders the maker would hold for one share: how many to bid for and
     * at what prices, how many to offer and at what prices, and when to move
     * them. The fund's rules are applied last and always win.
     */
    private function plan(object $c, string $regime, array $r, array $flow, array $market, ?array $accumulation, ?array $wave, int $startQty): array
    {
        $t = self::TEMPLATES[$regime];
        $why = [self::label($regime)];
        $last = (int) $c->last;
        $atr = (int) max(1, round($r['atr'] ?? $last / 200));
        $usual = (int) ($c->levels * ($c->qty_min + $c->qty_max) / 2);
        $bid = $t['bid'];
        $ask = $t['ask'];
        $chase = $t['chase'];

        if ($regime !== 'warming') {
            // RSI: in a strong climb above 70 the maker stops chasing. It does not start selling because of the number.
            if ($regime === 'up_strong' && $r['rsi'] > 70) {
                $chase = false;
                $bid = min($bid, 1.0);
                $why[] = 'RSI فوق 70: لا يطارد السعر';
            }
            // MACD: price still rising on fading momentum: fewer near bids, and they stop following.
            if ($regime === 'up_tired') {
                $why[] = 'الزخم يضعف: يقلل الطلبات القريبة ولا يرفعها';
            }
            // Stochastic carries the most weight when the share is going sideways.
            if ($regime === 'range') {
                $k = $r['stoch']['k'];
                $d = $r['stoch']['d'];
                if ($k < 25 && $k > $d) {
                    $bid = 1.0 + min(0.5, (25 - $k) / 50 + 0.1);
                    $why[] = 'قرب أسفل النطاق والزخم يتحسن: يزيد الطلبات تدريجيًا';
                } elseif ($k > 75 && $k < $d) {
                    $ask = 1.0 + min(0.5, ($k - 75) / 50 + 0.1);
                    $why[] = 'قرب أعلى النطاق والزخم يضعف: يزيد العروض تدريجيًا';
                }
            }
            if ($regime === 'unclear') {
                $why[] = 'إشارات متضاربة أو السعر داخل السحابة: يخفف الأوامر حتى تتضح الحركة';
            }
            // The market is context, not a command. A share that follows the market gives way to it,
            // unless it is strong on its own with members really buying it.
            $follows = $r['relation']['follows_market'] ?? false;
            if (in_array($market['regime'], ['down', 'down_tired'], true)) {
                if ($regime === 'up_strong' && $flow['member_backed']) {
                    $why[] = 'السوق نازل لكن السهم قوي ومدعوم بشراء الأعضاء: لا يخفض الطلبات';
                } elseif ($follows && $regime !== 'up_strong') {
                    $bid *= 0.7;
                    $why[] = 'السهم يتبع السوق والسوق نازل: يخفف الطلبات';
                }
            } elseif (in_array($market['regime'], ['up_strong', 'up_tired'], true) && in_array($regime, ['down', 'down_tired', 'unclear'], true)) {
                $chase = false;
                $why[] = 'السوق صاعد لكن السهم ضعيف: لا يشتريه بالغالي';
            }
        } else {
            $why[] = 'بانتظار '.$r['ready_in'].' شمعة ساعة ليكتمل التحليل';
        }

        $bidQty = (int) round($usual * $bid);
        $askQty = (int) round($usual * $ask);
        $bidCeiling = null;

        // --- fund rules, above any signal
        if ($accumulation !== null) {
            $bidQty = max($bidQty, $accumulation['still_to_buy']);
            $chase = false;
            $bidCeiling = $flow['vwap'];   // a fair price: no higher than today's average trade
            $why[] = 'تجميع ملحوظ من '.$accumulation['alias'].': '.($accumulation['buy_target'] > 0
                ? 'يستهدف شراء '.number_format($accumulation['buy_target']).' سهم بطلبات معروضة'
                : 'يشتري بطلبات معروضة فقط ولا يلحق السعر');
        }
        if ($wave !== null) {
            $askQty *= 2;
            $why[] = 'ارتفاع '.$wave['days'].' جلسات متتالية بمجموع '.round($wave['rise_bps'] / 100, 1).'% بلا خبر: يضاعف العرض على دفعات';
        }
        $reserve = (int) ceil($c->free_float * $this->s['reserve_bps'] / 10000);
        $sellable = $c->maker_reserve_open ? (int) $c->fund_qty : max(0, (int) $c->fund_qty - $reserve);
        $cap = intdiv($startQty * ($wave !== null ? $this->s['wave_cap_bps'] : $this->s['sell_cap_bps']), 10000);
        $left = max(0, $cap - $flow['fund_sold']);
        $wanted = $askQty;
        $askQty = min($askQty, $left, $sellable);
        if ($askQty < $wanted) {
            $why[] = match (true) {
                (int) $c->fund_qty === 0 => 'الصندوق لا يملك أسهمًا يعرضها',
                $left <= $sellable => 'سقف البيع اليومي يحدّ العرض',
                default => 'الاحتياطي المقفل يحدّ العرض',
            };
        }

        // --- prices
        $tick = self::tick($last);
        $step = max($tick, intdiv($last * $c->spread_bps, 10000 * $tick) * $tick);
        $low = $c->low_limit ?? 1;
        $high = $c->high_limit ?? PHP_INT_MAX;
        $bidTop = intdiv($last - max($tick, (int) round($t['bid_gap'] * $atr)), $tick) * $tick;
        if ($bidCeiling !== null) {
            $bidTop = min($bidTop, intdiv($bidCeiling, $tick) * $tick);
        }
        $askTop = (int) ceil(($last + max($tick, (int) round($t['ask_gap'] * $atr))) / $tick) * $tick;

        return [
            'bids' => self::ladder($bidTop, -$step, $bidQty, (int) $c->levels, $low, $high),
            'asks' => self::ladder($askTop, $step, $askQty, (int) $c->levels, $low, $high),
            'bid_qty' => $bidQty, 'ask_qty' => $askQty,
            // When standing orders are moved: when the price has travelled this far, or the reading changes.
            'requote' => ['after_move' => max($step, intdiv($r['m15']['atr'] ?? $atr, 2)), 'follow_price_up' => $chase],
            'fund' => ['holding' => (int) $c->fund_qty, 'start' => $startQty, 'reserve' => $reserve, 'reserve_open' => (bool) $c->maker_reserve_open,
                'sellable' => $sellable, 'cap' => $cap, 'sold' => $flow['fund_sold'], 'left' => $left, 'bought' => $flow['fund_bought']],
            'reason' => implode('. ', $why),
        ];
    }

    /** Spread a quantity over price levels going away from the price, inside the day's limits. */
    private static function ladder(int $top, int $step, int $qty, int $levels, int $low, int $high): array
    {
        $prices = [];
        for ($i = 0; $i < $levels; $i++) {
            $p = $top + $i * $step;
            if ($p >= $low && $p <= $high && $p > 0) {
                $prices[] = $p;
            }
        }
        if ($qty <= 0 || $prices === []) {
            return [];
        }
        $each = intdiv($qty, count($prices));
        $out = [];
        foreach ($prices as $i => $p) {
            $q = $each + ($i === 0 ? $qty - $each * count($prices) : 0);
            if ($q > 0) {
                $out[] = ['price' => $p, 'qty' => $q];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ alerts

    public const PATTERNS = [
        'golden_cross' => ['تقاطع ذهبي: المتوسط 20 قطع المتوسط 50 صعودًا', 'احتمال بداية مسار صاعد'],
        'death_cross' => ['تقاطع سلبي: المتوسط 20 قطع المتوسط 50 هبوطًا', 'احتمال بداية مسار هابط'],
        'cross_near_up' => ['المتوسطان يتقاربان من الأسفل', 'تقاطع ذهبي محتمل قريبًا'],
        'cross_near_down' => ['المتوسطان يتقاربان من الأعلى', 'تقاطع سلبي محتمل قريبًا'],
        'cloud_break_up' => ['السعر اخترق السحابة صعودًا', 'احتمال استمرار الصعود'],
        'cloud_break_down' => ['السعر كسر السحابة هبوطًا', 'احتمال استمرار الهبوط'],
        'bearish_divergence' => ['انحراف سلبي: قمة سعرية جديدة بزخم أضعف', 'احتمال تصحيح أو انعكاس هابط'],
        'bullish_divergence' => ['انحراف إيجابي: قاع سعري جديد بزخم أقوى', 'احتمال ارتداد صاعد'],
        'double_top' => ['قمة مزدوجة تتشكل', 'احتمال هبوط إن كُسر القاع بين القمتين'],
        'double_bottom' => ['قاع مزدوج يتشكل', 'احتمال صعود إن اختُرقت القمة بين القاعين'],
    ];

    private function alerts(object $c, array $r, ?array $accumulation, ?array $wave, array $buyers, array $holders, array $flow): void
    {
        foreach ($r['patterns'] ?? [] as $p) {
            [$title, $expect] = self::PATTERNS[$p];
            $this->alert('pattern_'.$p, $c->id, null, $c->name.': '.$title, ['expect' => $expect, 'pattern' => $p, 'price' => (int) $c->last]);
        }
        foreach ($holders as $h) {
            foreach ([2000, 1000, 500] as $level) {
                if ($h['share_bps'] >= $level) {
                    $this->alert('concentration_'.$level, $c->id, $h['user_id'],
                        $h['alias'].' يملك '.round($h['share_bps'] / 100, 1).'% من الأسهم الحرة في '.$c->name, $h);
                    break;
                }
            }
        }
        if ($accumulation !== null) {
            $this->alert('accumulation', $c->id, $accumulation['user_id'], 'تجميع ملحوظ في '.$c->name.' من '.$accumulation['alias'], $accumulation);
        }
        if ($wave !== null) {
            $this->alert('wave', $c->id, null, $c->name.': ارتفاع '.$wave['days'].' جلسات متتالية بمجموع '.round($wave['rise_bps'] / 100, 1).'% بلا خبر يفسّره', $wave);
        }
        // A possible group: three or more members each took a tenth or more of the day's volume, and most of it together.
        if ($flow['volume'] > 0) {
            $big = array_values(array_filter($buyers, fn ($b) => $b['net'] * 10 >= $flow['volume']));
            $together = array_sum(array_column($big, 'net'));
            if (count($big) >= 3 && $together * 2 >= $flow['volume'] && $together * 1000 >= $c->free_float) {
                $this->alert('group', $c->id, null, 'اشتباه مجموعة حسابات تجمّع '.$c->name,
                    ['members' => array_column($big, 'alias'), 'share_bps' => intdiv($together * 10000, $flow['volume'])]);
            }
        }
    }

    private function alert(string $type, ?int $companyId, ?int $userId, string $title, array $details): void
    {
        DB::statement(
            'INSERT INTO maker_alerts (type, company_id, user_id, title, details, day) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (type, COALESCE(company_id, 0), COALESCE(user_id, 0), day) DO NOTHING',
            [$type, $companyId, $userId, $title, json_encode($details, JSON_UNESCAPED_UNICODE), $this->today]);
    }

    public static function label(string $regime): string
    {
        return [
            'up_strong' => 'مسار صاعد قوي', 'up_tired' => 'مسار صاعد يفقد زخمه', 'range' => 'حركة عرضية',
            'down' => 'مسار هابط', 'down_tired' => 'مسار هابط يهدأ', 'unclear' => 'حركة غير واضحة', 'warming' => 'التحليل غير مكتمل',
        ][$regime] ?? $regime;
    }
}
