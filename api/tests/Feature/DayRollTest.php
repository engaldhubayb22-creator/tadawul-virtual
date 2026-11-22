<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DayRollTest extends TestCase
{
    use RefreshDatabase;

    /** Registers a member and returns [id, token]. */
    private function member(string $alias, string $role = 'trader'): array
    {
        $res = $this->postJson('/api/v1/register', ['name' => $alias, 'alias' => $alias, 'mobile' => '05000000'.(10 + strlen($alias)), 'password' => 'secret-pass-1'])->json();
        DB::table('users')->where('id', $res['user']['id'])->update(['role' => $role]);

        return [$res['user']['id'], $res['token']];
    }

    private function as(string $token) { $this->app['auth']->forgetGuards(); return $this->withToken($token); }

    public function test_closing_the_day_moves_market_history_back_one_day(): void
    {
        [, $admin] = $this->member('boss', 'admin');
        $company = (object) ['id' => DB::table('companies')->insertGetId(['symbol' => '9001', 'name' => 'شركة', 'shares_outstanding' => 1000000, 'tick_size' => 1, 'ref_price' => 1000])];
        $today = DB::selectOne("SELECT (now() AT TIME ZONE 'Asia/Riyadh')::date AS d")->d;
        $yesterday = date('Y-m-d', strtotime($today.' -1 day'));
        $before = date('Y-m-d', strtotime($today.' -2 day'));

        DB::table('daily_stats')->where('company_id', $company->id)->delete();
        DB::table('daily_stats')->insert([
            ['company_id' => $company->id, 'trade_date' => $yesterday, 'prev_close' => 900, 'low_limit' => 810, 'high_limit' => 990, 'close' => 1000],
            ['company_id' => $company->id, 'trade_date' => $today, 'prev_close' => 1000, 'low_limit' => 900, 'high_limit' => 1100, 'close' => null],
        ]);
        [$buyer] = $this->member('buyer1');
        [$seller] = $this->member('seller12');
        $order = fn ($user, $side, $status) => DB::table('orders')->insertGetId([
            'user_id' => $user, 'company_id' => $company->id, 'side' => $side, 'kind' => 'limit',
            'price' => 1050, 'qty' => 10, 'filled_qty' => $status === 'filled' ? 10 : 0, 'status' => $status,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $buy = $order($buyer, 'buy', 'filled');
        $sell = $order($seller, 'sell', 'filled');
        $resting = $order($buyer, 'buy', 'open');
        DB::table('trades')->insert([
            'company_id' => $company->id, 'buy_order_id' => $buy, 'sell_order_id' => $sell,
            'buy_user_id' => $buyer, 'sell_user_id' => $seller, 'price' => 1050, 'qty' => 10, 'taker_side' => 'buy',
        ]);
        $minute = DB::selectOne("SELECT date_trunc('minute', now()) AS m")->m;
        foreach ([0, 1] as $back) {   // the same minute today and yesterday
            DB::insert("INSERT INTO candles (company_id, interval, bucket, open, high, low, close, volume)
                VALUES (?, '1m', ?::timestamptz - make_interval(days => ?), 1050, 1050, 1050, 1050, 10)", [$company->id, $minute, $back]);
        }

        $this->as($admin)->postJson('/api/v1/admin/roll-day')->assertOk()->assertJsonPath('day', $today);

        $days = DB::table('daily_stats')->where('company_id', $company->id)->orderBy('trade_date')->pluck('close', 'trade_date')->all();
        $this->assertEquals([$before => 1000, $yesterday => 1050], $days);   // today closed at the last trade and became yesterday
        $this->assertSame(2, DB::table('candles')->where('company_id', $company->id)
            ->whereRaw("bucket < ?::timestamptz - interval '23 hours'", [$minute])->count());
        $this->assertSame(1, DB::table('trades')->whereRaw("(executed_at AT TIME ZONE 'Asia/Riyadh')::date = ?", [$yesterday])->count());
        $this->assertSame(1, DB::table('orders')->where('id', $resting)
            ->whereRaw("(created_at AT TIME ZONE 'Asia/Riyadh')::date = ?", [$yesterday])->count());   // the engine expires it as a day order
        $this->assertSame(1, (int) json_decode(DB::table('settings')->where('key', 'day_roll')->value('value')));

        $this->as($admin)->postJson('/api/v1/admin/roll-day')->assertOk();
        $this->assertSame(2, (int) json_decode(DB::table('settings')->where('key', 'day_roll')->value('value')));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'market.roll_day')->count() - 1);
    }

    public function test_only_an_admin_can_close_the_day(): void
    {
        $this->as($this->member('plain')[1])->postJson('/api/v1/admin/roll-day')->assertForbidden();
    }
}
