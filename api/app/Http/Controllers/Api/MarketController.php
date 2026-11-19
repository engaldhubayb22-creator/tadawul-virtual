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

    private function company(string $symbol): object
    {
        $company = DB::table('companies')->where('symbol', $symbol)->first();
        abort_if($company === null, 404, 'الشركة غير موجودة');

        return $company;
    }
}
