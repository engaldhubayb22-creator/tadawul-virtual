<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ledger;
use App\Services\Maker\Brain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admin: the maker's room. What the maker reads, what it plans, its alerts, and the admin's say over direction. */
class MakerController extends Controller
{
    private const SETTINGS = [
        'maker_brain' => ['string', 'in:off,shadow'],
        'maker_confirm_runs' => ['integer', 'between:1,20'],
        'maker_reserve_bps' => ['integer', 'between:0,9000'],
        'maker_sell_cap_bps' => ['integer', 'between:1,10000'],
        'maker_wave_cap_bps' => ['integer', 'between:1,10000'],
        'maker_wave_days' => ['integer', 'between:1,10'],
        'maker_wave_rise_bps' => ['integer', 'between:100,10000'],
        'maker_accum_holder_bps' => ['integer', 'between:100,10000'],
        'maker_accum_volume_bps' => ['integer', 'between:100,10000'],
        'maker_accum_buy_bps' => ['integer', 'between:10000,30000'],
    ];

    public function room(): JsonResponse
    {
        $market = DB::table('maker_market')->where('id', 1)->first();
        $rows = DB::select(<<<'SQL'
            SELECT c.symbol, c.name, c.maker_reserve_open, s.regime, s.auto_regime, s.data, s.plan, s.reason, s.computed_at
            FROM maker_state s JOIN companies c ON c.id = s.company_id
            ORDER BY c.symbol
            SQL);
        $settings = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            $settings[$key] = Ledger::setting($key);
        }

