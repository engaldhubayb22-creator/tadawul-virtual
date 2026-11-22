<?php

namespace Tests\Feature;

use App\Services\Maker\Brain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MakerTest extends TestCase
{
    use RefreshDatabase;

    private int $fund;

    private string $admin;

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->insert(['name' => 'صندوق السيولة', 'alias' => 'mm-bot', 'mobile' => 'mm-bot', 'password' => '!', 'role' => 'bot']);
        $this->fund = (int) DB::table('users')->where('alias', 'mm-bot')->value('id');
        $res = $this->postJson('/api/v1/register', ['name' => 'a', 'alias' => 'boss', 'mobile' => '0500000009', 'password' => 'secret-pass-1'])->json();
        DB::table('users')->where('id', $res['user']['id'])->update(['role' => 'admin']);
        $this->admin = $res['token'];
        DB::table('settings')->where('key', 'maker_confirm_runs')->update(['value' => '1']);
    }

    private function me() { $this->app['auth']->forgetGuards(); return $this->withToken($this->admin); }

    /** A company whose fund holds $fundQty of a 1,000,000 free float, with one hourly bar per close. */
    private function company(string $symbol, array $closes, int $fundQty = 600000): int
    {
        $last = (int) round(end($closes));
        $id = DB::table('companies')->insertGetId([
            'symbol' => $symbol, 'name' => 'شركة '.$symbol, 'shares_outstanding' => 1000000, 'tick_size' => 1, 'ref_price' => $last,
        ]);
        DB::table('bot_configs')->insert(['company_id' => $id, 'levels' => 5, 'qty_min' => 100, 'qty_max' => 300, 'spread_bps' => 10]);
        DB::table('positions')->insert(['user_id' => $this->fund, 'company_id' => $id, 'qty' => $fundQty, 'cost_total' => $fundQty * $last]);
        $hour = now()->startOfHour();
        $rows = [];
        $n = count($closes);
        foreach (array_values($closes) as $i => $c) {
            $c = (int) round($c);
            $rows[] = ['company_id' => $id, 'interval' => '1m', 'bucket' => $hour->copy()->subHours($n - 1 - $i)->toIso8601String(),
                'open' => $c, 'high' => $c + 4, 'low' => $c - 4, 'close' => $c, 'volume' => 1000];
        }
        DB::table('candles')->insert($rows);

        return $id;
    }

    private function state(string $symbol): array
    {
        $r = DB::selectOne('SELECT s.* FROM maker_state s JOIN companies c ON c.id = s.company_id WHERE c.symbol = ?', [$symbol]);

        return ['regime' => $r->regime, 'auto' => $r->auto_regime, 'data' => json_decode($r->data, true), 'plan' => json_decode($r->plan, true), 'reason' => $r->reason];
    }

    private static function shape(string $kind, int $n = 120): array
    {
        return array_map(fn ($i) => match ($kind) {
            'up' => 2000 + 10 * $i + 6 * sin($i),
            'down' => 4000 - 10 * $i + 6 * sin($i),
            'range' => 3000 + 5 * (($i % 2) * 2 - 1) + 8 * sin($i / 3),
            'tired' => $i < $n - 8 ? 2000 + 10 * $i : 2000 + 10 * ($n - 8) + 2 * ($i - $n + 8),
        }, range(0, $n - 1));
    }

    private function member(string $alias): int
    {
        return DB::table('users')->insertGetId(['name' => $alias, 'alias' => $alias, 'mobile' => $alias, 'password' => '!']);
    }

    /** A trade executed today. */
    private function trade(int $company, int $buyer, int $seller, int $qty, int $price = 3000): void
    {
        $order = fn (int $user, string $side) => DB::table('orders')->insertGetId([
            'user_id' => $user, 'company_id' => $company, 'side' => $side, 'kind' => 'limit', 'price' => $price,
            'qty' => $qty, 'filled_qty' => $qty, 'status' => 'filled', 'idempotency_key' => 'mk'.(++$this->orders),
        ]);
        DB::table('trades')->insert([
            'company_id' => $company, 'buy_order_id' => $order($buyer, 'buy'), 'sell_order_id' => $order($seller, 'sell'),
            'buy_user_id' => $buyer, 'sell_user_id' => $seller, 'price' => $price, 'qty' => $qty, 'taker_side' => 'buy',
        ]);
    }

    /** Completed sessions ending yesterday, oldest first, as [prev_close, close] pairs. */
    private function sessions(int $company, array $days): void
    {
        $date = now('Asia/Riyadh')->startOfDay()->subDays(count($days));
        foreach ($days as [$prev, $close]) {
            DB::table('daily_stats')->insert(['company_id' => $company, 'trade_date' => $date->toDateString(),
                'prev_close' => $prev, 'low_limit' => (int) ($prev * 0.9), 'high_limit' => (int) ($prev * 1.1), 'close' => $close]);
            $date->addDay();
        }
    }

    private static function total(array $side): int
    {
        return array_sum(array_column($side, 'qty'));
    }

    public function test_each_kind_of_move_gets_its_own_orders(): void
    {
        foreach (['up', 'down', 'range', 'tired'] as $k) {
            $this->company('S'.$k, self::shape($k));
        }
        $this->company('NEW', self::shape('up', 30));
        $done = Brain::run();
        $this->assertSame(5, $done['companies']);

        // The usual size here is 5 levels of 200 = 1,000 a side.
        $up = $this->state('Sup');
        $this->assertSame('up_strong', $up['regime']);
        $this->assertSame('above', $up['data']['reading']['cloud']['position']);
        // RSI is far above 70: full bids, lighter offers, and bids do not follow the price. No selling because of the number.
        $this->assertSame([1000, 800, false], [self::total($up['plan']['bids']), self::total($up['plan']['asks']), $up['plan']['requote']['follow_price_up']]);
        $this->assertStringContainsString('لا يطارد السعر', $up['reason']);
        // Bids sit under the last price and offers above it, on the price grid, further out level by level.
        $this->assertLessThan(3188, $up['plan']['bids'][0]['price']);
        $this->assertGreaterThan(3188, $up['plan']['asks'][0]['price']);
        $this->assertGreaterThan($up['plan']['bids'][1]['price'], $up['plan']['bids'][0]['price']);
        $this->assertSame(0, $up['plan']['asks'][0]['price'] % 2);

        $down = $this->state('Sdown');
        $this->assertSame(['down', 700, 1000], [$down['regime'], self::total($down['plan']['bids']), self::total($down['plan']['asks'])]);
        // Bids in a falling share sit deeper than in a rising one.
        $this->assertGreaterThan(3188 - $up['plan']['bids'][0]['price'], 2808 - $down['plan']['bids'][0]['price']);

        $tired = $this->state('Stired');
        $this->assertSame(['up_tired', 'fading', 600], [$tired['regime'], $tired['data']['reading']['macd']['momentum'], self::total($tired['plan']['bids'])]);
        $this->assertFalse($tired['plan']['requote']['follow_price_up']);

        $range = $this->state('Srange');
        $this->assertSame('range', $range['regime']);
        $this->assertLessThan(20, $range['data']['reading']['adx']);

        // Too little history: the usual orders, and it says how long until the reading is ready.
        $new = $this->state('NEW');
        $this->assertSame(['warming', 52, 1000, 1000], [$new['regime'], $new['data']['reading']['ready_in'], self::total($new['plan']['bids']), self::total($new['plan']['asks'])]);
        $this->assertNotNull(DB::table('maker_market')->value('regime'));
    }

    public function test_fund_rules_cap_the_offers_whatever_the_signal(): void
    {
        $cap = $this->company('CAP', self::shape('range'));            // fund holds 600,000: daily cap 6,000
        $res = $this->company('RES', self::shape('range'), 150500);    // 500 above the 150,000 reserve
        $buyer = $this->member('buyer');
        $this->trade($cap, $buyer, $this->fund, 5800);
        Brain::run();

        // 5,800 of the 6,000 cap are sold: only 200 may still be offered, though the signal asks for 1,000.
        $c = $this->state('CAP');
        $this->assertSame([6000, 5800, 200], [$c['plan']['fund']['cap'], $c['plan']['fund']['sold'], $c['plan']['fund']['left']]);
        $this->assertSame(200, self::total($c['plan']['asks']));
        $this->assertStringContainsString('سقف البيع اليومي', $c['reason']);

        // The reserve: 15% of the free float stays, so 500 are for sale.
        $r = $this->state('RES');
        $this->assertSame([150000, 500, 500], [$r['plan']['fund']['reserve'], $r['plan']['fund']['sellable'], self::total($r['plan']['asks'])]);
        $this->assertStringContainsString('الاحتياطي المقفل', $r['reason']);

        // The cap is measured against the holding at the start of the session, not the shrinking one.
        DB::table('positions')->where(['user_id' => $this->fund, 'company_id' => $cap])->update(['qty' => 594200]);
        Brain::run();
        $this->assertSame([600000, 6000], [$this->state('CAP')['plan']['fund']['start'], $this->state('CAP')['plan']['fund']['cap']]);

        // Only the admin opens the reserve.
        $this->me()->putJson('/api/v1/admin/maker/reserve/RES', ['open' => true])->assertOk();
        $this->assertSame(1000, self::total($this->state('RES')['plan']['asks']));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'maker.reserve')->count());
    }

    public function test_an_unexplained_run_doubles_the_offer_and_news_explains_it(): void
    {
        $run = $this->company('RUN', self::shape('range'));
        $slow = $this->company('SLOW', self::shape('range'));
        $told = $this->company('TOLD', self::shape('range'));
        // Four rising sessions adding up to 16%: a run. Three sessions, or a smaller rise, are not.
        $four = [[2500, 2600], [2600, 2700], [2700, 2800], [2800, 2900]];
        $this->sessions($run, $four);
        $this->sessions($slow, [[2500, 2400], [2400, 2600], [2600, 2800], [2800, 2900]]);
        $this->sessions($told, $four);
        DB::table('news')->insert(['company_id' => $told, 'title' => 'أرباح قياسية', 'impact_bps' => 400, 'created_at' => now()->subDays(2)]);
        Brain::run();

        $r = $this->state('RUN');
        $this->assertEquals(['days' => 4, 'rise_bps' => 1600, 'from' => 2500], array_intersect_key($r['data']['wave'], ['days' => 1, 'rise_bps' => 1, 'from' => 1]));
        // Twice the usual offer, and the cap for the day is 2% instead of 1%.
        $this->assertSame([2000, 12000], [self::total($r['plan']['asks']), $r['plan']['fund']['cap']]);
        $this->assertStringContainsString('يضاعف العرض', $r['reason']);
        $this->assertNull($this->state('SLOW')['data']['wave']);
        $this->assertNull($this->state('TOLD')['data']['wave']);
        $this->assertSame(1000, self::total($this->state('TOLD')['plan']['asks']));
        $this->assertSame(1, DB::table('maker_alerts')->where('type', 'wave')->count());
    }

    public function test_accumulation_makes_the_fund_buy_back_patiently_and_alerts_the_admin(): void
    {
        $held = $this->company('HELD', self::shape('range'));
        $bought = $this->company('BGHT', self::shape('range'));
        $ring = $this->company('RING', self::shape('range'));
        $quiet = $this->company('QUIET', self::shape('range'));
        $whale = $this->member('whale');
        $other = $this->member('other');

        // HELD: a member owns 12% of the free float. The fund sold 2,000 today and bought 300.
        DB::table('positions')->insert(['user_id' => $whale, 'company_id' => $held, 'qty' => 120000, 'cost_total' => 1]);
        $this->trade($held, $other, $this->fund, 2000, 3010);
        $this->trade($held, $this->fund, $other, 300, 2990);
        // BGHT: one member took 4,000 of the day's 9,000 shares.
        $this->trade($bought, $whale, $this->fund, 4000);
        $this->trade($bought, $other, $this->fund, 500);
        $this->trade($bought, $this->member('x1'), $this->fund, 500);
        $this->trade($bought, $this->fund, $this->member('x2'), 4000);
        // RING: three members took 30% each of the day's volume.
        foreach (['r1', 'r2', 'r3'] as $alias) {
            $this->trade($ring, $this->member($alias), $this->fund, 3000);
        }
        $this->trade($ring, $other, $this->fund, 500);
        $this->trade($quiet, $other, $this->fund, 100);   // all of a tiny day's volume: not accumulation
        Brain::run();

        // Target: 20% more than the 2,000 sold = 2,400; 300 are in, 2,100 to go, all in resting bids no higher than today's average price.
        $h = $this->state('HELD');
        $this->assertSame(['whale', 'holder', 2400, 2100], [$h['data']['accumulation']['alias'], $h['data']['accumulation']['why'],
            $h['data']['accumulation']['buy_target'], $h['data']['accumulation']['still_to_buy']]);
        $this->assertSame(2100, self::total($h['plan']['bids']));
        $this->assertLessThanOrEqual($h['data']['flow']['vwap'], $h['plan']['bids'][0]['price']);
        $this->assertFalse($h['plan']['requote']['follow_price_up']);

        $b = $this->state('BGHT');
        $this->assertSame(['whale', 'volume'], [$b['data']['accumulation']['alias'], $b['data']['accumulation']['why']]);
        $this->assertNull($this->state('QUIET')['data']['accumulation']);

        $types = DB::table('maker_alerts')->orderBy('type')->pluck('type')->all();
        $this->assertContains('concentration_1000', $types);   // 12% is past the 10% mark, not yet the 20% one
        $this->assertNotContains('concentration_2000', $types);
        // HELD and BGHT, and RING too: its biggest buyer alone took 30% of the day.
        $this->assertSame(3, count(array_keys($types, 'accumulation')));
        // Only RING looks like a group: in BGHT the two small buyers are not a tenth of the day each.
        $this->assertSame(1, DB::table('maker_alerts')->where('type', 'group')->count());
        $this->assertEqualsCanonicalizing(['r1', 'r2', 'r3'], json_decode(DB::table('maker_alerts')->where('type', 'group')->value('details'), true)['members']);
        // The same alert is not raised twice in a day.
        $count = count($types);
        Brain::run();
        $this->assertSame($count, DB::table('maker_alerts')->count());
    }

    public function test_the_market_is_context_and_a_strong_share_keeps_its_bids(): void
    {
        // The market falls: a heavy share leads it down. FOLLOW moves with it but has no trend of its own.
        $wobble = fn (int $i) => 40 * sin($i * 1.7);
        $lead = $this->company('LEAD', array_map(fn ($i) => 9000 - 25 * $i + $wobble($i), range(0, 119)));
        DB::table('companies')->where('id', $lead)->update(['shares_outstanding' => 50000000]);
        $this->company('FOLLOW', array_map(fn ($i) => 3000 + $wobble($i), range(0, 119)));
        $strong = $this->company('STRONG', self::shape('up'));
        // Members are really buying STRONG today.
        $this->trade($strong, $this->member('m1'), $this->fund, 400, 3190);
        $this->trade($strong, $this->member('m2'), $this->fund, 300, 3190);
        Brain::run();

        $this->assertContains(DB::table('maker_market')->value('regime'), ['down', 'down_tired']);
        $f = $this->state('FOLLOW');
        $this->assertTrue($f['data']['reading']['relation']['follows_market']);
        $this->assertSame('range', $f['regime']);
        $this->assertSame(700, self::total($f['plan']['bids']));
        $this->assertStringContainsString('السهم يتبع السوق والسوق نازل', $f['reason']);

        // Above its averages and its cloud, with members behind the move: the red index does not cut its bids.
        $s = $this->state('STRONG');
        $this->assertTrue($s['data']['flow']['member_backed']);
        $this->assertSame(['up_strong', 1000], [$s['regime'], self::total($s['plan']['bids'])]);
        $this->assertStringContainsString('لا يخفض الطلبات', $s['reason']);
    }

    public function test_a_new_reading_must_last_before_the_maker_acts_on_it(): void
    {
        DB::table('settings')->where('key', 'maker_confirm_runs')->update(['value' => '3']);
        $id = $this->company('FLIP', self::shape('up'));
        Brain::run();
        $this->assertSame('up_strong', $this->state('FLIP')['regime']);

        // The chart now reads as falling. Two runs are not enough; the third settles it.
        DB::table('candles')->where('company_id', $id)->delete();
        DB::table('maker_bars')->where('company_id', $id)->delete();
        $hour = now()->startOfHour();
        foreach (self::shape('down') as $i => $c) {
            DB::table('candles')->insert(['company_id' => $id, 'interval' => '1m', 'bucket' => $hour->copy()->subHours(119 - $i)->toIso8601String(),
                'open' => (int) $c, 'high' => (int) $c + 4, 'low' => (int) $c - 4, 'close' => (int) $c, 'volume' => 1000]);
        }
        Brain::run();
        $this->assertEquals(['up_strong', ['regime' => 'down', 'runs' => 1]], [$this->state('FLIP')['regime'], $this->state('FLIP')['data']['pending']]);
        Brain::run();
        $this->assertSame('up_strong', $this->state('FLIP')['regime']);
        Brain::run();
        $this->assertSame(['down', null], [$this->state('FLIP')['regime'], $this->state('FLIP')['data']['pending']]);
    }

    public function test_admin_room_overrides_and_alert_answers(): void
    {
        $up = $this->company('UPP', self::shape('up'));
        $this->company('FLAT', self::shape('range'));
        // A golden cross: a long slide, then a firm climb that has just carried the 20 over the 50.
        $path = array_map(fn ($i) => $i < 80 ? 4000 - 8 * $i : 3360 + 16 * ($i - 80), range(0, 139));
        $e20 = \App\Services\Maker\Indicators::ema($path, 20);
        $e50 = \App\Services\Maker\Indicators::ema($path, 50);
        $at = 80;
        while ($e20[$at] - $e50[$at] <= 4) {   // the bar where the 20 has clearly gone over the 50
            $at++;
        }
        $cross = $this->company('CROSS', array_slice($path, 0, $at + 1));
        Brain::run();

        $trader = $this->postJson('/api/v1/register', ['name' => 't', 'alias' => 'trader', 'mobile' => '0500000008', 'password' => 'secret-pass-1'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($trader)->getJson('/api/v1/admin/maker')->assertStatus(403);

        $room = $this->me()->getJson('/api/v1/admin/maker')->assertOk()
            ->assertJsonPath('settings.maker_brain', 'shadow')->assertJsonCount(3, 'companies')
            ->assertJsonPath('companies.2.symbol', 'UPP')->assertJsonPath('companies.2.regime', 'up_strong')
            ->assertJsonPath('companies.2.label', 'مسار صاعد قوي')->json();
        $this->assertNotNull($room['market']['regime']);
        $this->me()->getJson('/api/v1/admin/maker/company/UPP')->assertOk()
            ->assertJsonPath('plan.fund.reserve', 150000)->assertJsonPath('data.reading.cloud.position', 'above');
        $this->me()->getJson('/api/v1/admin/maker/company/NONE')->assertStatus(404);

        // Go against the reading: the maker treats the rising share as a falling one, for a day.
        $this->me()->putJson('/api/v1/admin/maker/override', ['symbol' => 'UPP', 'mode' => 'oppose', 'hours' => 24])->assertOk();
        $s = $this->state('UPP');
        $this->assertSame(['down', 'up_strong', 'oppose'], [$s['regime'], $s['auto'], $s['data']['override']['mode']]);
        $this->assertSame(700, self::total($s['plan']['bids']));
        // Force a direction, then hand it back to the analysis.
        $this->me()->putJson('/api/v1/admin/maker/override', ['symbol' => 'FLAT', 'mode' => 'up'])->assertOk();
        $this->assertSame('up_strong', $this->state('FLAT')['regime']);
        $this->me()->putJson('/api/v1/admin/maker/override', ['symbol' => 'FLAT', 'mode' => 'auto'])->assertOk();
        $this->assertSame(['range', null], [$this->state('FLAT')['regime'], $this->state('FLAT')['data']['override']]);
        // An override lapses on its own.
        DB::table('maker_overrides')->where('company_id', $up)->update(['expires_at' => now()->subMinute()]);
        Brain::run();
        $this->assertSame('up_strong', $this->state('UPP')['regime']);
        // The market as a whole can be steered too.
        $this->me()->putJson('/api/v1/admin/maker/override', ['mode' => 'down', 'hours' => 2])->assertOk();
        $this->assertSame('down', DB::table('maker_market')->value('regime'));
        $this->me()->putJson('/api/v1/admin/maker/override', ['mode' => 'sideways'])->assertStatus(422);

        // A pattern alert: the admin tells the maker to go against it.
        $alert = DB::table('maker_alerts')->where('company_id', $cross)->where('type', 'pattern_golden_cross')->first();
        $this->assertNotNull($alert, 'patterns seen: '.DB::table('maker_alerts')->pluck('type')->implode(',').' reading '.json_encode($this->state('CROSS')['data']['reading']));
        $this->assertSame('احتمال بداية مسار صاعد', json_decode($alert->details, true)['expect']);
        $this->me()->postJson("/api/v1/admin/maker/alerts/{$alert->id}", ['action' => 'oppose', 'hours' => 6])->assertOk();
        $this->assertSame('oppose', $this->state('CROSS')['data']['override']['mode']);
        $this->me()->postJson("/api/v1/admin/maker/alerts/{$alert->id}", ['action' => 'dismiss'])->assertStatus(404);
        $this->assertSame(0, collect($this->me()->getJson('/api/v1/admin/maker')->json('alerts'))->where('id', $alert->id)->count());

        // Settings, and switching the analysis off.
        $this->me()->putJson('/api/v1/admin/maker/settings', ['maker_reserve_bps' => 2000, 'maker_brain' => 'off'])->assertOk();
        $this->me()->putJson('/api/v1/admin/maker/settings', ['maker_brain' => 'live'])->assertStatus(422);
        $this->artisan('maker:analyze')->expectsOutput('maker brain is off')->assertSuccessful();
        $this->me()->postJson('/api/v1/admin/maker/run')->assertOk()->assertJsonPath('companies', 3);
        $this->assertSame(200000, $this->state('UPP')['plan']['fund']['reserve']);
    }
}
