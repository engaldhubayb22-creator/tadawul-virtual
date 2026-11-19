<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admin panel. Everything here is a setting the engine or the market
 * maker re-reads on its own: the engine within five seconds, the bot on its
 * next cycle. Every change is written to audit_logs.
 */
class AdminController extends Controller
{
    private const SETTINGS = [
        'starting_balance_halalas' => ['integer', 'min:0', 'max:100000000000000'],
        'market_open' => ['date_format:H:i'],
        'market_close' => ['date_format:H:i'],
        'market_hours_enforced' => ['boolean'],
        'bots_enabled' => ['boolean'],
        'commission_bps' => ['numeric', 'between:0,100'],
        'market_days' => ['array', 'min:1'],
        'market_days.*' => ['integer', 'between:0,6', 'distinct'],
        'financing_enabled' => ['boolean'],
        'financing_fee_bps' => ['integer', 'between:0,2000'],
        'financing_days' => ['integer', 'between:1,365'],
        'financing_max_ratio_bps' => ['integer', 'between:0,30000'],
        'margin_call_bps' => ['integer', 'between:1,9999'],
        'liquidation_bps' => ['integer', 'between:1,9999'],
    ];

    public function overview(): JsonResponse
    {
        $settings = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            if (! str_contains($key, '.')) {
                $settings[$key] = Ledger::setting($key);
            }
        }

