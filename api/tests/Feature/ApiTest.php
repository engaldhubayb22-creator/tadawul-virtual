<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Needs a real PostgreSQL (the schema uses PostgreSQL features) and Redis.
 * The connection settings are in phpunit.xml and can be overridden by
 * environment variables.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('engine')->del('orders:in');
        DB::table('companies')->insert([
            'symbol' => '2222', 'name' => 'أرامكو السعودية',
            'shares_outstanding' => 1000000, 'tick_size' => 1, 'ref_price' => 2700,
        ]);
    }

    private function registerUser(string $alias = 'trader1', string $mobile = '0500000001'): array
    {
        return $this->postJson('/api/v1/register', [
            'name' => 'Test Trader', 'alias' => $alias, 'mobile' => $mobile, 'password' => 'secret-pass-1',
        ])->assertCreated()->json();
    }

    private function queued(): array
    {
        return array_map(
            fn ($fields) => json_decode($fields['data'], true),
            array_values(Redis::connection('engine')->xrange('orders:in', '-', '+'))
        );
    }

    public function test_register_grants_the_starting_balance_through_the_ledger(): void
    {
        $res = $this->registerUser();

        $this->assertSame(10000000, $res['starting_balance']);
        $this->assertSame(['cash' => 10000000, 'reserved' => 0], $res['user']['balances']);
        $this->assertNotEmpty($res['token']);

        // Money is moved, never created: every account together sums to zero.
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        $this->assertSame(1, DB::table('ledger_entries')->where('ref_type', 'starting_balance')->count());
        // The password is stored hashed.
        $this->assertNotSame('secret-pass-1', DB::table('users')->value('password'));
    }

    public function test_starting_balance_follows_the_admin_setting(): void
    {
        DB::table('settings')->where('key', 'starting_balance_halalas')->update(['value' => '2500000']);

        $this->assertSame(2500000, $this->registerUser()['starting_balance']);
    }

    public function test_register_rejects_duplicates_and_bad_input(): void
    {
        $this->registerUser();

        $this->postJson('/api/v1/register', [
            'name' => 'Other', 'alias' => 'trader1', 'mobile' => '0500000001', 'password' => 'secret-pass-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['alias', 'mobile']);

        $this->postJson('/api/v1/register', [
            'name' => 'Other', 'alias' => 'ok_name', 'mobile' => '12345', 'password' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['mobile', 'password']);

        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_login_and_me(): void
    {
        $this->registerUser();

        $this->postJson('/api/v1/login', ['mobile' => '0500000001', 'password' => 'wrong'])->assertStatus(401);

        $token = $this->postJson('/api/v1/login', ['mobile' => '0500000001', 'password' => 'secret-pass-1'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('user.alias', 'trader1')
            ->assertJsonPath('user.balances.cash', 10000000);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $this->registerUser();
        DB::table('users')->update(['status' => 'suspended']);

        $this->postJson('/api/v1/login', ['mobile' => '0500000001', 'password' => 'secret-pass-1'])
            ->assertStatus(403);
    }

    public function test_private_routes_need_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/portfolio')->assertStatus(401);
        $this->postJson('/api/v1/orders', [])->assertStatus(401);
        $this->assertSame([], $this->queued());
    }

    public function test_new_order_is_queued_for_the_engine(): void
    {
        $res = $this->registerUser();

        $this->withToken($res['token'])->postJson('/api/v1/orders', [
            'symbol' => '2222', 'side' => 'buy', 'kind' => 'limit', 'price' => 2700, 'qty' => 10, 'key' => 'abc-1',
        ])->assertStatus(202)->assertJson(['status' => 'queued', 'key' => 'abc-1']);

        $this->assertSame([[
            'type' => 'new', 'user_id' => $res['user']['id'], 'symbol' => '2222',
            'side' => 'buy', 'kind' => 'limit', 'qty' => 10, 'key' => 'abc-1', 'price' => 2700,
        ]], $this->queued());

        // The API itself does not create the order or touch the balance.
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_market_order_needs_no_price_and_gets_a_key(): void
    {
        $res = $this->registerUser();

        $key = $this->withToken($res['token'])->postJson('/api/v1/orders', [
            'symbol' => '2222', 'side' => 'sell', 'kind' => 'market', 'qty' => 5,
        ])->assertStatus(202)->json('key');

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $this->assertSame($key, $queued[0]['key']);
        $this->assertArrayNotHasKey('price', $queued[0]);
    }

    public function test_fill_options_are_passed_to_the_engine(): void
    {
        $token = $this->registerUser()['token'];
        $order = ['symbol' => '2222', 'side' => 'buy', 'kind' => 'limit', 'price' => 2700, 'qty' => 10];
        $this->withToken($token)->postJson('/api/v1/orders', $order + ['tif' => 'fok'])->assertStatus(202);
        $this->withToken($token)->postJson('/api/v1/orders', $order + ['tif' => 'ioc'])->assertStatus(202);
        $this->withToken($token)->postJson('/api/v1/orders', $order + ['tif' => 'day'])->assertStatus(202);
        $this->withToken($token)->postJson('/api/v1/orders', $order + ['tif' => 'whenever'])->assertStatus(422);

        $queued = $this->queued();
        $this->assertCount(3, $queued);
        $this->assertSame('fok', $queued[0]['tif']);
        $this->assertSame('ioc', $queued[1]['tif']);
        $this->assertArrayNotHasKey('tif', $queued[2]);
    }

    public function test_invalid_orders_are_not_queued(): void
    {
        $token = $this->registerUser()['token'];
        $bad = [
            ['symbol' => '9999', 'side' => 'buy', 'kind' => 'limit', 'price' => 2700, 'qty' => 1],
            ['symbol' => '2222', 'side' => 'hold', 'kind' => 'limit', 'price' => 2700, 'qty' => 1],
            ['symbol' => '2222', 'side' => 'buy', 'kind' => 'limit', 'qty' => 1],
            ['symbol' => '2222', 'side' => 'buy', 'kind' => 'limit', 'price' => 2700, 'qty' => 0],
            ['symbol' => '2222', 'side' => 'buy', 'kind' => 'limit', 'price' => 27.5, 'qty' => 1],
        ];
        foreach ($bad as $order) {
            $this->withToken($token)->postJson('/api/v1/orders', $order)->assertStatus(422);
        }
        $this->assertSame([], $this->queued());
    }

    public function test_cancel_only_queues_for_the_owner_of_an_open_order(): void
    {
        $owner = $this->registerUser();
        $other = $this->registerUser('trader2', '0500000002');
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $owner['user']['id'], 'company_id' => DB::table('companies')->value('id'),
            'side' => 'buy', 'kind' => 'limit', 'price' => 2700, 'qty' => 10, 'reserve_price' => 2700,
            'status' => 'open', 'idempotency_key' => 'k1',
        ]);

        $this->withToken($other['token'])->deleteJson("/api/v1/orders/$orderId")->assertStatus(404);
        $this->assertSame([], $this->queued());

        // Sanctum caches the authenticated user per test request cycle.
        $this->app['auth']->forgetGuards();
        $this->withToken($owner['token'])->deleteJson("/api/v1/orders/$orderId")->assertStatus(202);
        $this->assertSame([['type' => 'cancel', 'user_id' => $owner['user']['id'], 'order_id' => $orderId]], $this->queued());

        DB::table('orders')->where('id', $orderId)->update(['status' => 'filled', 'filled_qty' => 10]);
        $this->withToken($owner['token'])->deleteJson("/api/v1/orders/$orderId")->assertStatus(409);
        $this->assertCount(1, $this->queued());
    }

    public function test_market_list_and_portfolio(): void
    {
        $res = $this->registerUser();
        $companyId = DB::table('companies')->value('id');
        DB::table('daily_stats')->insert([
            'company_id' => $companyId, 'trade_date' => '2026-11-20',
            'prev_close' => 2700, 'low_limit' => 2430, 'high_limit' => 2970,
        ]);
        DB::table('positions')->insert([
            'user_id' => $res['user']['id'], 'company_id' => $companyId, 'qty' => 100, 'cost_total' => 250000,
        ]);

        $this->getJson('/api/v1/market')->assertOk()
            ->assertJsonPath('market.open', '10:00')
            ->assertJsonPath('market.close', '22:00')
            ->assertJsonPath('companies.0.symbol', '2222')
            ->assertJsonPath('companies.0.last', 2700)
            ->assertJsonPath('companies.0.high_limit', 2970);

        $this->getJson('/api/v1/market/9999/depth')->assertStatus(404);
        $this->getJson('/api/v1/market/2222/depth')->assertOk()->assertJson(['bids' => [], 'asks' => []]);

        // 100 shares bought at 2500, now 2700: value 270,000, profit 20,000.
        $this->withToken($res['token'])->getJson('/api/v1/portfolio')->assertOk()
            ->assertJsonPath('positions.0.avg_cost', 2500)
            ->assertJsonPath('positions.0.market_value', 270000)
            ->assertJsonPath('positions.0.unrealized_pnl', 20000)
            ->assertJsonPath('net_worth', 10270000);
    }

    public function test_my_trades_lists_own_executions_with_commission(): void
    {
        $buyer = $this->registerUser();
        $seller = $this->registerUser('trader2', '0500000002');
        $other = $this->registerUser('trader3', '0500000003');
        $companyId = DB::table('companies')->value('id');
        $order = fn (int $user, string $side) => DB::table('orders')->insertGetId([
            'user_id' => $user, 'company_id' => $companyId, 'side' => $side, 'kind' => 'limit',
            'price' => 2700, 'qty' => 10, 'filled_qty' => 10, 'status' => 'filled', 'idempotency_key' => $side.$user,
        ]);
        $buyOrder = $order($buyer['user']['id'], 'buy');
        $sellOrder = $order($seller['user']['id'], 'sell');
        $tradeId = DB::table('trades')->insertGetId([
            'company_id' => $companyId, 'buy_order_id' => $buyOrder, 'sell_order_id' => $sellOrder,
            'buy_user_id' => $buyer['user']['id'], 'sell_user_id' => $seller['user']['id'],
            'price' => 2700, 'qty' => 10, 'taker_side' => 'buy',
        ]);
        // Commission of 41 from each side, as the engine records it.
        $account = fn (int $user) => DB::table('ledger_accounts')->where('user_id', $user)->where('kind', 'cash')->value('id');
        $commission = DB::table('ledger_accounts')->whereNull('user_id')->where('kind', 'sys_commission')->value('id');
        foreach ([$buyer, $seller] as $side) {
            DB::table('ledger_entries')->insert([
                'debit_account_id' => $account($side['user']['id']), 'credit_account_id' => $commission,
                'amount' => 41, 'ref_type' => 'commission', 'ref_id' => $tradeId,
            ]);
        }

        $this->getJson('/api/v1/trades')->assertStatus(401);
        $this->withToken($buyer['token'])->getJson('/api/v1/trades')->assertOk()
            ->assertJsonCount(1, 'trades')
            ->assertJsonPath('trades.0.symbol', '2222')
            ->assertJsonPath('trades.0.side', 'buy')
            ->assertJsonPath('trades.0.order_id', $buyOrder)
            ->assertJsonPath('trades.0.value', 27000)
            ->assertJsonPath('trades.0.commission', 41)
            ->assertJsonPath('trades.0.net', 27041);
        $this->app['auth']->forgetGuards();
        $this->withToken($seller['token'])->getJson('/api/v1/trades')->assertOk()
            ->assertJsonPath('trades.0.side', 'sell')
            ->assertJsonPath('trades.0.order_id', $sellOrder)
            ->assertJsonPath('trades.0.net', 26959);
        $this->app['auth']->forgetGuards();
        $this->withToken($other['token'])->getJson('/api/v1/trades')->assertOk()->assertJsonCount(0, 'trades');

        // The whole market's trades are public and can be read incrementally.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/market-trades')->assertOk()
            ->assertJsonCount(1, 'trades')
            ->assertJsonPath('trades.0.id', $tradeId)
            ->assertJsonPath('trades.0.symbol', '2222')
            ->assertJsonPath('trades.0.taker_side', 'buy');
        $this->getJson('/api/v1/market-trades?after='.$tradeId)->assertOk()->assertJsonCount(0, 'trades');
    }

    public function test_bank_statement_shows_money_moves_with_running_balance(): void
    {
        $res = $this->registerUser();
        $userId = $res['user']['id'];
        $cash = DB::table('ledger_accounts')->where('user_id', $userId)->where('kind', 'cash')->value('id');
        $held = DB::table('ledger_accounts')->where('user_id', $userId)->where('kind', 'reserved')->value('id');
        $commission = DB::table('ledger_accounts')->whereNull('user_id')->where('kind', 'sys_commission')->value('id');
        DB::transaction(function () use ($cash, $held, $commission) {
            \App\Services\Ledger::move($cash, $held, 500000, 'order_reserve', 0); // internal: not in the statement
            \App\Services\Ledger::move($held, $commission, 300, 'commission', 0);
        });

        $this->getJson('/api/v1/bank')->assertStatus(401);
        // Starting balance 100,000.00, then 3.00 of commission taken from the held money.
        $this->withToken($res['token'])->getJson('/api/v1/bank')->assertOk()
            ->assertJsonPath('balances.cash', 9500000)
            ->assertJsonPath('balances.reserved', 499700)
            ->assertJsonCount(2, 'statement')
            ->assertJsonPath('statement.0.type', 'commission')
            ->assertJsonPath('statement.0.amount', -300)
            ->assertJsonPath('statement.0.balance_after', 9999700)
            ->assertJsonPath('statement.1.type', 'starting_balance')
            ->assertJsonPath('statement.1.amount', 10000000)
            ->assertJsonPath('statement.1.balance_after', 10000000);
    }

    public function test_leaderboard_ranks_traders_by_net_worth_and_hides_bots(): void
    {
        $poor = $this->registerUser('small_fish', '0500000011');
        $rich = $this->registerUser('big_whale', '0500000012');
        $companyId = DB::table('companies')->value('id');
        // The whale also holds 1,000 shares worth 27.00 each = 27,000 SAR.
        DB::table('positions')->insert(['user_id' => $rich['user']['id'], 'company_id' => $companyId, 'qty' => 1000, 'cost_total' => 1]);
        // A bot far richer than anyone must not appear.
        $bot = DB::table('users')->insertGetId(['name' => 'صندوق السيولة', 'alias' => 'mm-bot', 'mobile' => 'mm-bot', 'password' => '!', 'role' => 'bot']);
        DB::table('positions')->insert(['user_id' => $bot, 'company_id' => $companyId, 'qty' => 9000, 'cost_total' => 1]);

        $this->getJson('/api/v1/leaderboard')->assertOk()
            ->assertJsonCount(2, 'traders')
            ->assertJsonPath('traders.0.alias', 'big_whale')
            ->assertJsonPath('traders.0.rank', 1)
            ->assertJsonPath('traders.0.net_worth', 10000000 + 2700000)
            ->assertJsonPath('traders.0.holdings', 1)
            ->assertJsonPath('traders.1.alias', 'small_fish')
            ->assertJsonPath('traders.1.net_worth', 10000000);

        $this->getJson('/api/v1/market/2222/holders?limit=1')->assertOk()->assertJsonCount(1, 'holders');
        // Top holders: the fund shows under its name; shares are of all issued shares.
        $this->getJson('/api/v1/market/2222/holders')->assertOk()
            ->assertJsonPath('total_held', 10000)
            ->assertJsonPath('holders.0.holder', 'صندوق السيولة')
            ->assertJsonPath('holders.0.is_fund', true)
            ->assertJsonPath('holders.0.share_bps', 90)   // 9,000 of the 1,000,000 issued
            ->assertJsonPath('holders.1.holder', 'big_whale')
            ->assertJsonPath('holders.1.share_bps', 10);
        $this->getJson('/api/v1/market/9999/holders')->assertStatus(404);
        $this->assertNotNull($poor['token']);
    }

    public function test_fundamentals_and_free_float_index(): void
    {
        // 2222: 50M issued, 39M founders, equity 675M SAR, income 75M SAR, listed at 27.00.
        DB::table('companies')->where('symbol', '2222')->update([
            'shares_outstanding' => 50000000, 'founder_shares' => 39000000,
            'equity' => 67500000000, 'net_income' => 7500000000,
        ]);
        $sector = DB::table('sectors')->insertGetId(['name' => 'البنوك']);
        $bank = DB::table('companies')->insertGetId(['symbol' => '1120', 'name' => 'الراجحي', 'sector_id' => $sector,
            'shares_outstanding' => 20000000, 'founder_shares' => 10000000, 'ref_price' => 10000]);
        // The bank trades at 110.00 today after closing at 100.00; 2222 has not traded.
        foreach ([[DB::table('companies')->where('symbol', '2222')->value('id'), 2700], [$bank, 10000]] as [$id, $prev]) {
            DB::table('daily_stats')->insert(['company_id' => $id, 'trade_date' => '2026-11-20', 'prev_close' => $prev, 'low_limit' => 1, 'high_limit' => 99999900]);
        }
        $a = $this->registerUser('idx_a', '0500000021'); $b = $this->registerUser('idx_b', '0500000022');
        $mk = fn ($u, $side) => DB::table('orders')->insertGetId(['user_id' => $u, 'company_id' => $bank, 'side' => $side, 'kind' => 'limit',
            'price' => 11000, 'qty' => 1, 'filled_qty' => 1, 'status' => 'filled', 'idempotency_key' => $side]);
        DB::table('trades')->insert(['company_id' => $bank, 'buy_order_id' => $mk($a['user']['id'], 'buy'), 'sell_order_id' => $mk($b['user']['id'], 'sell'),
            'buy_user_id' => $a['user']['id'], 'sell_user_id' => $b['user']['id'], 'price' => 11000, 'qty' => 1, 'taker_side' => 'buy']);

        $res = $this->getJson('/api/v1/market')->assertOk();
        $c = collect($res->json('companies'))->keyBy('symbol');
        // Book value 675M / 50M = 13.50, EPS 1.50, P/E 27.00 / 1.50 = 18.00, P/B 2.00.
        $this->assertSame(50000000, $c['2222']['issued_shares']);
        $this->assertSame(11000000, $c['2222']['free_float']);
        $this->assertSame(1000, $c['2222']['par_value']);
        $this->assertSame(50000000 * 1000, $c['2222']['capital']);
        $this->assertSame(50000000 * 2700, $c['2222']['market_cap']);
        $this->assertSame(1350, $c['2222']['book_value']);
        $this->assertSame(150, $c['2222']['eps']);
        $this->assertSame(1800, $c['2222']['pe']);
        $this->assertSame(200, $c['2222']['pb']);
        $this->assertNull($c['1120']['pe']); // no income entered

        // Index: float x price now = 11M x 27.00 + 10M x 110.00 = 1,397M; at listing 1,297M.
        // 10,000 x 1397 / 1297 = 10,771.01; against yesterday's closes it is +7.71%.
        $this->assertSame(1077101, $res->json('index.value'));
        $this->assertSame(771, $res->json('index.change_bps'));
        // The bank sector alone is up exactly 10%.
        $bankIdx = collect($res->json('sectors'))->firstWhere('name', 'البنوك');
        $this->assertSame(1100000, $bankIdx['value']);
        $this->assertSame(1000, $bankIdx['change_bps']);

        // Holders: founders first, as a share of all issued shares (78%).
        $this->getJson('/api/v1/market/2222/holders')->assertOk()
            ->assertJsonPath('issued_shares', 50000000)->assertJsonPath('free_float', 11000000)
            ->assertJsonPath('holders.0.qty', 39000000)->assertJsonPath('holders.0.share_bps', 7800);
    }
}
