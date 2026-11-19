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
                'o.status', 'o.idempotency_key as key', 'o.created_at');

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

        return response()->json([
            'balances' => $balances,
            'positions' => $positions,
            'market_value' => $marketValue,
            'net_worth' => $balances['cash'] + $balances['reserved'] + $marketValue,
        ]);
    }
}
