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
}
