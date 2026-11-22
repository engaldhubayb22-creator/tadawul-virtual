<?php

namespace Tests\Feature;

use App\Services\Financing;
use App\Services\Pledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PledgeTest extends TestCase
{
    use RefreshDatabase;

    private array $user;

    private int $userId;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'symbol' => '2222', 'name' => 'أرامكو', 'shares_outstanding' => 1000000, 'tick_size' => 1, 'ref_price' => 2000,
        ]);
        $this->user = $this->postJson('/api/v1/register', [
            'name' => 'u', 'alias' => 'borrower', 'mobile' => '0500000001', 'password' => 'secret-pass-1',
        ])->json();
        $this->userId = $this->user['user']['id'];
        // 5,000 shares at 20.00 = 100,000.00, on top of the 100,000.00 starting cash.
        DB::table('positions')->insert(['user_id' => $this->userId, 'company_id' => $this->companyId, 'qty' => 5000, 'cost_total' => 9000000]);
    }

    private function me() { return $this->withToken($this->user['token']); }

    private function price(int $price): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['ref_price' => $price]);
    }

    private function position(): object
    {
        return DB::table('positions')->where('user_id', $this->userId)->first();
    }

    private function cash(): int
    {
        return (int) DB::table('ledger_accounts')->where('user_id', $this->userId)->where('kind', 'cash')->value('balance');
    }

    public function test_pledging_locks_the_shares_and_repaying_releases_them(): void
    {
        $this->getJson('/api/v1/pledges')->assertStatus(401);
        // 5,000 free shares worth 100,000.00: debt may reach 50,000.00, so principal at most 49,019.60 with the 2% fee.
        $this->me()->getJson('/api/v1/pledges')->assertOk()
            ->assertJsonPath('terms.ltv_bps', 5000)->assertJsonPath('loans', [])
            ->assertJsonPath('eligible.0.symbol', '2222')->assertJsonPath('eligible.0.free', 5000)
            ->assertJsonPath('eligible.0.max_loan', 4901960);

        // Pledge 2,000 shares (40,000.00): at most 19,607.84.
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 2000, 'amount' => 1960785])->assertStatus(422);
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 6000, 'amount' => 100])->assertStatus(422);
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '9999', 'qty' => 1, 'amount' => 100])->assertStatus(404);
        $loanId = $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 2000, 'amount' => 1500000])->assertCreated()
            ->assertJsonPath('loans.0.qty', 2000)->assertJsonPath('loans.0.principal', 1500000)
            ->assertJsonPath('loans.0.fee', 30000)->assertJsonPath('loans.0.debt', 1530000)
            ->assertJsonPath('loans.0.value', 4000000)->assertJsonPath('loans.0.ltv_bps', 3825)
            ->assertJsonPath('debt', 1530000)->assertJsonPath('pledged_value', 4000000)
            ->assertJsonPath('eligible.0.free', 3000)
            ->json('loans.0.id');

        // The shares are still owned but locked: the engine sees them as reserved.
        $this->assertSame([5000, 2000, 2000], [(int) $this->position()->qty, (int) $this->position()->reserved_qty, (int) $this->position()->pledged_qty]);
        $this->assertSame(11500000, $this->cash());
        $this->me()->getJson('/api/v1/portfolio')->assertOk()
            ->assertJsonPath('positions.0.qty', 5000)->assertJsonPath('positions.0.pledged_qty', 2000)
            ->assertJsonPath('positions.0.reserved_qty', 0)
            ->assertJsonPath('debt', 1530000)->assertJsonPath('net_worth', 10000000 + 10000000 - 30000);
        $this->getJson('/api/v1/leaderboard')->assertJsonPath('traders.0.net_worth', 19970000);
        $this->me()->getJson('/api/v1/bank')->assertJsonPath('statement.0.type', 'pledge_loan')->assertJsonPath('statement.0.amount', 1500000);
        // Loan debt counts against equity for the margin facility too.
        $this->assertSame(19970000, Financing::position($this->userId)['equity']);

        // Only the remaining 3,000 can be pledged again.
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 3001, 'amount' => 100])->assertStatus(422);

        // Repaying needs the cash.
        DB::table('ledger_accounts')->where('user_id', $this->userId)->where('kind', 'cash')->update(['balance' => 1000000]);
        DB::table('ledger_accounts')->where('kind', 'sys_treasury')->increment('balance', 10500000);
        $this->me()->postJson("/api/v1/pledges/$loanId/repay")->assertStatus(422);
        DB::table('ledger_accounts')->where('user_id', $this->userId)->where('kind', 'cash')->update(['balance' => 11500000]);
        DB::table('ledger_accounts')->where('kind', 'sys_treasury')->decrement('balance', 10500000);

        $this->me()->postJson("/api/v1/pledges/$loanId/repay")->assertOk()
            ->assertJsonPath('loans', [])->assertJsonPath('closed.0.status', 'repaid')->assertJsonPath('eligible.0.free', 5000);
        $this->assertSame([5000, 0, 0], [(int) $this->position()->qty, (int) $this->position()->reserved_qty, (int) $this->position()->pledged_qty]);
        $this->assertSame(10000000 - 30000, $this->cash());
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        $this->me()->postJson("/api/v1/pledges/$loanId/repay")->assertStatus(404);
    }

    public function test_falling_price_warns_then_the_bank_takes_the_shares(): void
    {
        DB::table('users')->insert(['name' => 'صندوق السيولة', 'alias' => 'fund', 'mobile' => 'fund', 'password' => '!', 'role' => 'bot']);
        $fundId = (int) DB::table('users')->where('alias', 'fund')->value('id');
        // Pledge 2,000 shares (40,000.00) for 19,000.00: debt 19,380.00, ltv 48.45%.
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 2000, 'amount' => 1900000])->assertCreated();
        $none = ['pledge_warned' => 0, 'pledge_collected' => 0, 'pledge_foreclosed' => 0];
        $this->assertSame($none, Pledge::monitor());

        // 13.50: value 27,000.00, ltv 71.8% -> warned once.
        $this->price(1350);
        $this->assertSame(1, Pledge::monitor()['pledge_warned']);
        $this->assertSame($none, Pledge::monitor());
        $this->me()->getJson('/api/v1/pledges')->assertJsonPath('loans.0.warned', true)->assertJsonPath('loans.0.ltv_bps', 7177);
        // Recovery clears the warning.
        $this->price(2000);
        Pledge::monitor();
        $this->me()->getJson('/api/v1/pledges')->assertJsonPath('loans.0.warned', false);

        // 11.00: value 22,000.00, ltv 88.1% -> the bank takes the shares at 11.00.
        $this->price(1100);
        $this->assertSame(1, Pledge::monitor()['pledge_foreclosed']);
        $loan = DB::table('pledge_loans')->first();
        $this->assertSame(['foreclosed', 1100, 0], [$loan->status, (int) $loan->foreclosed_price, (int) $loan->written_off]);
        // 3,000 shares left with their share of the cost; the 2,000 went to the fund at 11.00.
        $this->assertSame([3000, 0, 0, 5400000], [(int) $this->position()->qty, (int) $this->position()->reserved_qty,
            (int) $this->position()->pledged_qty, (int) $this->position()->cost_total]);
        $fund = DB::table('positions')->where('user_id', $fundId)->first();
        $this->assertSame([2000, 2200000], [(int) $fund->qty, (int) $fund->cost_total]);
        // Cash: 100,000 + 19,000 loan + 22,000 for the shares - 19,380 debt.
        $this->assertSame(10000000 + 1900000 + 2200000 - 1938000, $this->cash());
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        $this->me()->getJson('/api/v1/bank')
            ->assertJsonPath('statement.0.type', 'pledge_repay')->assertJsonPath('statement.0.amount', -1938000)
            ->assertJsonPath('statement.1.type', 'pledge_foreclosure')->assertJsonPath('statement.1.amount', 2200000);
        $this->assertSame($none, Pledge::monitor());
    }

    public function test_overdue_loan_is_collected_from_cash_or_foreclosed_with_a_write_off(): void
    {
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 1000, 'amount' => 900000])->assertCreated();
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 1000, 'amount' => 900000])->assertCreated();
        [$first, $second] = DB::table('pledge_loans')->orderBy('id')->pluck('id')->all();

        // The first is due and cash covers it: collected, shares released.
        DB::table('pledge_loans')->where('id', $first)->update(['due_at' => now()->subMinute()]);
        $this->assertSame(1, Pledge::monitor()['pledge_collected']);
        $this->assertSame('repaid', DB::table('pledge_loans')->where('id', $first)->value('status'));
        $this->assertSame(1000, (int) $this->position()->pledged_qty);

        // The second is due, the price has collapsed to 5.00 and there is almost no cash:
        // shares bring 5,000.00, cash had 100.00, debt 9,180.00 -> 4,080.00 written off.
        DB::table('pledge_loans')->where('id', $second)->update(['due_at' => now()->subMinute()]);
        $spent = $this->cash() - 10000;
        DB::table('ledger_accounts')->where('user_id', $this->userId)->where('kind', 'cash')->update(['balance' => 10000]);
        DB::table('ledger_accounts')->where('kind', 'sys_treasury')->increment('balance', $spent);
        $this->price(500);
        $this->assertSame(1, Pledge::monitor()['pledge_foreclosed']);
        $loan = DB::table('pledge_loans')->where('id', $second)->first();
        $this->assertSame(['foreclosed', 408000], [$loan->status, (int) $loan->written_off]);
        $this->assertSame(0, $this->cash());
        $this->assertSame([4000, 0, 0], [(int) $this->position()->qty, (int) $this->position()->reserved_qty, (int) $this->position()->pledged_qty]);
        // No fund existed: the bank made a portfolio to hold the shares.
        $this->assertSame(1000, (int) DB::table('positions')->where('user_id', '<>', $this->userId)->value('qty'));
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
    }

    public function test_disabled_and_admin_terms(): void
    {
        DB::table('settings')->where('key', 'pledge_enabled')->update(['value' => 'false']);
        $this->me()->postJson('/api/v1/pledges', ['symbol' => '2222', 'qty' => 10, 'amount' => 100])->assertStatus(409);
        $this->me()->getJson('/api/v1/pledges')->assertJsonPath('enabled', false);

        DB::table('users')->where('id', $this->userId)->update(['role' => 'admin']);
        $this->app['auth']->forgetGuards(); // the guard remembers the user it loaded as a trader
        $this->me()->putJson('/api/v1/admin/settings', ['pledge_enabled' => true, 'pledge_ltv_bps' => 6000, 'pledge_fee_bps' => 150])
            ->assertOk()->assertJsonPath('settings.pledge_ltv_bps', 6000);
        // The levels must rise: loan limit, then warning, then foreclosure.
        $this->me()->putJson('/api/v1/admin/settings', ['pledge_ltv_bps' => 7000])->assertStatus(422);
        $this->me()->putJson('/api/v1/admin/settings', ['pledge_call_bps' => 9000])->assertStatus(422);
        $this->me()->getJson('/api/v1/pledges')->assertJsonPath('enabled', true)->assertJsonPath('terms.fee_bps', 150);
    }
}