        $companies = DB::select(<<<'SQL'
            SELECT c.symbol, c.name, c.status, c.tick_size, d.low_limit, d.high_limit,
                   COALESCE((SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
                            d.prev_close, c.ref_price) AS last,
                   COALESCE(b.enabled, true) AS enabled, COALESCE(b.spread_bps, 10) AS spread_bps,
                   COALESCE(b.levels, 5) AS levels, COALESCE(b.qty_min, 100) AS qty_min,
                   COALESCE(b.qty_max, 2000) AS qty_max, COALESCE(b.noise_pct, 70) AS noise_pct,
                   CASE WHEN b.target_until > now() THEN b.target_price END AS target_price,
                   CASE WHEN b.target_until > now() THEN b.target_until END AS target_until
            FROM companies c
            LEFT JOIN LATERAL (
                SELECT * FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1
            ) d ON true
            LEFT JOIN bot_configs b ON b.company_id = c.id
            ORDER BY c.symbol
            SQL);

        return response()->json([
            'settings' => $settings,
            'companies' => $companies,
            'stats' => [
                'users' => DB::table('users')->where('role', 'trader')->count(),
                'open_orders' => DB::table('orders')->whereIn('status', ['open', 'partial'])->count(),
                'trades_today' => DB::table('trades')->whereRaw('executed_at::date = now()::date')->count(),
                // Double-entry check: must always be zero.
                'ledger_sum' => (int) DB::table('ledger_accounts')->sum('balance'),
                // Share registry check: companies whose positions do not add up to their free float.
                'registry_mismatches' => (int) DB::selectOne(
                    "SELECT count(*) AS n FROM companies c WHERE c.status = 'trading' AND c.shares_outstanding - c.founder_shares
                     <> COALESCE((SELECT sum(qty) FROM positions p WHERE p.company_id = c.id), 0)")->n,
            ],
            'news' => DB::table('news')->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(array_map(fn ($rules) => ['sometimes', ...$rules], self::SETTINGS));
        if (isset($data['market_days'])) {
            $data['market_days'] = array_values(array_map('intval', $data['market_days']));
            sort($data['market_days']);
        }
        if (isset($data['margin_call_bps'], $data['liquidation_bps']) && $data['liquidation_bps'] >= $data['margin_call_bps']) {
            return response()->json(['message' => 'حد التسييل يجب أن يكون أقل من حد تنبيه الهامش'], 422);
        }
        if (isset($data['market_open'], $data['market_close']) && $data['market_open'] >= $data['market_close']) {
            return response()->json(['message' => 'وقت الافتتاح يجب أن يسبق وقت الإغلاق'], 422);
        }

        DB::transaction(function () use ($data, $request) {
            foreach ($data as $key => $value) {
                DB::table('settings')->where('key', $key)
                    ->update(['value' => json_encode($key === 'commission_bps' ? (float) $value : $value), 'updated_at' => DB::raw('now()')]);
            }
            $this->audit($request, 'settings.update', $data);
        });

        return $this->overview();
    }

    /** Tune the market maker for one company, or start and stop a price scenario. */
    public function bot(Request $request, string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'spread_bps' => ['sometimes', 'integer', 'between:1,500'],
            'levels' => ['sometimes', 'integer', 'between:1,10'],
            'qty_min' => ['sometimes', 'integer', 'min:1'],
            'qty_max' => ['sometimes', 'integer', 'min:1'],
            'noise_pct' => ['sometimes', 'integer', 'between:0,100'],
            'target_price' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'target_minutes' => ['required_with:target_price', 'nullable', 'integer', 'between:1,1440'],
        ]);

        $row = array_intersect_key($data, array_flip(['enabled', 'spread_bps', 'levels', 'qty_min', 'qty_max', 'noise_pct']));
        if (array_key_exists('target_price', $data)) {
            $row['target_price'] = $data['target_price'];
            $row['target_until'] = $data['target_price'] === null ? null : $this->minutesFromNow((int) $data['target_minutes']);
        }
        $current = (array) (DB::table('bot_configs')->where('company_id', $company->id)->first()
            ?? ['qty_min' => 100, 'qty_max' => 2000]);
        if (($row['qty_min'] ?? $current['qty_min']) > ($row['qty_max'] ?? $current['qty_max'])) {
            return response()->json(['message' => 'أقل كمية يجب ألا تزيد على أكبر كمية'], 422);
        }

        DB::transaction(function () use ($company, $row, $request, $symbol) {
            $row['updated_at'] = DB::raw('now()');
            DB::table('bot_configs')->updateOrInsert(['company_id' => $company->id], $row);
            $this->audit($request, 'bot.update', ['symbol' => $symbol] + $request->all());
        });

        return $this->overview();
    }

    /** Set a company's virtual fundamentals: equity, income and par value. */
    public function fundamentals(Request $request, string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $data = $request->validate([
            'equity' => ['sometimes', 'integer', 'min:0'],
            'net_income' => ['sometimes', 'integer'],
            'par_value' => ['sometimes', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($company, $data, $request, $symbol) {
            DB::table('companies')->where('id', $company->id)->update($data);
            $this->audit($request, 'company.fundamentals', ['symbol' => $symbol] + $data);
        });

        return $this->overview();
    }

    /** Halt or resume trading in one company. */
    public function companyStatus(Request $request, string $symbol): JsonResponse
    {
        $company = $this->company($symbol);
        $data = $request->validate(['status' => ['required', Rule::in(['trading', 'halted'])]]);

        DB::transaction(function () use ($company, $data, $request, $symbol) {
            DB::table('companies')->where('id', $company->id)->update(['status' => $data['status']]);
            $this->audit($request, 'company.status', ['symbol' => $symbol] + $data);
        });

        return $this->overview();
    }

    /**
     * Publish a news item. With an impact, the market maker is told to walk
     * the price by that much over the given minutes, so the news moves the
     * market the same way any scenario does: through real orders.
     */
    public function news(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', Rule::exists('companies', 'symbol')],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'impact_bps' => ['required', 'integer', 'between:-1000,1000'],
            'minutes' => ['required_unless:impact_bps,0', 'nullable', 'integer', 'between:1,1440'],
        ]);
        $company = $this->company($data['symbol']);

        DB::transaction(function () use ($company, $data, $request) {
            DB::table('news')->insert([
                'company_id' => $company->id, 'title' => $data['title'], 'body' => $data['body'] ?? '',
                'impact_bps' => $data['impact_bps'], 'created_by' => $request->user()->id,
            ]);
            if ($data['impact_bps'] !== 0) {
                $last = (int) (DB::table('trades')->where('company_id', $company->id)->orderByDesc('id')->value('price')
                    ?? DB::table('daily_stats')->where('company_id', $company->id)->orderByDesc('trade_date')->value('prev_close')
                    ?? $company->ref_price);
                $tick = (int) $company->tick_size;
                $target = max($tick, intdiv($last * (10000 + $data['impact_bps']), 10000 * $tick) * $tick);
                DB::table('bot_configs')->updateOrInsert(['company_id' => $company->id], [
                    'target_price' => $target,
                    'target_until' => $this->minutesFromNow((int) $data['minutes']),
                    'updated_at' => DB::raw('now()'),
                ]);
            }
            $this->audit($request, 'news.publish', $data);
        });

        return $this->overview();
    }

    /**
     * Times are computed by the database, not by PHP: the two can run in
     * different time zones, and a scenario that expires hours early or late
     * would be a silent bug.
     */
    private function minutesFromNow(int $minutes): Expression
    {
        return DB::raw("now() + interval '".$minutes." minutes'");
    }

    private function company(string $symbol): object
    {
        $company = DB::table('companies')->where('symbol', $symbol)->first();
        abort_if($company === null, 404, 'الشركة غير موجودة');

        return $company;
    }

    private function audit(Request $request, string $action, array $details): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip' => $request->ip(),
        ]);
    }
}
