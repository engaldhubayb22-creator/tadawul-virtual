<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Margin financing with a fixed fee.
 *
 *   assets = cash + cash held by orders + shares at the last price (pledged ones included)
 *   debt   = principal + fee of the open financing, plus what is owed on loans against pledged shares
 *   equity = assets - debt
 *   margin = equity / assets
 *
 * A user may owe up to financing_max_ratio_bps of their equity. When the
 * margin falls to margin_call_bps they are warned; at liquidation_bps their
 * orders are cancelled and their shares sold until the debt is repaid.
 */
class Financing
{
    /** @return array{assets:int,debt:int,facility_debt:int,equity:int,margin_bps:?int,cash:int,financing:?object} */
    public static function position(int $userId): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              COALESCE((SELECT sum(balance) FROM ledger_accounts
                        WHERE user_id = ? AND kind IN ('cash', 'reserved')), 0) AS money,
              COALESCE((SELECT balance FROM ledger_accounts WHERE user_id = ? AND kind = 'cash'), 0) AS cash,
              COALESCE((SELECT sum(p.qty * COALESCE(
                          (SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
                          (SELECT prev_close FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1),
                          c.ref_price))
                        FROM positions p JOIN companies c ON c.id = p.company_id WHERE p.user_id = ?), 0) AS stocks
            SQL, [$userId, $userId, $userId]);
        $financing = DB::table('financings')->where('user_id', $userId)
            ->whereIn('status', ['active', 'liquidating'])->first();
        $assets = (int) $row->money + (int) $row->stocks;
        $facility = $financing ? (int) $financing->principal + (int) $financing->fee : 0;
        // Everything owed counts against equity, so borrowed cash cannot be borrowed against again.
        $debt = $facility + Pledge::debt($userId);

