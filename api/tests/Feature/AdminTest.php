<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;

    private string $trader;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('companies')->insert([
            'symbol' => '1120', 'name' => 'مصرف الراجحي',
            'shares_outstanding' => 1000000, 'tick_size' => 5, 'ref_price' => 9500,
        ]);
        $register = fn ($alias, $mobile) => $this->postJson('/api/v1/register', [
            'name' => $alias, 'alias' => $alias, 'mobile' => $mobile, 'password' => 'secret-pass-1',
        ])->json('token');
        $this->admin = $register('boss', '0500000009');
        $this->trader = $register('trader', '0500000008');
        $this->artisan('app:make-admin', ['mobile' => '0500000009'])->assertSuccessful();
        $this->app['auth']->forgetGuards();
    }

    public function test_only_admins_reach_the_panel(): void
    {
        $this->getJson('/api/v1/admin/overview')->assertStatus(401);
        $this->withToken($this->trader)->getJson('/api/v1/admin/overview')->assertStatus(403);
        $this->withToken($this->trader)->putJson('/api/v1/admin/settings', ['bots_enabled' => false])->assertStatus(403);
        $this->assertSame('true', DB::table('settings')->where('key', 'bots_enabled')->value('value'));
    }

    public function test_overview_shows_settings_companies_and_a_balanced_ledger(): void
    {
        $this->withToken($this->admin)->getJson('/api/v1/admin/overview')->assertOk()
            ->assertJsonPath('settings.market_open', '10:00')
            ->assertJsonPath('settings.bots_enabled', true)
            ->assertJsonPath('companies.0.symbol', '1120')
            ->assertJsonPath('companies.0.spread_bps', 10)
            ->assertJsonPath('stats.users', 1)
            ->assertJsonPath('stats.ledger_sum', 0);
    }

    public function test_settings_update_is_validated_and_audited(): void
    {
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', [
            'starting_balance_halalas' => 5000000, 'market_open' => '09:30', 'market_close' => '15:00', 'bots_enabled' => false,
        ])->assertOk()->assertJsonPath('settings.market_open', '09:30')->assertJsonPath('settings.bots_enabled', false);

        $this->assertSame('5000000', DB::table('settings')->where('key', 'starting_balance_halalas')->value('value'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settings.update')->count());

        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['market_open' => '16:00', 'market_close' => '15:00'])
            ->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['market_open' => 'noon'])->assertStatus(422);
        $this->assertSame('"09:30"', DB::table('settings')->where('key', 'market_open')->value('value'));
    }

    public function test_commission_and_trading_days_settings(): void
    {
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['commission_bps' => 15.5, 'market_days' => [4, 0, 1, 2, 3]])
            ->assertOk()->assertJsonPath('settings.commission_bps', 15.5)->assertJsonPath('settings.market_days', [0, 1, 2, 3, 4]);
        $this->assertSame('15.5', DB::table('settings')->where('key', 'commission_bps')->value('value'));
        $this->assertSame('[0, 1, 2, 3, 4]', DB::table('settings')->where('key', 'market_days')->value('value'));

        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['market_days' => []])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['market_days' => [7]])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['commission_bps' => -1])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['margin_call_bps' => 2000, 'liquidation_bps' => 2500])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/settings', ['financing_fee_bps' => 150, 'financing_days' => 14])
            ->assertOk()->assertJsonPath('settings.financing_fee_bps', 150)->assertJsonPath('settings.financing_days', 14);
    }

    public function test_bot_tuning_and_scenario(): void
    {
        $this->withToken($this->admin)->putJson('/api/v1/admin/bots/1120', [
            'spread_bps' => 25, 'levels' => 3, 'target_price' => 9900, 'target_minutes' => 30,
        ])->assertOk()->assertJsonPath('companies.0.spread_bps', 25)->assertJsonPath('companies.0.target_price', 9900);

        $row = DB::table('bot_configs')->first();
        $this->assertSame(3, $row->levels);
        $this->assertNotNull($row->target_until);

        // Clearing the scenario keeps the tuning.
        $this->withToken($this->admin)->putJson('/api/v1/admin/bots/1120', ['target_price' => null])
            ->assertOk()->assertJsonPath('companies.0.target_price', null)->assertJsonPath('companies.0.spread_bps', 25);

        $this->withToken($this->admin)->putJson('/api/v1/admin/bots/1120', ['spread_bps' => 9999])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/bots/1120', ['qty_min' => 5000])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/bots/1120', ['target_price' => 9900])->assertStatus(422);
        $this->withToken($this->admin)->putJson('/api/v1/admin/bots/9999', ['levels' => 2])->assertStatus(404);
    }

    public function test_halt_and_resume(): void
    {
        $this->withToken($this->admin)->putJson('/api/v1/admin/companies/1120/status', ['status' => 'halted'])
            ->assertOk()->assertJsonPath('companies.0.status', 'halted');
        $this->withToken($this->admin)->putJson('/api/v1/admin/companies/1120/status', ['status' => 'delisted'])
            ->assertStatus(422);
        $this->assertSame('halted', DB::table('companies')->value('status'));
    }

    public function test_news_with_impact_sets_a_price_target_on_the_tick_grid(): void
    {
        // Last price is the listing price 95.00; +3.33% is 98.1635 -> 98.15 on a 0.05 grid.
        $this->withToken($this->admin)->postJson('/api/v1/admin/news', [
            'symbol' => '1120', 'title' => 'أرباح قياسية', 'impact_bps' => 333, 'minutes' => 20,
        ])->assertOk()->assertJsonPath('companies.0.target_price', 9815)->assertJsonPath('news.0.title', 'أرباح قياسية');

        $this->getJson('/api/v1/news')->assertOk()->assertJsonPath('news.0.symbol', '1120');

        // News without impact is published but moves nothing.
        DB::table('bot_configs')->delete();
        $this->withToken($this->admin)->postJson('/api/v1/admin/news', [
            'symbol' => '1120', 'title' => 'إعلان عام', 'impact_bps' => 0,
        ])->assertOk();
        $this->assertSame(0, DB::table('bot_configs')->count());
        $this->assertSame(2, DB::table('news')->count());
    }

    public function test_admin_manages_members(): void
    {
        $traderId = DB::table('users')->where('alias', 'trader')->value('id');
        $adminId = DB::table('users')->where('alias', 'boss')->value('id');
        DB::table('users')->insert(['name' => 'صندوق', 'alias' => 'mm-bot', 'mobile' => 'mm-bot', 'password' => '!', 'role' => 'bot']);
        $botId = DB::table('users')->where('alias', 'mm-bot')->value('id');
        $as = function (string $token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token);
        };

        $as($this->trader)->getJson('/api/v1/admin/users')->assertStatus(403);
        // Members only: the bot is not listed. Newest first.
        $as($this->admin)->getJson('/api/v1/admin/users')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(2, 'users')
            ->assertJsonPath('users.0.alias', 'trader')
            ->assertJsonPath('users.0.cash', 10000000)
            ->assertJsonPath('users.1.role', 'admin');
        $as($this->admin)->getJson('/api/v1/admin/users?q=0500000008')->assertOk()->assertJsonCount(1, 'users');
        $as($this->admin)->putJson("/api/v1/admin/users/$botId/status", ['status' => 'banned'])->assertStatus(404);

        // Deposit 5,000.00 then take 2,000.00 back; the ledger stays balanced.
        $as($this->admin)->postJson("/api/v1/admin/users/$traderId/balance", ['amount' => 500000])->assertOk()->assertJsonPath('cash', 10500000);
        $as($this->admin)->postJson("/api/v1/admin/users/$traderId/balance", ['amount' => -200000])->assertOk()->assertJsonPath('cash', 10300000);
        $as($this->admin)->postJson("/api/v1/admin/users/$traderId/balance", ['amount' => -99999999999])->assertStatus(422);
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        $as($this->trader)->getJson('/api/v1/bank')->assertOk()
            ->assertJsonPath('statement.0.type', 'admin_withdraw')->assertJsonPath('statement.0.amount', -200000)
            ->assertJsonPath('statement.1.type', 'admin_deposit')->assertJsonPath('statement.1.balance_after', 10500000);

        // An admin cannot lock themselves out.
        $as($this->admin)->putJson("/api/v1/admin/users/$adminId/status", ['status' => 'suspended'])->assertStatus(422);
        $as($this->admin)->putJson("/api/v1/admin/users/$adminId/role", ['role' => 'trader'])->assertStatus(422);

        // Suspending signs the member out and blocks signing in again.
        $as($this->admin)->putJson("/api/v1/admin/users/$traderId/status", ['status' => 'suspended'])->assertOk();
        $as($this->trader)->getJson('/api/v1/portfolio')->assertStatus(401);
        $this->postJson('/api/v1/login', ['mobile' => '0500000008', 'password' => 'secret-pass-1'])->assertStatus(403);
        $as($this->admin)->putJson("/api/v1/admin/users/$traderId/status", ['status' => 'active'])->assertOk();

        // A new password replaces the old one.
        $as($this->admin)->putJson("/api/v1/admin/users/$traderId/password", ['password' => 'short'])->assertStatus(422);
        $as($this->admin)->putJson("/api/v1/admin/users/$traderId/password", ['password' => 'brand-new-pass'])->assertOk();
        $this->postJson('/api/v1/login', ['mobile' => '0500000008', 'password' => 'secret-pass-1'])->assertStatus(401);
        $token = $this->postJson('/api/v1/login', ['mobile' => '0500000008', 'password' => 'brand-new-pass'])->assertOk()->json('token');

        // Promote to admin: the member reaches the panel.
        $as($this->admin)->putJson("/api/v1/admin/users/$traderId/role", ['role' => 'admin'])->assertOk();
        $as($token)->getJson('/api/v1/admin/overview')->assertOk();
        $this->assertSame(6, DB::table('audit_logs')->where('action', 'like', 'user.%')->count());
    }

    public function test_whales_list_includes_admins(): void
    {
        $this->getJson('/api/v1/leaderboard')->assertOk()->assertJsonCount(2, 'traders');
    }
}
