<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Share movements made by the admin, outside the market: no trade, no cash.
 * Every share stays accounted for. Shares only move between holders, and a
 * change in a company's free float is taken from or added to the liquidity
 * fund, so the float always equals the sum of all positions.
 */
class Shares
{
    /** The liquidity fund: the market maker's account, or failing that the first fund. */
    public static function fund(): ?int
    {
        $id = DB::table('users')->where('alias', 'mm-bot')->value('id')
            ?? DB::table('users')->where('role', 'bot')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    public static function lastPrice(int $companyId): int
    {
        return (int) DB::selectOne(<<<'SQL'
            SELECT COALESCE(
                (SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
                (SELECT prev_close FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1),
                c.ref_price) AS last
            FROM companies c WHERE c.id = ?
            SQL, [$companyId])->last;
    }

    /**
     * Move $qty free shares from one holder to another. Shares in open sell
     * orders or pledged to the bank cannot move. The cost carried over is the
     * giver's average cost, or the last price when $atMarket is set (so the
     * receiver starts with no gain or loss). Call inside a transaction.
     * Returns null on success or a message saying why not.
     */
    public static function move(int $companyId, int $from, int $to, int $qty, bool $atMarket = false): ?string
    {
        if ($from === $to) {
            return 'المالك الحالي والمالك الجديد واحد';
        }
        $pos = DB::selectOne('SELECT qty, reserved_qty, cost_total FROM positions WHERE user_id = ? AND company_id = ? FOR UPDATE',
            [$from, $companyId]);
        $free = $pos ? (int) $pos->qty - (int) $pos->reserved_qty : 0;
        if ($free < $qty) {
            return 'الأسهم الحرة عند المالك الحالي '.number_format($free).' فقط (المحجوزة في أوامر بيع والمرهونة لا تُنقل)';
        }
        $carried = intdiv((int) $pos->cost_total * $qty, (int) $pos->qty);
        DB::update('UPDATE positions SET qty = qty - ?, cost_total = cost_total - ? WHERE user_id = ? AND company_id = ?',
            [$qty, $carried, $from, $companyId]);
        DB::statement(
            'INSERT INTO positions (user_id, company_id, qty, cost_total) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, company_id) DO UPDATE
             SET qty = positions.qty + EXCLUDED.qty, cost_total = positions.cost_total + EXCLUDED.cost_total',
            [$to, $companyId, $qty, $atMarket ? $qty * self::lastPrice($companyId) : $carried]);

        return null;
    }

    /**
     * Change the free float by $delta shares: new shares go to the liquidity
     * fund, removed ones come out of its free holding. Call inside a transaction.
     */
    public static function changeFloat(object $company, int $delta): ?string
    {
        if ($delta === 0) {
            return null;
        }
        $fund = self::fund();
        if ($fund === null) {
            return 'لا يوجد صندوق سيولة يستلم الأسهم أو تُسحب منه';
        }
        $pos = DB::selectOne('SELECT qty, reserved_qty, cost_total FROM positions WHERE user_id = ? AND company_id = ? FOR UPDATE',
            [$fund, $company->id]);
        $free = $pos ? (int) $pos->qty - (int) $pos->reserved_qty : 0;
        if ($delta < 0 && $free < -$delta) {
            return 'الأسهم الحرة ينقص عددها '.number_format(-$delta).' سهم، وصندوق السيولة يملك '.number_format($free).' سهم حر فقط';
        }
        $avg = $pos && $pos->qty > 0 ? intdiv((int) $pos->cost_total, (int) $pos->qty) : self::lastPrice((int) $company->id);
        DB::statement(
            'INSERT INTO positions (user_id, company_id, qty, cost_total) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, company_id) DO UPDATE
             SET qty = positions.qty + ?, cost_total = GREATEST(0, positions.cost_total + ?)',
            [$fund, $company->id, max($delta, 0), max($delta, 0) * $avg, $delta, $delta * $avg]);

        return null;
    }
}
