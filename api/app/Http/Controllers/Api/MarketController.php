<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only market data. These endpoints give the page its first snapshot;
 * after that the page listens to the live feed and applies changes.
 * All prices are integers in halalas.
 */
class MarketController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.symbol, c.name, s.name AS sector, c.status, c.tick_size, c.ref_price,
                   c.shares_outstanding, c.founder_shares, c.par_value, c.equity, c.net_income,
                   d.prev_close, d.low_limit, d.high_limit, d.volume,
                   COALESCE(t.price, d.prev_close) AS last
            FROM companies c
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN LATERAL (
                SELECT * FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1
            ) d ON true
            LEFT JOIN LATERAL (
                SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1
            ) t ON true
            WHERE c.status <> 'delisted'
            ORDER BY c.symbol
            SQL);

        $companies = array_map(function ($r) {
            $last = $r->last === null ? null : (int) $r->last;
            $prev = $r->prev_close === null ? null : (int) $r->prev_close;

            return [
                'symbol' => $r->symbol,
                'name' => $r->name,
                'sector' => $r->sector,
                'status' => $r->status,
                'tick_size' => (int) $r->tick_size,
                'last' => $last,
                'prev_close' => $prev,
                'change' => ($last !== null && $prev !== null) ? $last - $prev : null,
                'change_bps' => ($last !== null && $prev) ? intdiv(($last - $prev) * 10000, $prev) : null,
                'low_limit' => $r->low_limit === null ? null : (int) $r->low_limit,
                'high_limit' => $r->high_limit === null ? null : (int) $r->high_limit,
                'volume' => (int) ($r->volume ?? 0),
            ] + self::fundamentals($r, $last);
        }, $rows);

        return response()->json([
            'index' => self::indexOf($companies, null),
            'sectors' => array_map(
                fn ($sector) => ['name' => $sector] + self::indexOf($companies, $sector),
                array_values(array_unique(array_filter(array_column($companies, 'sector'))))
            ),
            'market' => [
                'open' => Ledger::setting('market_open'),
                'close' => Ledger::setting('market_close'),
                'timezone' => Ledger::setting('market_timezone'),
            ],
            'companies' => $companies,
        ]);
    }

    /**
     * Per-share figures derived from the company's issued shares, equity and
     * income. Ratios are multiplied by 100 (1850 means 18.50).
     */
    private static function fundamentals(object $r, ?int $last): array
    {
        $issued = (int) $r->shares_outstanding;
        $bookValue = intdiv((int) $r->equity, $issued);   // halalas per share
        $eps = intdiv((int) $r->net_income, $issued);

        return [
            'issued_shares' => $issued,
            'free_float' => $issued - (int) $r->founder_shares,
            'par_value' => (int) $r->par_value,
            'capital' => $issued * (int) $r->par_value,
            'market_cap' => $last === null ? null : $issued * $last,
            'book_value' => $bookValue,
            'eps' => $eps,
            'pe' => ($last !== null && $eps > 0) ? intdiv($last * 100, $eps) : null,
            'pb' => ($last !== null && $bookValue > 0) ? intdiv($last * 100, $bookValue) : null,
            'base_price' => (int) $r->ref_price,
        ];
    }

    /**
     * Free-float weighted index, base 10,000 at the listing prices:
     * 10,000 x sum(float x last) / sum(float x listing price).
     * $sector limits it to one sector.
     *
     * @return array{value:int,change_bps:int} value is multiplied by 100
     */
    private static function indexOf(array $companies, ?string $sector): array
    {
        $now = $prev = $base = 0;
        foreach ($companies as $c) {
            if ($c['status'] !== 'trading' || $c['last'] === null || ($sector !== null && $c['sector'] !== $sector)) {
                continue;
            }
            $now += $c['free_float'] * $c['last'];
            $prev += $c['free_float'] * ($c['prev_close'] ?? $c['last']);
            $base += $c['free_float'] * $c['base_price'];
        }
        if ($base === 0) {
            return ['value' => 1000000, 'change_bps' => 0];
        }

        return [
            'value' => (int) round($now / $base * 1000000),
            'change_bps' => $prev > 0 ? (int) round(($now - $prev) / $prev * 10000) : 0,
        ];
    }

    public function depth(string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $side = fn (string $side, string $dir) => array_map(
            fn ($r) => ['price' => (int) $r->price, 'qty' => (int) $r->qty, 'orders' => (int) $r->orders],
            DB::select(
                "SELECT price, sum(qty - filled_qty) AS qty, count(*) AS orders
                 FROM orders
                 WHERE company_id = ? AND side = ? AND status IN ('open', 'partial') AND price IS NOT NULL
                 GROUP BY price ORDER BY price $dir LIMIT 10",
                [$company->id, $side]
            )
        );

        return response()->json([
            'symbol' => $company->symbol,
            'bids' => $side('buy', 'DESC'),
            'asks' => $side('sell', 'ASC'),
        ]);
    }

    public function trades(Request $request, string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $limit = min(max((int) $request->query('limit', 50), 1), 200);

        $rows = DB::table('trades')->where('company_id', $company->id)
            ->orderByDesc('id')->limit($limit)
            ->get(['id', 'price', 'qty', 'taker_side', 'executed_at']);

        return response()->json(['symbol' => $company->symbol, 'trades' => $rows]);
    }

    /**
     * Executions across the whole market, newest first. With ?after=<id> it
     * returns only trades newer than that id, so a page that is already open
     * can keep itself up to date cheaply.
     */
    public function allTrades(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 300);
        $after = max((int) $request->query('after', 0), 0);

        $rows = DB::table('trades as t')->join('companies as c', 'c.id', '=', 't.company_id')
            ->when($after > 0, fn ($q) => $q->where('t.id', '>', $after))
            ->orderByDesc('t.id')->limit($limit)
            ->get(['t.id', 'c.symbol', 'c.name', 't.price', 't.qty', 't.taker_side', 't.executed_at']);

        return response()->json(['trades' => $rows]);
    }

    public function candles(Request $request, string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $limit = min(max((int) $request->query('limit', 300), 1), 1000);

        $rows = DB::table('candles')->where('company_id', $company->id)->where('interval', '1m')
            ->orderByDesc('bucket')->limit($limit)
            ->get(['bucket', 'open', 'high', 'low', 'close', 'volume'])
            ->reverse()->values();

        return response()->json(['symbol' => $company->symbol, 'interval' => '1m', 'candles' => $rows]);
    }

    /**
     * The whales list: traders ranked by net worth (cash, cash held by open
     * orders, and shares at the last price). Bot and staff accounts are left out.
     */
    public function leaderboard(): JsonResponse
    {
        $rows = DB::select(<<<'SQL'
            WITH px AS (
                SELECT c.id, COALESCE(
                    (SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
                    (SELECT prev_close FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1),
                    c.ref_price) AS last
                FROM companies c
            ), cash AS (
                SELECT user_id, sum(balance) AS amount FROM ledger_accounts
                WHERE user_id IS NOT NULL AND kind IN ('cash', 'reserved') GROUP BY user_id
            ), stock AS (
                SELECT p.user_id, sum(p.qty * px.last) AS amount, count(*) FILTER (WHERE p.qty > 0) AS holdings
                FROM positions p JOIN px ON px.id = p.company_id GROUP BY p.user_id
            ), debt AS (
                SELECT user_id, sum(principal + fee) AS amount FROM financings
                WHERE status IN ('active', 'liquidating') GROUP BY user_id
            )
            SELECT u.id, u.alias,
                   COALESCE(cash.amount, 0) AS cash, COALESCE(stock.amount, 0) AS stocks,
                   COALESCE(cash.amount, 0) + COALESCE(stock.amount, 0) - COALESCE(debt.amount, 0) AS net_worth,
                   COALESCE(stock.holdings, 0) AS holdings
            FROM users u
            LEFT JOIN cash ON cash.user_id = u.id
            LEFT JOIN stock ON stock.user_id = u.id
            LEFT JOIN debt ON debt.user_id = u.id
            WHERE u.role <> 'bot' AND u.status = 'active'
            ORDER BY net_worth DESC, u.id
            LIMIT 50
            SQL);

        $rank = 0;
        $traders = array_map(function ($r) use (&$rank) {
            return [
                'rank' => ++$rank, 'user_id' => (int) $r->id, 'alias' => $r->alias,
                'net_worth' => (int) $r->net_worth, 'cash' => (int) $r->cash,
                'stocks' => (int) $r->stocks, 'holdings' => (int) $r->holdings,
            ];
        }, $rows);

        return response()->json(['traders' => $traders]);
    }

    /**
     * Largest holders of one company. Bot accounts appear under their fund
     * name; the share is of all shares currently held.
     */
    public function holders(string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $total = (int) $company->shares_outstanding;
        $founders = (int) $company->founder_shares;
        $rows = DB::select(<<<'SQL'
            SELECT CASE WHEN u.role = 'bot' THEN u.name ELSE u.alias END AS holder,
                   u.role = 'bot' AS is_fund, p.qty
            FROM positions p JOIN users u ON u.id = p.user_id
            WHERE p.company_id = ? AND p.qty > 0
            ORDER BY p.qty DESC, u.id
            LIMIT 10
            SQL, [$company->id]);

        $holders = array_map(fn ($r) => [
            'holder' => $r->holder, 'is_fund' => (bool) $r->is_fund, 'qty' => (int) $r->qty,
            'share_bps' => intdiv((int) $r->qty * 10000, $total),
        ], $rows);
        if ($founders > 0) {
            array_unshift($holders, ['holder' => 'المؤسسون (أسهم غير متداولة)', 'is_fund' => false,
                'qty' => $founders, 'share_bps' => intdiv($founders * 10000, $total)]);
        }

        return response()->json([
            'symbol' => $company->symbol,
            'issued_shares' => $total,
            'free_float' => $total - $founders,
            'total_held' => (int) DB::table('positions')->where('company_id', $company->id)->sum('qty'),
            'holders' => $holders,
        ]);
    }

    public function news(): JsonResponse
    {
        $rows = DB::table('news as n')->leftJoin('companies as c', 'c.id', '=', 'n.company_id')
            ->orderByDesc('n.id')->limit(30)
            ->get(['n.id', 'c.symbol', 'c.name', 'n.title', 'n.body', 'n.created_at']);

        return response()->json(['news' => $rows]);
    }

    private function company(string $symbol): object
    {
        $company = DB::table('companies')->where('symbol', $symbol)->first();
        abort_if($company === null, 404, 'الشركة غير موجودة');

        return $company;
    }
}
