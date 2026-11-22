<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Loans against pledged shares.
 *
 *   value = pledged shares at the last price
 *   debt  = principal + fee
 *   ltv   = debt / value
 *
 * A loan may be opened while ltv <= pledge_ltv_bps. The shares stay in the
 * trader's position but are locked. When ltv reaches pledge_call_bps the
 * trader is warned; at pledge_foreclose_bps, or when the loan is overdue and
 * cash does not cover it, the bank forecloses: it takes the shares at the last
 * price, keeps what it is owed and pays the trader the rest.
 */
class Pledge
{
    private const LAST_PRICE = <<<'SQL'
        COALESCE(
            (SELECT price FROM trades WHERE company_id = c.id ORDER BY id DESC LIMIT 1),
            (SELECT prev_close FROM daily_stats WHERE company_id = c.id ORDER BY trade_date DESC LIMIT 1),
            c.ref_price)
        SQL;

    /** @return array{ltv_bps:int,fee_bps:int,days:int,call_bps:int,foreclose_bps:int} */
    public static function terms(): array
    {
        return [
            'ltv_bps' => (int) Ledger::setting('pledge_ltv_bps'),
            'fee_bps' => (int) Ledger::setting('pledge_fee_bps'),
            'days' => (int) Ledger::setting('pledge_days'),
            'call_bps' => (int) Ledger::setting('pledge_call_bps'),
            'foreclose_bps' => (int) Ledger::setting('pledge_foreclose_bps'),
        ];
    }

    /** The largest principal that pledging shares worth $value allows: principal + fee <= value * ltv. */
    public static function maxPrincipal(int $value, array $terms): int
    {
        return intdiv(intdiv($value * $terms['ltv_bps'], 10000) * 10000, 10000 + $terms['fee_bps']);
    }

    /** Total owed on the user's open loans. */
    public static function debt(int $userId): int
    {
        return (int) DB::table('pledge_loans')->where('user_id', $userId)->where('status', 'active')
            ->sum(DB::raw('principal + fee'));
    }

    /** Open loans with their current value and ltv, newest first. */
    public static function open(int $userId): array
    {
        $last = self::LAST_PRICE;

        return array_map(fn ($r) => self::present($r), DB::select(<<<SQL
            SELECT l.*, c.symbol, c.name, $last AS last
            FROM pledge_loans l JOIN companies c ON c.id = l.company_id
            WHERE l.user_id = ? AND l.status = 'active'
            ORDER BY l.id DESC
            SQL, [$userId]));
    }

    /** Closed loans, newest first. */
    public static function closed(int $userId, int $limit = 20): array
    {
        $last = self::LAST_PRICE;

        return array_map(fn ($r) => self::present($r), DB::select(<<<SQL
            SELECT l.*, c.symbol, c.name, $last AS last
            FROM pledge_loans l JOIN companies c ON c.id = l.company_id
            WHERE l.user_id = ? AND l.status <> 'active'
            ORDER BY l.closed_at DESC, l.id DESC
            LIMIT ?
            SQL, [$userId, $limit]));
    }

    /** Positions with shares free to pledge, and what each could borrow at most. */
    public static function eligible(int $userId): array
    {
        $terms = self::terms();
        $last = self::LAST_PRICE;

        return array_map(function ($r) use ($terms) {
            $value = (int) $r->free * (int) $r->last;

            return [
                'symbol' => $r->symbol, 'name' => $r->name, 'free' => (int) $r->free, 'last' => (int) $r->last,
                'value' => $value, 'max_loan' => self::maxPrincipal($value, $terms),
            ];
        }, DB::select(<<<SQL
            SELECT c.symbol, c.name, p.qty - p.reserved_qty AS free, $last AS last
            FROM positions p JOIN companies c ON c.id = p.company_id
            WHERE p.user_id = ? AND p.qty - p.reserved_qty > 0
            ORDER BY c.symbol
            SQL, [$userId]));
    }