        return [
            'assets' => $assets, 'debt' => $debt, 'facility_debt' => $facility,
            'equity' => $assets - $debt, 'cash' => (int) $row->cash,
            'margin_bps' => $debt > 0 && $assets > 0 ? intdiv(($assets - $debt) * 10000, $assets) : null,
            'financing' => $financing,
        ];
    }

    /** The largest amount the user could be granted right now. */
    public static function limit(array $position): int
    {
        if ($position['financing'] !== null) {
            return 0;
        }
        $ratio = (int) Ledger::setting('financing_max_ratio_bps');
        $feeBps = (int) Ledger::setting('financing_fee_bps');
        // principal + fee <= equity * ratio
        $maxDebt = intdiv(max($position['equity'], 0) * $ratio, 10000);

        return intdiv($maxDebt * 10000, 10000 + $feeBps);
    }

    /** Grant a financing. Call inside a transaction. Returns the new row id. */
    public static function grant(int $userId, int $amount): int
    {
        $fee = intdiv($amount * (int) Ledger::setting('financing_fee_bps'), 10000);
        $days = (int) Ledger::setting('financing_days');
        $id = DB::table('financings')->insertGetId([
            'user_id' => $userId, 'principal' => $amount, 'fee' => $fee,
            'due_at' => DB::raw("now() + interval '".$days." days'"),
        ]);
        Ledger::move(self::pool(), Ledger::account($userId, 'cash'), $amount, 'financing_grant', $id);

        return $id;
    }

    /**
     * Repay the open financing in full from cash. Returns false if cash does
     * not cover it. Call inside a transaction.
     */
    public static function repay(object $financing, string $closedAs = 'repaid'): bool
    {
        $debt = (int) $financing->principal + (int) $financing->fee;
        $cash = Ledger::account((int) $financing->user_id, 'cash');
        $balance = (int) DB::selectOne('SELECT balance FROM ledger_accounts WHERE id = ? FOR UPDATE', [$cash])->balance;
        if ($balance < $debt) {
            return false;
        }
        Ledger::move($cash, self::pool(), $debt, 'financing_repay', (int) $financing->id);
        DB::table('financings')->where('id', $financing->id)
            ->update(['status' => $closedAs, 'closed_at' => DB::raw('now()')]);

        return true;
    }

    /**
     * One pass of the margin monitor over every open financing. Returns what
     * it did, for the log. Selling goes through the engine's queue as market
     * orders, like any other trade.
     */
    public static function monitor(): array
    {
        $callBps = (int) Ledger::setting('margin_call_bps');
        $liqBps = (int) Ledger::setting('liquidation_bps');
        $done = ['called' => 0, 'liquidating' => 0, 'closed' => 0, 'defaulted' => 0, 'sell_orders' => 0];

        $open = DB::select("SELECT *, due_at <= now() AS overdue FROM financings WHERE status IN ('active', 'liquidating')");
        foreach ($open as $f) {
            DB::transaction(function () use ($f, $callBps, $liqBps, &$done) {
                $userId = (int) $f->user_id;
                $pos = self::position($userId);
                $margin = $pos['margin_bps'] ?? 10000;

                if ($f->status === 'active') {
                    if ($margin > $liqBps && ! $f->overdue) {
                        if ($margin <= $callBps && $f->margin_called_at === null) {
                            DB::table('financings')->where('id', $f->id)->update(['margin_called_at' => DB::raw('now()')]);
                            $done['called']++;
                        } elseif ($margin > $callBps && $f->margin_called_at !== null) {
                            DB::table('financings')->where('id', $f->id)->update(['margin_called_at' => null]);
                        }

                        return;
                    }
                    // Overdue with enough cash: just collect it.
                    if ($f->overdue && $margin > $liqBps && self::repay($f)) {
                        $done['closed']++;

                        return;
                    }
                    DB::table('financings')->where('id', $f->id)->update(['status' => 'liquidating']);
                    $done['liquidating']++;
                }

                // Liquidating: repay as soon as cash covers the debt.
                if (self::repay($f, 'liquidated')) {
                    $done['closed']++;

                    return;
                }
                // Free everything held by open orders, then sell what is free.
                $orders = DB::table('orders')->where('user_id', $userId)->whereIn('status', ['open', 'partial'])->pluck('id');
                foreach ($orders as $orderId) {
                    OrderQueue::send(['type' => 'cancel', 'user_id' => $userId, 'order_id' => (int) $orderId]);
                }
                $positions = DB::select(
                    'SELECT c.symbol, p.qty - p.reserved_qty AS free, p.qty FROM positions p
                     JOIN companies c ON c.id = p.company_id WHERE p.user_id = ? AND p.qty - p.pledged_qty > 0', [$userId]);
                foreach ($positions as $p) {
                    if ($p->free > 0) {
                        OrderQueue::send(['type' => 'new', 'user_id' => $userId, 'symbol' => $p->symbol, 'side' => 'sell',
                            'kind' => 'market', 'qty' => (int) $p->free,
                            'key' => 'liq-'.$f->id.'-'.$p->symbol.'-'.bin2hex(random_bytes(6))]);
                        $done['sell_orders']++;
                    }
                }
                // Nothing left to sell or cancel and still short: take what
                // cash there is and write off the rest.
                if (count($positions) === 0 && $orders->isEmpty()) {
                    $cash = Ledger::account($userId, 'cash');
                    $debt = (int) $f->principal + (int) $f->fee;
                    $paid = min($pos['cash'], $debt);
                    Ledger::move($cash, self::pool(), $paid, 'financing_repay', (int) $f->id);
                    DB::table('financings')->where('id', $f->id)->update([
                        'status' => 'defaulted', 'written_off' => $debt - $paid, 'closed_at' => DB::raw('now()'),
                    ]);
                    $done['defaulted']++;
                }
            });
        }

        return $done;
    }

    private static function pool(): int
    {
        return (int) DB::table('ledger_accounts')->where('kind', 'sys_financing')->value('id');
    }
}
