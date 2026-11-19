<?php

namespace Tests\Feature;

use App\Services\Financing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class FinancingTest extends TestCase
{
    use RefreshDatabase;

    private array $user;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('engine')->del('orders:in');
        $this->companyId = DB::table('companies')->insertGetId([
            'symbol' => '2222', 'name' => 'أرامكو', 'shares_outstanding' => 1000000, 'tick_size' => 1, 'ref_price' => 2000,
        ]);
        $this->user = $this->postJson('/api/v1/register', [
            'name' => 'u', 'alias' => 'borrower', 'mobile' => '0500000001', 'password' => 'secret-pass-1',
        ])->json();
    }

    private function me() { return $this->withToken($this->user['token']); }

    private function queued(): array
    {
        return array_map(fn ($f) => json_decode($f['data'], true),
            array_values(Redis::connection('engine')->xrange('orders:in', '-', '+')));
    }

    /** Pretend the user spent $cash on $qty shares now worth $price each. */
    private function hold(int $qty, int $price, int $cashLeft): void
    {
        DB::table('positions')->updateOrInsert(['user_id' => $this->user['user']['id'], 'company_id' => $this->companyId],
            ['qty' => $qty, 'cost_total' => $qty * $price]);
        DB::table('companies')->where('id', $this->companyId)->update(['ref_price' => $price]);
        $cash = DB::table('ledger_accounts')->where('user_id', $this->user['user']['id'])->where('kind', 'cash');
        $treasury = DB::table('ledger_accounts')->where('kind', 'sys_treasury');
        $diff = (int) $cash->value('balance') - $cashLeft;
        $cash->update(['balance' => $cashLeft]);
        $treasury->increment('balance', $diff);
    }

    public function test_grant_within_the_limit_and_repay_with_the_fixed_fee(): void
    {
        // Equity 100,000. May owe up to 100,000 including the 1% fee: 99,009.90 at most.
        $this->me()->getJson('/api/v1/financing')->assertOk()
            ->assertJsonPath('limit', 9900990)->assertJsonPath('financing', null)->assertJsonPath('terms.fee_bps', 100);

        $this->me()->postJson('/api/v1/financing', ['amount' => 9900991])->assertStatus(422);
        $this->me()->postJson('/api/v1/financing', ['amount' => 5000000])->assertCreated()
            ->assertJsonPath('financing.principal', 5000000)->assertJsonPath('financing.fee', 50000)
            ->assertJsonPath('debt', 5050000)->assertJsonPath('equity', 10000000 - 50000)->assertJsonPath('limit', 0);
        $this->me()->postJson('/api/v1/financing', ['amount' => 100])->assertStatus(409); // one at a time

        $this->me()->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 15000000);
        // Borrowed money is not wealth: net worth and the whales list are net of the debt.
        $this->me()->getJson('/api/v1/portfolio')->assertJsonPath('debt', 5050000)->assertJsonPath('net_worth', 9950000);
        $this->getJson('/api/v1/leaderboard')->assertJsonPath('traders.0.net_worth', 9950000);

        $this->me()->postJson('/api/v1/financing/repay')->assertOk()->assertJsonPath('financing', null)->assertJsonPath('debt', 0);
        $this->me()->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 10000000 - 50000);
        $this->assertSame('repaid', DB::table('financings')->value('status'));
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        $this->me()->postJson('/api/v1/financing/repay')->assertStatus(404);
    }

    public function test_repay_needs_the_cash_and_disabled_financing_grants_nothing(): void
    {
        $this->me()->postJson('/api/v1/financing', ['amount' => 5000000])->assertCreated();
        $this->hold(7000, 2000, 1000000); // 140,000 in shares, 10,000 cash: cannot repay 50,500
        $this->me()->postJson('/api/v1/financing/repay')->assertStatus(422);
        $this->assertSame('active', DB::table('financings')->value('status'));

        DB::table('financings')->delete();
        DB::table('settings')->where('key', 'financing_enabled')->update(['value' => 'false']);
        $this->me()->postJson('/api/v1/financing', ['amount' => 1000])->assertStatus(409);
    }

    public function test_margin_call_then_liquidation_then_repayment(): void
    {
        $this->me()->postJson('/api/v1/financing', ['amount' => 9000000])->assertCreated(); // debt 90,900
        // All 190,000 went into 9,500 shares at 20.00. Margin = (190,000 - 90,900) / 190,000 = 52%.
        $this->hold(9500, 2000, 0);
        $this->assertSame(['called' => 0, 'liquidating' => 0, 'closed' => 0, 'defaulted' => 0, 'sell_orders' => 0], Financing::monitor());

        // Price 13.50: assets 128,250, margin 29.1% -> margin call only.
        $this->hold(9500, 1350, 0);
        $this->assertSame(1, Financing::monitor()['called']);
        $this->me()->getJson('/api/v1/financing')->assertJsonPath('financing.margin_called', true)->assertJsonPath('margin_bps', 2912);
        $this->assertSame(0, Financing::monitor()['called']); // warned once
        $this->assertSame([], $this->queued());

        // Price 11.90: assets 113,050, margin 19.6% -> liquidation. One order is open; it must be cancelled.
        $orderId = DB::table('orders')->insertGetId(['user_id' => $this->user['user']['id'], 'company_id' => $this->companyId,
            'side' => 'sell', 'kind' => 'limit', 'price' => 1500, 'qty' => 500, 'status' => 'open', 'idempotency_key' => 'x']);
        DB::table('positions')->update(['reserved_qty' => 500]);
        $this->hold(9500, 1190, 0);
        $done = Financing::monitor();
        $this->assertSame(1, $done['liquidating']);
        $this->assertSame(1, $done['sell_orders']);
        $queued = $this->queued();
        $this->assertSame(['type' => 'cancel', 'user_id' => $this->user['user']['id'], 'order_id' => $orderId], $queued[0]);
        $this->assertSame('sell', $queued[1]['side']);
        $this->assertSame('market', $queued[1]['kind']);
        $this->assertSame(9000, $queued[1]['qty']); // only the shares not held by the open order
        $this->assertSame('liquidating', DB::table('financings')->value('status'));

        // The engine sold everything at 11.90: 113,050 cash. The next pass collects the debt.
        DB::table('orders')->update(['status' => 'cancelled']);
        DB::table('positions')->delete();
        $this->hold(0, 1190, 11305000);
        DB::table('positions')->delete();
        $this->assertSame(1, Financing::monitor()['closed']);
        $this->assertSame('liquidated', DB::table('financings')->value('status'));
        $this->me()->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 11305000 - 9090000);
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
    }

    public function test_default_writes_off_what_cannot_be_collected(): void
    {
        $this->me()->postJson('/api/v1/financing', ['amount' => 9000000])->assertCreated();
        $this->hold(0, 2000, 3000000); // nothing to sell, 30,000 cash against a 90,900 debt
        DB::table('positions')->delete();
        $done = Financing::monitor();
        $this->assertSame(1, $done['defaulted']);
        $row = DB::table('financings')->first();
        $this->assertSame('defaulted', $row->status);
        $this->assertSame(9090000 - 3000000, $row->written_off);
        $this->me()->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 0);
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        // The slate is clean: the user may apply again (and has no equity to borrow against).
        $this->me()->getJson('/api/v1/financing')->assertJsonPath('financing', null)->assertJsonPath('limit', 0);
    }

    public function test_overdue_financing_is_collected_from_cash(): void
    {
        $this->me()->postJson('/api/v1/financing', ['amount' => 1000000])->assertCreated();
        DB::table('financings')->update(['due_at' => DB::raw("now() - interval '1 day'")]);
        $this->assertSame(1, Financing::monitor()['closed']);
        $this->assertSame('repaid', DB::table('financings')->value('status'));
        $this->me()->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 10000000 - 10000);
    }
}