    /**
     * Pledge $qty shares and lend $amount against them. Call inside a
     * transaction. Returns the loan id, or a message saying why not.
     */
    public static function grant(int $userId, object $company, int $qty, int $amount): int|string
    {
        $terms = self::terms();
        $last = (int) DB::selectOne('SELECT '.self::LAST_PRICE.' AS last FROM companies c WHERE c.id = ?', [$company->id])->last;
        if ($amount > self::maxPrincipal($qty * $last, $terms)) {
            return 'المبلغ أكبر من الحد المتاح مقابل هذه الأسهم';
        }
        // Lock the shares. Only shares that are free (not in sell orders, not already pledged) qualify.
        $locked = DB::update(
            'UPDATE positions SET reserved_qty = reserved_qty + ?, pledged_qty = pledged_qty + ?
             WHERE user_id = ? AND company_id = ? AND qty - reserved_qty >= ?',
            [$qty, $qty, $userId, $company->id, $qty]);
        if ($locked !== 1) {
            return 'لا تملك هذا العدد من الأسهم الحرة';
        }
        $id = DB::table('pledge_loans')->insertGetId([
            'user_id' => $userId, 'company_id' => $company->id, 'qty' => $qty, 'principal' => $amount,
            'fee' => intdiv($amount * $terms['fee_bps'], 10000),
            'due_at' => DB::raw("now() + interval '".$terms['days']." days'"),
        ]);
        Ledger::move(self::pool(), Ledger::account($userId, 'cash'), $amount, 'pledge_loan', $id);

        return $id;
    }

    /**
     * Repay a loan in full from cash and release its shares. Returns false
     * if cash does not cover it. Call inside a transaction with the loan row locked.
     */
    public static function repay(object $loan): bool
    {
        $debt = (int) $loan->principal + (int) $loan->fee;
        $cash = Ledger::account((int) $loan->user_id, 'cash');
        $balance = (int) DB::selectOne('SELECT balance FROM ledger_accounts WHERE id = ? FOR UPDATE', [$cash])->balance;
        if ($balance < $debt) {
            return false;
        }
        Ledger::move($cash, self::pool(), $debt, 'pledge_repay', (int) $loan->id);
        DB::update(
            'UPDATE positions SET reserved_qty = reserved_qty - ?, pledged_qty = pledged_qty - ?
             WHERE user_id = ? AND company_id = ?', [$loan->qty, $loan->qty, $loan->user_id, $loan->company_id]);
        DB::table('pledge_loans')->where('id', $loan->id)->update(['status' => 'repaid', 'closed_at' => DB::raw('now()')]);

        return true;
    }

    /**
     * The bank takes the pledged shares at the last price. The shares move to
     * the liquidity fund, which trades them back into the market. The trader
     * is paid their value, the debt is taken from that, and any shortfall is
     * taken from free cash; what still cannot be collected is written off.
     * Call inside a transaction with the loan row locked.
     */
    public static function foreclose(object $loan): void
    {
        $userId = (int) $loan->user_id;
        $qty = (int) $loan->qty;
        $price = (int) DB::selectOne('SELECT '.self::LAST_PRICE.' AS last FROM companies c WHERE c.id = ?', [$loan->company_id])->last;
        $value = $qty * $price;
        $debt = (int) $loan->principal + (int) $loan->fee;

        // Shares: out of the trader's position (with their share of the cost), into the fund's.
        DB::update(
            'UPDATE positions SET cost_total = cost_total - (cost_total * ? / qty), qty = qty - ?,
                    reserved_qty = reserved_qty - ?, pledged_qty = pledged_qty - ?
             WHERE user_id = ? AND company_id = ?', [$qty, $qty, $qty, $qty, $userId, $loan->company_id]);
        DB::statement(
            'INSERT INTO positions (user_id, company_id, qty, cost_total) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, company_id) DO UPDATE
             SET qty = positions.qty + EXCLUDED.qty, cost_total = positions.cost_total + EXCLUDED.cost_total',
            [self::custodian(), $loan->company_id, $qty, $value]);

        // Money: the bank pays the value, then collects the debt.
        $cash = Ledger::account($userId, 'cash');
        if ($value > 0) {
            Ledger::move(self::pool(), $cash, $value, 'pledge_foreclosure', (int) $loan->id);
        }
        $balance = (int) DB::selectOne('SELECT balance FROM ledger_accounts WHERE id = ? FOR UPDATE', [$cash])->balance;
        $paid = min($balance, $debt);
        if ($paid > 0) {
            Ledger::move($cash, self::pool(), $paid, 'pledge_repay', (int) $loan->id);
        }
        DB::table('pledge_loans')->where('id', $loan->id)->update([
            'status' => 'foreclosed', 'foreclosed_price' => $price, 'written_off' => $debt - $paid,
            'closed_at' => DB::raw('now()'),
        ]);
    }

    /** One pass over every open loan: cover calls, overdue collection and foreclosures. */
    public static function monitor(): array
    {
        $terms = self::terms();
        $done = ['pledge_warned' => 0, 'pledge_collected' => 0, 'pledge_foreclosed' => 0];

        foreach (DB::table('pledge_loans')->where('status', 'active')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $terms, &$done) {
                $last = self::LAST_PRICE;
                $loan = DB::selectOne(<<<SQL
                    SELECT l.*, l.due_at <= now() AS overdue, $last AS last
                    FROM pledge_loans l JOIN companies c ON c.id = l.company_id
                    WHERE l.id = ? AND l.status = 'active' FOR UPDATE OF l
                    SQL, [$id]);
                if ($loan === null) {
                    return;
                }
                $ltv = self::ltv($loan);
                if ($loan->overdue) {
                    // Due: collect from cash if it is there, otherwise take the shares.
                    if (self::repay($loan)) {
                        $done['pledge_collected']++;
                    } else {
                        self::foreclose($loan);
                        $done['pledge_foreclosed']++;
                    }
                } elseif ($ltv >= $terms['foreclose_bps']) {
                    self::foreclose($loan);
                    $done['pledge_foreclosed']++;
                } elseif ($ltv >= $terms['call_bps'] && $loan->warned_at === null) {
                    DB::table('pledge_loans')->where('id', $id)->update(['warned_at' => DB::raw('now()')]);
                    $done['pledge_warned']++;
                } elseif ($ltv < $terms['call_bps'] && $loan->warned_at !== null) {
                    DB::table('pledge_loans')->where('id', $id)->update(['warned_at' => null]);
                }
            });
        }

        return $done;
    }

