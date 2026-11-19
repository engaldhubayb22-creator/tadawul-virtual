<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IpoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = auth('sanctum')->id() ?? 0;
        $rows = DB::select(<<<'SQL'
            SELECT i.id, c.symbol, c.name, i.price, i.shares_offered, i.min_qty, i.max_qty, i.status,
                   i.closes_at, i.closes_at > now() AS accepting,
                   COALESCE((SELECT sum(qty_requested) FROM ipo_subscriptions WHERE ipo_id = i.id), 0) AS requested,
                   (SELECT count(*) FROM ipo_subscriptions WHERE ipo_id = i.id) AS subscribers,
                   s.qty_requested AS my_requested, s.qty_allocated AS my_allocated
            FROM ipos i JOIN companies c ON c.id = i.company_id
            LEFT JOIN ipo_subscriptions s ON s.ipo_id = i.id AND s.user_id = ?
            ORDER BY i.status = 'open' DESC, i.id DESC
            LIMIT 30
            SQL, [$userId]);

        return response()->json(['ipos' => array_map(fn ($r) => [
            'id' => (int) $r->id, 'symbol' => $r->symbol, 'name' => $r->name, 'price' => (int) $r->price,
            'shares_offered' => (int) $r->shares_offered, 'min_qty' => (int) $r->min_qty, 'max_qty' => (int) $r->max_qty,
            'status' => $r->status, 'closes_at' => $r->closes_at,
            'accepting' => $r->status === 'open' && $r->accepting,
            'requested' => (int) $r->requested, 'subscribers' => (int) $r->subscribers,
            'coverage_bps' => intdiv((int) $r->requested * 10000, (int) $r->shares_offered),
            'my_requested' => $r->my_requested === null ? null : (int) $r->my_requested,
            'my_allocated' => $r->my_allocated === null ? null : (int) $r->my_allocated,
        ], $rows)]);
    }

    public function subscribe(Request $request, int $id): JsonResponse
    {
        $qty = (int) $request->validate(['qty' => ['required', 'integer', 'min:1']])['qty'];
        $userId = $request->user()->id;

        return DB::transaction(function () use ($id, $qty, $userId) {
            $ipo = DB::selectOne('SELECT *, closes_at > now() AS accepting FROM ipos WHERE id = ? FOR UPDATE', [$id]);
            abort_if($ipo === null, 404, 'الاكتتاب غير موجود');
            if ($ipo->status !== 'open' || ! $ipo->accepting) {
                return response()->json(['message' => 'الاكتتاب مغلق'], 409);
            }
            if ($qty < $ipo->min_qty || $qty > $ipo->max_qty) {
                return response()->json(['message' => "الكمية يجب أن تكون بين {$ipo->min_qty} و {$ipo->max_qty}"], 422);
            }
            if (DB::table('ipo_subscriptions')->where('ipo_id', $id)->where('user_id', $userId)->exists()) {
                return response()->json(['message' => 'سبق أن اكتتبت في هذا الطرح'], 409);
            }
            if (! Ipo::reserve($userId, $qty * (int) $ipo->price, $id)) {
                return response()->json(['message' => 'الرصيد غير كافٍ'], 422);
            }
            DB::table('ipo_subscriptions')->insert(['ipo_id' => $id, 'user_id' => $userId, 'qty_requested' => $qty]);

            return response()->json(['status' => 'subscribed', 'reserved' => $qty * (int) $ipo->price], 201);
        });
    }

    /** Admin: announce an IPO. The company is created as pending until it lists. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'regex:/^[0-9A-Z]{2,8}$/', 'unique:companies,symbol'],
            'name' => ['required', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'integer', 'min:1'],
            'tick_size' => ['required', 'integer', 'min:1'],
            'shares_offered' => ['required', 'integer', 'min:1'],
            'maker_shares' => ['required', 'integer', 'min:0'],
            'min_qty' => ['required', 'integer', 'min:1'],
            'max_qty' => ['required', 'integer', 'gte:min_qty'],
            'minutes' => ['required', 'integer', 'between:1,43200'],
        ]);
        if ($data['price'] % $data['tick_size'] !== 0) {
            return response()->json(['message' => 'سعر الطرح يجب أن يطابق وحدة تغيّر السعر'], 422);
        }

        $id = DB::transaction(function () use ($data, $request) {
            $sectorId = null;
            if (! empty($data['sector'])) {
                DB::statement('INSERT INTO sectors (name) VALUES (?) ON CONFLICT DO NOTHING', [$data['sector']]);
                $sectorId = DB::table('sectors')->where('name', $data['sector'])->value('id');
            }
            $companyId = DB::table('companies')->insertGetId([
                'symbol' => $data['symbol'], 'name' => $data['name'], 'sector_id' => $sectorId,
                'shares_outstanding' => $data['shares_offered'] + $data['maker_shares'],
                'tick_size' => $data['tick_size'], 'ref_price' => $data['price'], 'status' => 'pending',
            ]);
            $ipoId = DB::table('ipos')->insertGetId([
                'company_id' => $companyId, 'price' => $data['price'], 'shares_offered' => $data['shares_offered'],
                'maker_shares' => $data['maker_shares'], 'min_qty' => $data['min_qty'], 'max_qty' => $data['max_qty'],
                'closes_at' => DB::raw("now() + interval '".(int) $data['minutes']." minutes'"),
            ]);
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'ipo.create',
                'details' => json_encode($data, JSON_UNESCAPED_UNICODE), 'ip' => $request->ip()]);

            return $ipoId;
        });

        return response()->json(['id' => $id], 201);
    }

    /** Admin: close the IPO, allocate the shares, refund the excess and list the company. */
    public function allocate(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($id, $request) {
            $ipo = DB::selectOne('SELECT * FROM ipos WHERE id = ? FOR UPDATE', [$id]);
            abort_if($ipo === null, 404, 'الاكتتاب غير موجود');
            if ($ipo->status !== 'open') {
                return response()->json(['message' => 'سبق تخصيص هذا الاكتتاب'], 409);
            }
            $summary = Ipo::allocateAndList($ipo);
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'ipo.allocate',
                'details' => json_encode(['ipo_id' => $id] + $summary), 'ip' => $request->ip()]);

            return response()->json($summary);
        });
    }
}
