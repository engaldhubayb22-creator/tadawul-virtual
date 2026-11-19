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
            SELECT c.symbol, c.name, s.name AS sector, c.status, c.tick_size,
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
            ];
        }, $rows);

        return response()->json([
            'market' => [
                'open' => Ledger::setting('market_open'),
                'close' => Ledger::setting('market_close'),
                'timezone' => Ledger::setting('market_timezone'),
            ],
            'companies' => $companies,
        ]);
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
            WHERE u.role = 'trader' AND u.status = 'active'
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
        $total = (int) DB::table('positions')->where('company_id', $company->id)->sum('qty');
        $rows = DB::select(<<<'SQL'
            SELECT CASE WHEN u.role = 'bot' THEN u.name ELSE u.alias END AS holder,
                   u.role = 'bot' AS is_fund, p.qty
            FROM positions p JOIN users u ON u.id = p.user_id
            WHERE p.company_id = ? AND p.qty > 0
            ORDER BY p.qty DESC, u.id
            LIMIT 10
            SQL, [$company->id]);

        return response()->json([
            'symbol' => $company->symbol,
            'total_held' => $total,
            'holders' => array_map(fn ($r) => [
                'holder' => $r->holder, 'is_fund' => (bool) $r->is_fund, 'qty' => (int) $r->qty,
                'share_bps' => $total > 0 ? intdiv((int) $r->qty * 10000, $total) : 0,
            ], $rows),
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