    /** Debt as a share of the pledged shares' value, in basis points. */
    private static function ltv(object $loan): int
    {
        $value = (int) $loan->qty * (int) $loan->last;

        return $value > 0 ? intdiv(((int) $loan->principal + (int) $loan->fee) * 10000, $value) : PHP_INT_MAX;
    }

    private static function present(object $r): array
    {
        $value = (int) $r->qty * (int) $r->last;
        $debt = (int) $r->principal + (int) $r->fee;

        return [
            'id' => (int) $r->id, 'symbol' => $r->symbol, 'name' => $r->name, 'qty' => (int) $r->qty,
            'last' => (int) $r->last, 'value' => $value,
            'principal' => (int) $r->principal, 'fee' => (int) $r->fee, 'debt' => $debt,
            'ltv_bps' => $value > 0 ? intdiv($debt * 10000, $value) : null,
            'status' => $r->status, 'warned' => $r->warned_at !== null,
            'created_at' => $r->created_at, 'due_at' => $r->due_at, 'closed_at' => $r->closed_at,
            'foreclosed_price' => $r->foreclosed_price === null ? null : (int) $r->foreclosed_price,
            'written_off' => (int) $r->written_off,
        ];
    }

    /** The fund that receives foreclosed shares: the first market-maker fund, or a bank portfolio made for it. */
    private static function custodian(): int
    {
        $id = DB::table('users')->where('role', 'bot')->orderBy('id')->value('id');
        if ($id === null) {
            $id = DB::table('users')->insertGetId([
                'name' => 'محفظة البنك', 'alias' => 'bank-portfolio', 'mobile' => 'bank-portfolio', 'password' => '!', 'role' => 'bot',
            ]);
        }

        return (int) $id;
    }

    private static function pool(): int
    {
        return (int) DB::table('ledger_accounts')->where('kind', 'sys_financing')->value('id');
    }
}
