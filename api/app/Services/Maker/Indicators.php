<?php

namespace App\Services\Maker;

/**
 * Technical indicators over a series of bars, oldest first. Every function
 * returns an array aligned with its input, holding null where the indicator
 * does not yet have enough bars behind it.
 */
final class Indicators
{
    /** @param list<float> $v */
    public static function sma(array $v, int $n): array
    {
        $out = array_fill(0, count($v), null);
        $sum = 0.0;
        foreach ($v as $i => $x) {
            $sum += $x;
            if ($i >= $n) {
                $sum -= $v[$i - $n];
            }
            if ($i >= $n - 1) {
                $out[$i] = $sum / $n;
            }
        }

        return $out;
    }

    /** Exponential average, started from the simple average of the first $n values. */
    public static function ema(array $v, int $n): array
    {
        $out = array_fill(0, count($v), null);
        if (count($v) < $n) {
            return $out;
        }
        $k = 2 / ($n + 1);
        $ema = array_sum(array_slice($v, 0, $n)) / $n;
        $out[$n - 1] = $ema;
        for ($i = $n; $i < count($v); $i++) {
            $ema = $v[$i] * $k + $ema * (1 - $k);
            $out[$i] = $ema;
        }

        return $out;
    }

    /** Wilder's smoothing: the average the RSI, ATR and ADX are built on. */
    private static function wilder(array $v, int $n, int $from = 0): array
    {
        $out = array_fill(0, count($v), null);
        if (count($v) - $from < $n) {
            return $out;
        }
        $avg = array_sum(array_slice($v, $from, $n)) / $n;
        $out[$from + $n - 1] = $avg;
        for ($i = $from + $n; $i < count($v); $i++) {
            $avg = ($avg * ($n - 1) + $v[$i]) / $n;
            $out[$i] = $avg;
        }

        return $out;
    }

    public static function rsi(array $close, int $n = 14): array
    {
        $gain = $loss = [0.0];
        for ($i = 1; $i < count($close); $i++) {
            $d = $close[$i] - $close[$i - 1];
            $gain[] = max($d, 0);
            $loss[] = max(-$d, 0);
        }
        $g = self::wilder($gain, $n, 1);
        $l = self::wilder($loss, $n, 1);
        $out = array_fill(0, count($close), null);
        foreach ($g as $i => $up) {
            if ($up !== null) {
                $out[$i] = $l[$i] == 0.0 ? ($up == 0.0 ? 50.0 : 100.0) : 100 - 100 / (1 + $up / $l[$i]);
            }
        }

        return $out;
    }

