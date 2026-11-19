<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * IPO money and share movements. Subscriptions hold the subscriber's cash in
 * their reserved account; allocation turns the held cash into shares and
 * refunds the rest, all in one transaction.
 */
class Ipo
{
    /**
     * Split the offered shares between subscribers.
     *
     * If the offer covers every request, everyone gets what they asked for.
     * Otherwise each subscriber first gets a guaranteed minimum (the IPO's
     * minimum quantity, or an equal share if even that does not fit), and the
     * rest is divided in proportion to what each still wants. Leftover single
     * shares go to the largest unmet fractions, earliest subscriber first, so
     * the result is exact and repeatable.
     *
     * @param  array<int,int>  $requests  user id => quantity requested, in subscription order
     * @return array<int,int> user id => quantity allocated
     */
    public static function allocate(array $requests, int $offered, int $minQty): array
    {
        if (array_sum($requests) <= $offered) {
            return $requests;
        }
        $count = count($requests);
        $floor = min($minQty, intdiv($offered, $count));
        $alloc = [];
        foreach ($requests as $user => $qty) {
            $alloc[$user] = min($qty, $floor);
        }
        $left = $offered - array_sum($alloc);
        $want = [];
        foreach ($requests as $user => $qty) {
            $want[$user] = $qty - $alloc[$user];
        }
        $totalWant = array_sum($want);
        if ($left > 0 && $totalWant > 0) {
            $remainders = [];
            foreach ($want as $user => $w) {
                $share = intdiv($w * $left, $totalWant);
                $alloc[$user] += $share;
                $remainders[$user] = ($w * $left) % $totalWant;
            }
            $spare = $offered - array_sum($alloc);
            // Stable sort: equal remainders keep subscription order.
            $order = array_keys($remainders);
            usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a]
                ?: array_search($a, array_keys($remainders)) <=> array_search($b, array_keys($remainders)));
            foreach ($order as $user) {
                if ($spare <= 0) {
                    break;
                }
                if ($alloc[$user] < $requests[$user]) {
                    $alloc[$user]++;
                    $spare--;
                }
            }
        }

        return $alloc;
    }

    /** Hold the subscription amount. Returns false if the user's cash does not cover it. */
    public static function reserve(int $userId, int $amount, int $ipoId): bool
    {
        $cash = Ledger::account($userId, 'cash');
        $reserved = Ledger::account($userId, 'reserved');
        // Lock the row: the engine may be reserving cash for an order right now.
        $balance = (int) DB::selectOne('SELECT balance FROM ledger_accounts WHERE id = ? FOR UPDATE', [$cash])->balance;
        if ($balance < $amount) {
            return false;
        }
        Ledger::move($cash, $reserved, $amount, 'ipo_reserve', $ipoId);

        return true;
    }

    /** Allocate, settle, refund and list. Returns a summary. Call inside a transaction. */
    public static function allocateAndList(object $ipo): array
    {
        $subs = DB::table('ipo_subscriptions')->where('ipo_id', $ipo->id)->orderBy('created_at')->orderBy('user_id')->get();
        $requests = [];
        foreach ($subs as $s) {
            $requests[(int) $s->user_id] = (int) $s->qty_requested;
        }
        $alloc = $requests ? self::allocate($requests, (int) $ipo->shares_offered, (int) $ipo->min_qty) : [];
        $treasury = (int) DB::table('ledger_accounts')->where('kind', 'sys_treasury')->value('id');
        $price = (int) $ipo->price;

        $sold = 0;
        foreach ($alloc as $user => $qty) {
            $reserved = Ledger::account($user, 'reserved');
            $cash = Ledger::account($user, 'cash');
            Ledger::move($reserved, $treasury, $qty * $price, 'ipo_allocation', (int) $ipo->id);
            Ledger::move($reserved, $cash, ($requests[$user] - $qty) * $price, 'ipo_refund', (int) $ipo->id);
            if ($qty > 0) {
                self::addShares($user, (int) $ipo->company_id, $qty, $qty * $price);
            }
            DB::table('ipo_subscriptions')->where('ipo_id', $ipo->id)->where('user_id', $user)->update(['qty_allocated' => $qty]);
            $sold += $qty;
        }

        // Unsold shares and the liquidity lot go to the market maker, so the
        // stock has two-sided quotes from its first minute.
        $makerQty = (int) $ipo->shares_offered - $sold + (int) $ipo->maker_shares;
        $maker = DB::table('users')->where('alias', 'mm-bot')->value('id');
        if ($maker && $makerQty > 0) {
            self::addShares((int) $maker, (int) $ipo->company_id, $makerQty, $makerQty * $price);
        }

        // listed_at starts the three trading days of wider price limits.
        DB::table('companies')->where('id', $ipo->company_id)->update(['status' => 'trading', 'listed_at' => DB::raw('current_date')]);
        DB::table('ipos')->where('id', $ipo->id)->update(['status' => 'listed']);

        return ['subscribers' => count($alloc), 'requested' => array_sum($requests), 'sold' => $sold, 'to_maker' => $maker ? $makerQty : 0];
    }

    private static function addShares(int $userId, int $companyId, int $qty, int $cost): void
    {
        DB::statement(
            'INSERT INTO positions (user_id, company_id, qty, cost_total) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, company_id)
             DO UPDATE SET qty = positions.qty + EXCLUDED.qty, cost_total = positions.cost_total + EXCLUDED.cost_total',
            [$userId, $companyId, $qty, $cost]
        );
    }
}
