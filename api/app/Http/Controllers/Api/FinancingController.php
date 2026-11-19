<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financing;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->summary($request->user()->id));
    }

    public function store(Request $request): JsonResponse
    {
        $amount = (int) $request->validate(['amount' => ['required', 'integer', 'min:1']])['amount'];
        $userId = $request->user()->id;
        if (! Ledger::setting('financing_enabled')) {
            return response()->json(['message' => 'التمويل متوقف حاليًا'], 409);
        }

        return DB::transaction(function () use ($amount, $userId) {
            // Serialise this user's financing requests.
            DB::selectOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $position = Financing::position($userId);
            if ($position['financing'] !== null) {
                return response()->json(['message' => 'لديك تمويل قائم، سدّده أولًا'], 409);
            }
            if ($amount > Financing::limit($position)) {
                return response()->json(['message' => 'المبلغ أكبر من حد التمويل المتاح'], 422);
            }
            Financing::grant($userId, $amount);

            return response()->json($this->summary($userId), 201);
        });
    }

    public function repay(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return DB::transaction(function () use ($userId) {
            $financing = DB::selectOne(
                "SELECT * FROM financings WHERE user_id = ? AND status IN ('active', 'liquidating') FOR UPDATE", [$userId]);
            if ($financing === null) {
                return response()->json(['message' => 'لا يوجد تمويل قائم'], 404);
            }
            if (! Financing::repay($financing)) {
                return response()->json(['message' => 'النقد المتاح لا يكفي للسداد'], 422);
            }

            return response()->json($this->summary($userId));
        });
    }

    private function summary(int $userId): array
    {
        $p = Financing::position($userId);
        $f = $p['financing'];

        return [
            'enabled' => (bool) Ledger::setting('financing_enabled'),
            'terms' => [
                'fee_bps' => (int) Ledger::setting('financing_fee_bps'),
                'days' => (int) Ledger::setting('financing_days'),
                'margin_call_bps' => (int) Ledger::setting('margin_call_bps'),
                'liquidation_bps' => (int) Ledger::setting('liquidation_bps'),
            ],
            'limit' => Financing::limit($p),
            'assets' => $p['assets'], 'debt' => $p['debt'], 'equity' => $p['equity'], 'margin_bps' => $p['margin_bps'],
            'financing' => $f === null ? null : [
                'id' => (int) $f->id, 'principal' => (int) $f->principal, 'fee' => (int) $f->fee,
                'status' => $f->status, 'due_at' => $f->due_at, 'margin_called' => $f->margin_called_at !== null,
            ],
        ];
    }
}
