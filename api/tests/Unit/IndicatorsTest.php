<?php

namespace Tests\Unit;

use App\Services\Maker\Indicators as I;
use PHPUnit\Framework\TestCase;

class IndicatorsTest extends TestCase
{
    public function test_averages(): void
    {
        $this->assertSame([null, null, 2.0, 3.0, 4.0], I::sma([1, 2, 3, 4, 5], 3));
        // EMA(3): seed = average of the first three, then k = 0.5.
        $this->assertEquals([null, null, 2.0, 3.0, 4.0], I::ema([1, 2, 3, 4, 5], 3));
        $this->assertEqualsWithDelta(7.0, I::ema(array_fill(0, 40, 7.0), 20)[39], 1e-9);
        $this->assertSame([null, null], I::ema([1, 2], 3));
    }

    public function test_rsi_matches_the_textbook_series(): void
    {
        // The worked example from Wilder's method: 14-period RSI of these closes is 70.46 then 66.25.
        $close = [44.34, 44.09, 44.15, 43.61, 44.33, 44.83, 45.10, 45.42, 45.84, 46.08, 45.89, 46.03, 45.61, 46.28, 46.28, 46.00];
        $rsi = I::rsi($close, 14);
        $this->assertNull($rsi[13]);
        $this->assertEqualsWithDelta(70.46, $rsi[14], 0.05);
        $this->assertEqualsWithDelta(66.25, $rsi[15], 0.05);
        $this->assertEquals(100.0, I::rsi(range(1, 30), 14)[29]);
        $this->assertEquals(0.0, I::rsi(range(30, 1), 14)[29]);
    }

    public function test_macd_follows_momentum(): void
    {
        $up = range(100, 160);
        $m = I::macd($up);
        $this->assertNull($m['macd'][24]);
        $this->assertGreaterThan(0, $m['macd'][60]);
        // A steady climb at a constant pace: the line settles on its signal and the histogram goes to zero.
        $this->assertEqualsWithDelta(0.0, $m['hist'][60], 1e-6);
        // A climb that speeds up keeps the histogram positive; one that slows turns it negative while still rising.
        $faster = array_map(fn ($i) => 100 + $i * $i / 10, range(0, 60));
        $this->assertGreaterThan(0, I::macd($faster)['hist'][60]);
        $slower = array_map(fn ($i) => 100 + 60 * sqrt($i), range(0, 60));
        $this->assertLessThan(0, I::macd($slower)['hist'][60]);
        $turn = array_merge(range(100, 150), range(149, 120));
        $this->assertLessThan(0, I::macd($turn)['hist'][count($turn) - 1]);
    }

    public function test_stochastic_and_atr(): void
    {
        $high = $low = $close = [];
        foreach (range(0, 39) as $i) {
            $close[] = 100 + $i;
            $high[] = 101 + $i;
            $low[] = 99 + $i;
        }
        $s = I::stochastic($high, $low, $close);
        $this->assertGreaterThan(90, $s['k'][39]);
        $this->assertEqualsWithDelta(2.0, I::atr($high, $low, $close)[39], 1e-9);
        // Flat bars: no range at all, the oscillator sits in the middle.
        $flat = array_fill(0, 20, 50.0);
        $this->assertSame(50.0, I::stochastic($flat, $flat, $flat)['k'][19]);
    }

    public function test_adx_separates_a_trend_from_a_range(): void
    {
        $th = $tl = $tc = $rh = $rl = $rc = [];
        foreach (range(0, 79) as $i) {
            $tc[] = 100 + 2 * $i; $th[] = 101 + 2 * $i; $tl[] = 99 + 2 * $i;
            $c = 100 + ($i % 2 ? 1 : -1); $rc[] = $c; $rh[] = $c + 1; $rl[] = $c - 1;
        }
        $this->assertGreaterThan(60, I::adx($th, $tl, $tc)[79]);
        $this->assertLessThan(20, I::adx($rh, $rl, $rc)[79]);
    }

    public function test_ichimoku_cloud_and_market_relation(): void
    {
        $high = $low = [];
        foreach (range(0, 99) as $i) {
            $high[] = 101 + $i;
            $low[] = 99 + $i;
        }
        $c = I::ichimoku($high, $low);
        $this->assertNull($c['span_b'][76]);
        // In a steady climb the cloud trails well under the price, and its leading edge is above the trailing one.
        $this->assertLessThan(199, $c['span_a'][99]);
        $this->assertGreaterThan($c['span_b'][99], $c['span_a'][99]);

        $market = [0.01, -0.02, 0.015, 0.0, -0.01, 0.02, 0.005, -0.015, 0.01, -0.005, 0.02, -0.01];
        $twice = array_map(fn ($r) => 2 * $r, $market);
        $r = I::relation($twice, $market);
        $this->assertEqualsWithDelta(2.0, $r['beta'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $r['corr'], 1e-9);
        $this->assertEqualsWithDelta(-1.0, I::relation(array_map(fn ($x) => -$x, $market), $market)['corr'], 1e-9);
        $this->assertNull(I::relation([0.1, 0.2], [0.1, 0.2])['beta']);
    }
}