        return response()->json([
            'settings' => $settings,
            'market' => $market ? [
                'regime' => $market->regime, 'auto_regime' => $market->auto_regime, 'label' => Brain::label($market->regime),
                'data' => json_decode($market->data, true), 'computed_at' => $market->computed_at,
            ] : null,
            'companies' => array_map(function ($r) {
                $data = json_decode($r->data, true);
                $plan = json_decode($r->plan, true);

                return [
                    'symbol' => $r->symbol, 'name' => $r->name, 'regime' => $r->regime, 'auto_regime' => $r->auto_regime,
                    'label' => Brain::label($r->regime), 'last' => $data['last'], 'bars' => $data['bars'],
                    'override' => $data['override'], 'accumulation' => $data['accumulation'] !== null, 'wave' => $data['wave'] !== null,
                    'follows_market' => $data['reading']['relation']['follows_market'] ?? null,
                    'corr' => $data['reading']['relation']['corr'] ?? null,
                    'member_net' => $data['flow']['member_net'], 'member_backed' => $data['flow']['member_backed'],
                    'bid_qty' => $plan['bid_qty'], 'ask_qty' => $plan['ask_qty'], 'reserve_open' => (bool) $r->maker_reserve_open,
                    'reason' => $r->reason, 'computed_at' => $r->computed_at,
                ];
            }, $rows),
            'alerts' => DB::table('maker_alerts as a')->leftJoin('companies as c', 'c.id', '=', 'a.company_id')
                ->whereNull('a.resolved_at')->orderByDesc('a.id')->limit(100)
                ->get(['a.id', 'a.type', 'a.title', 'a.details', 'a.created_at', 'c.symbol'])
                ->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'title' => $a->title, 'symbol' => $a->symbol,
                    'details' => json_decode($a->details, true), 'created_at' => $a->created_at,
                    'is_pattern' => str_starts_with($a->type, 'pattern_')]),
        ]);
    }

    /** Everything the maker knows about one company: the indicators, the flows, the fund's limits and the plan. */
    public function company(string $symbol): JsonResponse
    {
        $r = DB::selectOne(<<<'SQL'
            SELECT c.symbol, c.name, c.maker_reserve_open, s.* FROM maker_state s JOIN companies c ON c.id = s.company_id WHERE c.symbol = ?
            SQL, [$symbol]);
        abort_if($r === null, 404, 'لا يوجد تحليل لهذه الشركة بعد');

        return response()->json([
            'symbol' => $r->symbol, 'name' => $r->name, 'regime' => $r->regime, 'auto_regime' => $r->auto_regime,
            'label' => Brain::label($r->regime), 'auto_label' => Brain::label($r->auto_regime),
            'data' => json_decode($r->data, true), 'plan' => json_decode($r->plan, true),
            'reserve_open' => (bool) $r->maker_reserve_open, 'computed_at' => $r->computed_at,
        ]);
    }

    /**
     * Set the direction for a company, or for the market when no symbol is
     * given. `auto` removes the admin's say. Anything else lapses after `hours`.
     */
    public function override(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['nullable', 'string', Rule::exists('companies', 'symbol')],
            'mode' => ['required', Rule::in(['auto', 'follow', 'oppose', 'up', 'down', 'range'])],
            'hours' => ['nullable', 'integer', 'between:1,720'],
        ]);
        $companyId = empty($data['symbol']) ? null : DB::table('companies')->where('symbol', $data['symbol'])->value('id');
        $this->setOverride($request, $companyId, $data['mode'], (int) ($data['hours'] ?? 24));
        Brain::run();

        return response()->json(['ok' => true]);
    }

    /** Open or close the fund's locked reserve for one company. */
    public function reserve(Request $request, string $symbol): JsonResponse
    {
        $open = (bool) $request->validate(['open' => ['required', 'boolean']])['open'];
        $updated = DB::table('companies')->where('symbol', $symbol)->update(['maker_reserve_open' => $open]);
        abort_if($updated === 0, 404, 'الشركة غير موجودة');
        $this->audit($request, 'maker.reserve', ['symbol' => $symbol, 'open' => $open]);
        Brain::run();

        return response()->json(['open' => $open]);
    }

    /**
     * Answer an alert. For a pattern the admin may tell the maker to go with
     * the move or against it for a while; any alert can simply be dismissed.
     */
    public function resolveAlert(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['continue', 'oppose', 'dismiss'])],
            'hours' => ['nullable', 'integer', 'between:1,720'],
        ]);
        $alert = DB::table('maker_alerts')->where('id', $id)->whereNull('resolved_at')->first();
        abort_if($alert === null, 404, 'التنبيه غير موجود أو أُغلق');
        if ($data['action'] !== 'dismiss') {
            abort_if($alert->company_id === null, 422, 'هذا التنبيه لا يخص شركة بعينها');
            $this->setOverride($request, (int) $alert->company_id, $data['action'] === 'continue' ? 'follow' : 'oppose', (int) ($data['hours'] ?? 24));
        }
        DB::table('maker_alerts')->where('id', $id)->update([
            'resolved_at' => DB::raw('now()'), 'resolution' => $data['action'], 'resolved_by' => $request->user()->id,
        ]);
        if ($data['action'] !== 'dismiss') {
            Brain::run();
        }

        return response()->json(['ok' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(array_map(fn ($rules) => ['sometimes', ...$rules], self::SETTINGS));
        foreach ($data as $key => $value) {
            DB::table('settings')->where('key', $key)->update(['value' => json_encode($value), 'updated_at' => DB::raw('now()')]);
        }
        $this->audit($request, 'maker.settings', $data);

        return response()->json(['ok' => true]);
    }

    /** Run the analysis now instead of waiting for the next scheduled pass. */
    public function run(): JsonResponse
    {
        return response()->json(Brain::run());
    }

    private function setOverride(Request $request, ?int $companyId, string $mode, int $hours): void
    {
        DB::table('maker_overrides')->where('company_id', $companyId)->delete();
        if ($mode !== 'auto') {
            DB::table('maker_overrides')->insert([
                'company_id' => $companyId, 'mode' => $mode, 'created_by' => $request->user()->id,
                'expires_at' => DB::raw("now() + interval '$hours hours'"),
            ]);
        }
        $this->audit($request, 'maker.override', ['company_id' => $companyId, 'mode' => $mode, 'hours' => $hours]);
    }

    private function audit(Request $request, string $action, array $details): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'ip' => $request->ip()]);
    }
}
