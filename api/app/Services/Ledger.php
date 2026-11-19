<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cash movements made by the API. Trading itself (reserve, settle, release)
 * belongs to the Go engine; the API only moves money for things that are not
 * trades, such as the free starting balance.
 */
class Ledger
{
    public static function setting(string $key): mixed
    {
        $raw = DB::table('settings')->where('key', $key)->value('value');

        return $raw === null ? null : json_decode($raw, true);
    }

    /** Find or create the user's account of the given kind and return its id. */
    public static function account(int $userId, string $kind): int
    {
        DB::statement(
            'INSERT INTO ledger_accounts (user_id, kind) VALUES (?, ?) ON CONFLICT (user_id, kind) DO NOTHING',
            [$userId, $kind]
        );

        return (int) DB::table('ledger_accounts')->where('user_id', $userId)->where('kind', $kind)->value('id');
    }

    /** Move an amount between two accounts and record the entry. Call inside a transaction. */
    public static function move(int $from, int $to, int $amount, string $refType, ?int $refId = null): void
    {
        if ($amount <= 0) {
            return;
        }
        DB::update('UPDATE ledger_accounts SET balance = balance - ? WHERE id = ?', [$amount, $from]);
        DB::update('UPDATE ledger_accounts SET balance = balance + ? WHERE id = ?', [$amount, $to]);
        DB::table('ledger_entries')->insert([
            'debit_account_id' => $from,
            'credit_account_id' => $to,
            'amount' => $amount,
            'ref_type' => $refType,
            'ref_id' => $refId,
        ]);
    }

    /** Give a new user the free starting balance set in the admin settings. */
    public static function grantStartingBalance(int $userId): int
    {
        $amount = (int) self::setting('starting_balance_halalas');
        $treasury = (int) DB::table('ledger_accounts')->where('kind', 'sys_treasury')->value('id');
        $cash = self::account($userId, 'cash');
        self::account($userId, 'reserved');
        self::move($treasury, $cash, $amount, 'starting_balance', $userId);

        return $amount;
    }

    /** @return array{cash:int,reserved:int} */
    public static function balances(int $userId): array
    {
        $rows = DB::table('ledger_accounts')->where('user_id', $userId)->pluck('balance', 'kind');

        return ['cash' => (int) ($rows['cash'] ?? 0), 'reserved' => (int) ($rows['reserved'] ?? 0)];
    }
}