    /** @return array{macd:array,signal:array,hist:array} */
    public static function macd(array $close, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $a = self::ema($close, $fast);
        $b = self::ema($close, $slow);
        $n = count($close);
        $line = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] !== null && $b[$i] !== null) {
                $line[$i] = $a[$i] - $b[$i];
            }
        }
        $sig = array_fill(0, $n, null);
        $hist = array_fill(0, $n, null);
        $start = $slow - 1;
        if ($n > $start) {
            foreach (self::ema(array_slice($line, $start), $signal) as $j => $s) {
                if ($s !== null) {
                    $sig[$start + $j] = $s;
                    $hist[$start + $j] = $line[$start + $j] - $s;
                }
            }
        }

        return ['macd' => $line, 'signal' => $sig, 'hist' => $hist];
    }

    /** Slow stochastic: %K smoothed over $smooth bars, %D its average. @return array{k:array,d:array} */
    public static function stochastic(array $high, array $low, array $close, int $n = 14, int $smooth = 3): array
    {
        $count = count($close);
        $fast = array_fill(0, $count, null);
        for ($i = $n - 1; $i < $count; $i++) {
            $hh = max(array_slice($high, $i - $n + 1, $n));
            $ll = min(array_slice($low, $i - $n + 1, $n));
            $fast[$i] = $hh == $ll ? 50.0 : ($close[$i] - $ll) / ($hh - $ll) * 100;
        }
        $k = self::smaOfPartial($fast, $smooth);

        return ['k' => $k, 'd' => self::smaOfPartial($k, $smooth)];
    }

    /** Simple average of a series that starts with nulls. */
    private static function smaOfPartial(array $v, int $n): array
    {
        $out = array_fill(0, count($v), null);
        $first = null;
        foreach ($v as $i => $x) {
            if ($x !== null) {
                $first = $i;
                break;
            }
        }
        if ($first === null) {
            return $out;
        }
        foreach (self::sma(array_slice($v, $first), $n) as $j => $x) {
            $out[$first + $j] = $x;
        }

        return $out;
    }

    private static function trueRange(array $high, array $low, array $close): array
    {
        $tr = [$high[0] - $low[0]];
        for ($i = 1; $i < count($close); $i++) {
            $tr[] = max($high[$i] - $low[$i], abs($high[$i] - $close[$i - 1]), abs($low[$i] - $close[$i - 1]));
        }

        return $tr;
    }

    /** Average true range: how far the price usually travels in one bar. */
    public static function atr(array $high, array $low, array $close, int $n = 14): array
    {
        return count($close) === 0 ? [] : self::wilder(self::trueRange($high, $low, $close), $n);
    }

    /** Trend strength, 0 to 100, whichever way the trend points. */
    public static function adx(array $high, array $low, array $close, int $n = 14): array
    {
        $count = count($close);
        $out = array_fill(0, $count, null);
        if ($count < 2 * $n) {
            return $out;
        }
        $plus = $minus = [0.0];
        for ($i = 1; $i < $count; $i++) {
            $up = $high[$i] - $high[$i - 1];
            $down = $low[$i - 1] - $low[$i];
            $plus[] = ($up > $down && $up > 0) ? $up : 0.0;
            $minus[] = ($down > $up && $down > 0) ? $down : 0.0;
        }
        $tr = self::wilder(self::trueRange($high, $low, $close), $n, 1);
        $p = self::wilder($plus, $n, 1);
        $m = self::wilder($minus, $n, 1);
        $dx = array_fill(0, $count, 0.0);
        for ($i = $n; $i < $count; $i++) {
            if ($tr[$i] > 0) {
                $pdi = 100 * $p[$i] / $tr[$i];
                $mdi = 100 * $m[$i] / $tr[$i];
                $dx[$i] = $pdi + $mdi == 0.0 ? 0.0 : 100 * abs($pdi - $mdi) / ($pdi + $mdi);
            }
        }
        foreach (self::wilder($dx, $n, $n) as $i => $x) {
            $out[$i] = $x;
        }

        return $out;
    }

    /**
     * Ichimoku. span_a and span_b at bar i are the cloud drawn at bar i, which
     * is computed from the bars up to i - 26.
     *
     * @return array{tenkan:array,kijun:array,span_a:array,span_b:array}
     */
    public static function ichimoku(array $high, array $low, int $tenkan = 9, int $kijun = 26, int $span = 52): array
    {
        $mid = function (int $n) use ($high, $low) {
            $out = array_fill(0, count($high), null);
            for ($i = $n - 1; $i < count($high); $i++) {
                $out[$i] = (max(array_slice($high, $i - $n + 1, $n)) + min(array_slice($low, $i - $n + 1, $n))) / 2;
            }

            return $out;
        };
        $t = $mid($tenkan);
        $k = $mid($kijun);
        $b = $mid($span);
        $count = count($high);
        $spanA = $spanB = array_fill(0, $count, null);
        for ($i = $kijun; $i < $count; $i++) {
            $src = $i - $kijun;
            if ($t[$src] !== null && $k[$src] !== null) {
                $spanA[$i] = ($t[$src] + $k[$src]) / 2;
            }
            $spanB[$i] = $b[$src];
        }

        return ['tenkan' => $t, 'kijun' => $k, 'span_a' => $spanA, 'span_b' => $spanB];
    }

    /** Rolling beta and correlation of a share's returns against the market's. @return array{beta:?float,corr:?float} */
    public static function relation(array $stock, array $market): array
    {
        $n = min(count($stock), count($market));
        if ($n < 12) {
            return ['beta' => null, 'corr' => null];
        }
        $s = array_slice($stock, -$n);
        $m = array_slice($market, -$n);
        $ms = array_sum($s) / $n;
        $mm = array_sum($m) / $n;
        $cov = $vs = $vm = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $cov += ($s[$i] - $ms) * ($m[$i] - $mm);
            $vs += ($s[$i] - $ms) ** 2;
            $vm += ($m[$i] - $mm) ** 2;
        }
        if ($vs == 0.0 || $vm == 0.0) {
            return ['beta' => null, 'corr' => null];
        }

        return ['beta' => $cov / $vm, 'corr' => $cov / sqrt($vs * $vm)];
    }
}
