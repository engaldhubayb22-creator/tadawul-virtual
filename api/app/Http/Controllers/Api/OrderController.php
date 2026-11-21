<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use App\Services\OrderQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Orders are not executed here. The API checks the request is well formed and
 * queues it; the engine reserves funds, matches and settles, then reports the
 * result on the live feed. The answer to a new order is therefore "queued",
 * with the key to look it up by.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('orders as o')->join('companies as c', 'c.id', '=', 'o.company_id')
            ->where('o.user_id', $request->user()->id)
            ->orderByDesc('o.id')->limit(200)
            ->select('o.id', 'c.symbol', 'o.side', 'o.kind', 'o.price', 'o.qty', 'o.filled_qty',
                'o.status', 'o.time_in_force as tif', 'o.reserved_amount', 'o.idempotency_key as key', 'o.created_at');

        if ($request->query('status') === 'open') {
            $query->whereIn('o.status', ['open', 'partial']);
        }
        if ($key = $request->query('key')) {
            $query->where('o.idempotency_key', $key);
        }

        return response()->json(['orders' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', Rule::exists('companies', 'symbol')],
            'side' => ['required', Rule::in(['buy', 'sell'])],
            'kind' => ['required', Rule::in(['limit', 'market'])],
            'price' => ['required_if:kind,limit', 'nullable', 'integer', 'min:1'],
            'qty' => ['required', 'integer', 'min:1', 'max:100000000'],
            'key' => ['nullable', 'string', 'max:64'],
            // day: the remainder waits in the book; ioc: fill what is there and cancel
            // the rest; fok: fill the whole quantity at once or nothing.
            'tif' => ['nullable', Rule::in(['day', 'ioc', 'fok'])],
        ]);

        $key = $data['key'] ?? (string) Str::uuid();
        $command = [
            'type' => 'new',
            'user_id' => $request->user()->id,
            'symbol' => $data['symbol'],
            'side' => $data['side'],
            'kind' => $data['kind'],
            'qty' => (int) $data['qty'],
            'key' => $key,
        ];
        if ($data['kind'] === 'limit') {
            $command['price'] = (int) $data['price'];
        }
        if (in_array($data['tif'] ?? 'day', ['ioc', 'fok'], true)) {
            $command['tif'] = $data['tif'];
        }
        OrderQueue::send($command);

        return response()->json(['status' => 'queued', 'key' => $key], 202);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $order = DB::table('orders')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_if($order === null, 404, 'الأمر غير موجود');
        if (! in_array($order->status, ['open', 'partial'], true)) {
            return response()->json(['message' => 'الأمر ليس مفتوحًا'], 409);
        }

        OrderQueue::send(['type' => 'cancel', 'user_id' => $request->user()->id, 'order_id' => $id]);

        return response()->json(['status' => 'queued'], 202);
    }

    /**
     * The trader's own executions, newest first: one row per trade, with the
     * commission the trader paid on it and the cash it moved.
     */
    public function trades(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        $rows = DB::select(<<<'SQL'
            SELECT t.id, c.symbol, c.name, t.price, t.qty, t.executed_at,
                   CASE WHEN t.buy_user_id = :me THEN 'buy' ELSE 'sell' END AS side,
                   CASE WHEN t.buy_user_id = :me2 THEN t.buy_order_id ELSE t.sell_order_id END AS order_id,
                   COALESCE((
                       SELECT SUM(e.amount) FROM ledger_entries e
                       JOIN ledger_accounts a ON a.id = e.debit_account_id
                       WHERE e.ref_type = 'commission' AND e.ref_id = t.id AND a.user_id = :me3), 0) AS commission
            FROM trades t JOIN companies c ON c.id = t.company_id
            WHERE t.buy_user_id = :me4 OR t.sell_user_id = :me5
            ORDER BY t.id DESC
            LIMIT :limit
            SQL, ['me' => $userId, 'me2' => $userId, 'me3' => $userId, 'me4' => $userId, 'me5' => $userId, 'limit' => $limit]);

        return response()->json(['trades' => array_map(function ($r) {
            $value = (int) $r->price * (int) $r->qty;
            $commission = (int) $r->commission;

            return [
                'id' => (int) $r->id,
                'order_id' => (int) $r->order_id,
                'symbol' => $r->symbol,
                'name' => $r->name,
                'side' => $r->side,
                'price' => (int) $r->price,
                'qty' => (int) $r->qty,
                'value' => $value,
                'commission' => $commission,
                // Cash paid for a buy, cash received for a sell.
                'net' => $r->side === 'buy' ? $value + $commission : $value - $commission,
                'executed_at' => $r->executed_at,
            ];
        }, $rows)]);
    }

    public function portfolio(Request $request): JsonResponse
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.symbol, c.name, p.qty, p.reserved_qty, p.cost_total,
                   COALESCE(
                       (SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
                       (SELECT prev_close FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1),
                       c.ref_price) AS last
            FROM positions p JOIN companies c ON c.id = p.company_id
            WHERE p.user_id = ? AND p.qty > 0
            ORDER BY c.symbol
            SQL, [$request->user()->id]);

        $marketValue = 0;
        $positions = array_map(function ($r) use (&$marketValue) {
            $qty = (int) $r->qty;
            $value = $qty * (int) $r->last;
            $marketValue += $value;

            return [
                'symbol' => $r->symbol,
                'name' => $r->name,
                'qty' => $qty,
                'reserved_qty' => (int) $r->reserved_qty,
                'avg_cost' => intdiv((int) $r->cost_total, $qty),
                'last' => (int) $r->last,
                'market_value' => $value,
                'unrealized_pnl' => $value - (int) $r->cost_total,
            ];
        }, $rows);

        $balances = Ledger::balances($request->user()->id);
        $debt = (int) DB::table('financings')->where('user_id', $request->user()->id)
            ->whereIn('status', ['active', 'liquidating'])->sum(DB::raw('principal + fee'));

        return response()->json([
            'balances' => $balances,
            'positions' => $positions,
            'market_value' => $marketValue,
            'debt' => $debt,
            'net_worth' => $balances['cash'] + $balances['reserved'] + $marketValue - $debt,
        ]);
    }
}
