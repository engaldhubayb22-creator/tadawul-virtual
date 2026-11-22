<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use App\Services\Pledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Loans against pledged shares: what is open, what could be pledged, borrow and repay. */
class PledgeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->summary($request->user()->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:12'],
            'qty' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);
        $userId = $request->user()->id;
        if (! Ledger::setting('pledge_enabled')) {
            return response()->json(['message' => 'القروض بضمان الأسهم متوقفة حاليًا'], 409);
        }
        $company = DB::table('companies')->where('symbol', $data['symbol'])->first();
        if ($company === null) {
            return response()->json(['message' => 'الشركة غير موجودة'], 404);
        }

        return DB::transaction(function () use ($userId, $company, $data) {
            $result = Pledge::grant($userId, $company, (int) $data['qty'], (int) $data['amount']);
            if (is_string($result)) {
                return response()->json(['message' => $result], 422);
            }

            return response()->json($this->summary($userId), 201);
        });
    }

    public function repay(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        return DB::transaction(function () use ($userId, $id) {
            $loan = DB::selectOne(
                "SELECT * FROM pledge_loans WHERE id = ? AND user_id = ? AND status = 'active' FOR UPDATE", [$id, $userId]);
            if ($loan === null) {
                return response()->json(['message' => 'القرض غير موجود أو مغلق'], 404);
            }
            if (! Pledge::repay($loan)) {
                return response()->json(['message' => 'النقد المتاح لا يكفي للسداد'], 422);
            }

            return response()->json($this->summary($userId));
        });
    }

    private function summary(int $userId): array
    {
        $loans = Pledge::open($userId);

        return [
            'enabled' => (bool) Ledger::setting('pledge_enabled'),
            'terms' => Pledge::terms(),
            'debt' => array_sum(array_column($loans, 'debt')),
            'pledged_value' => array_sum(array_column($loans, 'value')),
            'loans' => $loans,
            'closed' => Pledge::closed($userId),
            'eligible' => Pledge::eligible($userId),
        ];
    }
}
