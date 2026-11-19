<?php

namespace Tests\Feature;

use App\Services\Ipo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IpoTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocation_rules(): void
    {
        // Enough for everyone: each gets what they asked for.
        $this->assertSame([1 => 100, 2 => 300], Ipo::allocate([1 => 100, 2 => 300], 1000, 10));

        // Oversubscribed 4x. Minimum 10 each first (30), then 970 pro rata to
        // what is still wanted (90 : 990 : 2890 of 3970).
        $a = Ipo::allocate([1 => 100, 2 => 1000, 3 => 2900], 1000, 10);
        $this->assertSame(1000, array_sum($a));
        $this->assertSame([1 => 32, 2 => 252, 3 => 716], $a);

        // More subscribers than the minimum can cover: equal shares, no one above their request.
        $b = Ipo::allocate([1 => 50, 2 => 50, 3 => 50, 4 => 2], 10, 50);
        $this->assertSame(10, array_sum($b));
        $this->assertSame(2, $b[4]);
        foreach ($b as $user => $qty) {
            $this->assertLessThanOrEqual([1 => 50, 2 => 50, 3 => 50, 4 => 2][$user], $qty);
        }

        // Never hands out more than offered or more than requested, whatever the mix.
        mt_srand(7);
        for ($i = 0; $i < 300; $i++) {
            $req = [];
            for ($u = 1, $n = mt_rand(1, 12); $u <= $n; $u++) {
                $req[$u] = mt_rand(1, 5000);
            }
            $offered = mt_rand(1, 20000);
            $out = Ipo::allocate($req, $offered, mt_rand(1, 200));
            $this->assertSame(min($offered, array_sum($req)), array_sum($out));
            foreach ($out as $u => $q) {
                $this->assertTrue($q >= 0 && $q <= $req[$u]);
            }
        }
    }

    public function test_full_ipo_from_announcement_to_listing(): void
    {
        $register = fn ($alias, $mobile) => $this->postJson('/api/v1/register', [
            'name' => $alias, 'alias' => $alias, 'mobile' => $mobile, 'password' => 'secret-pass-1',
        ])->json();
        $admin = $register('boss', '0500000001');
        $a = $register('sub_a', '0500000002');
        $b = $register('sub_b', '0500000003');
        DB::table('users')->where('id', $admin['user']['id'])->update(['role' => 'admin']);
        $maker = DB::table('users')->insertGetId(['name' => 'صندوق السيولة', 'alias' => 'mm-bot', 'mobile' => 'mm', 'password' => '!', 'role' => 'bot']);
        $as = function (array $who) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($who['token']);
        };

        $ipo = ['symbol' => '9001', 'name' => 'شركة الطرح', 'sector' => 'التقنية', 'price' => 4000, 'tick_size' => 5,
            'shares_offered' => 1000, 'maker_shares' => 5000, 'min_qty' => 10, 'max_qty' => 2000, 'minutes' => 60];
        $as($a)->postJson('/api/v1/admin/ipos', $ipo)->assertStatus(403);
        $as($admin)->postJson('/api/v1/admin/ipos', ['price' => 4001] + $ipo)->assertStatus(422); // off the tick grid
        $id = $as($admin)->postJson('/api/v1/admin/ipos', $ipo)->assertCreated()->json('id');
        $this->assertSame('pending', DB::table('companies')->where('symbol', '9001')->value('status'));

        // Subscribing holds the cash: 1,500 x 40.00 = 60,000.
        $as($a)->postJson("/api/v1/ipos/$id/subscribe", ['qty' => 5])->assertStatus(422);       // under the minimum
        $as($a)->postJson("/api/v1/ipos/$id/subscribe", ['qty' => 1500])->assertCreated()->assertJsonPath('reserved', 6000000);
        $as($a)->postJson("/api/v1/ipos/$id/subscribe", ['qty' => 100])->assertStatus(409);     // only once
        $as($b)->postJson("/api/v1/ipos/$id/subscribe", ['qty' => 2000])->assertCreated();
        $as($a)->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 4000000)->assertJsonPath('user.balances.reserved', 6000000);
        $as($a)->getJson('/api/v1/ipos')->assertJsonPath('ipos.0.requested', 3500)->assertJsonPath('ipos.0.coverage_bps', 35000)
            ->assertJsonPath('ipos.0.my_requested', 1500)->assertJsonPath('ipos.0.accepting', true);

        // Allocation: 10 each, then 980 split 1490 : 1990 -> 419.6 and 560.4. Whole shares give
        // 419 and 560; the one spare share goes to the larger fraction: 420 and 560, +10 = 430 and 570.
        $as($a)->postJson("/api/v1/admin/ipos/$id/allocate")->assertStatus(403);
        $as($admin)->postJson("/api/v1/admin/ipos/$id/allocate")->assertOk()
            ->assertJson(['subscribers' => 2, 'requested' => 3500, 'sold' => 1000, 'to_maker' => 5000]);
        $as($admin)->postJson("/api/v1/admin/ipos/$id/allocate")->assertStatus(409);

        $companyId = DB::table('companies')->where('symbol', '9001')->value('id');
        $shares = fn ($user) => (int) DB::table('positions')->where('user_id', $user)->where('company_id', $companyId)->value('qty');
        $this->assertSame(430, $shares($a['user']['id']));
        $this->assertSame(570, $shares($b['user']['id']));
        $this->assertSame(5000, $shares($maker));
        $this->assertSame('trading', DB::table('companies')->where('id', $companyId)->value('status'));

        // A paid 430 x 40.00 = 17,200 and got the rest back; nothing is still held.
        $as($a)->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 10000000 - 1720000)->assertJsonPath('user.balances.reserved', 0);
        $as($b)->getJson('/api/v1/me')->assertJsonPath('user.balances.cash', 10000000 - 2280000)->assertJsonPath('user.balances.reserved', 0);
        $this->assertSame(0, (int) DB::table('ledger_accounts')->sum('balance'));
        $as($a)->getJson('/api/v1/ipos')->assertJsonPath('ipos.0.status', 'listed')->assertJsonPath('ipos.0.my_allocated', 430);
        $as($a)->postJson("/api/v1/ipos/$id/subscribe", ['qty' => 100])->assertStatus(409);     // closed
    }

    public function test_cannot_subscribe_beyond_cash_or_after_closing(): void
    {
        $user = $this->postJson('/api/v1/register', [
            'name' => 'u', 'alias' => 'sub_c', 'mobile' => '0500000004', 'password' => 'secret-pass-1',
        ])->json();
        $companyId = DB::table('companies')->insertGetId(['symbol' => '9002', 'name' => 'x', 'shares_outstanding' => 1,
            'tick_size' => 1, 'ref_price' => 100000, 'status' => 'pending']);
        $open = DB::table('ipos')->insertGetId(['company_id' => $companyId, 'price' => 100000, 'shares_offered' => 100000,
            'min_qty' => 1, 'max_qty' => 100000, 'closes_at' => DB::raw("now() + interval '1 hour'")]);

        // 101 x 1,000.00 = 101,000 > the 100,000 starting balance.
        $this->withToken($user['token'])->postJson("/api/v1/ipos/$open/subscribe", ['qty' => 101])->assertStatus(422);
        $this->assertSame(0, DB::table('ipo_subscriptions')->count());
        $this->assertSame(10000000, (int) DB::table('ledger_accounts')->where('user_id', $user['user']['id'])->where('kind', 'cash')->value('balance'));

        DB::table('ipos')->where('id', $open)->update(['closes_at' => DB::raw("now() - interval '1 minute'")]);
        $this->withToken($user['token'])->postJson("/api/v1/ipos/$open/subscribe", ['qty' => 1])->assertStatus(409);
    }
}
