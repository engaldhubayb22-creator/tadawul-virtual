<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loans against pledged shares. db/schema.sql already holds these objects for
 * a fresh database; this brings an existing database up to the same state, so
 * every statement here is safe to run when the object already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS pledged_qty BIGINT NOT NULL DEFAULT 0 CHECK (pledged_qty >= 0);
            DO $$ BEGIN
                ALTER TABLE positions ADD CONSTRAINT positions_pledged_within_reserved CHECK (pledged_qty <= reserved_qty);
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            -- ---------------------------------------------------------------------------
            -- Loans against pledged shares
            -- ---------------------------------------------------------------------------
            
            -- The trader pledges shares of one company and borrows against their value.
            -- The shares stay in the trader's position but cannot be sold until the loan
            -- is repaid. If the debt grows too large against the shares' value, or the
            -- loan is overdue and unpaid, the bank takes the shares at the market price.
            CREATE TABLE IF NOT EXISTS pledge_loans (
                id               BIGSERIAL PRIMARY KEY,
                user_id          BIGINT NOT NULL REFERENCES users (id),
                company_id       BIGINT NOT NULL REFERENCES companies (id),
                qty              BIGINT NOT NULL CHECK (qty > 0),          -- pledged shares
                principal        BIGINT NOT NULL CHECK (principal > 0),
                fee              BIGINT NOT NULL CHECK (fee >= 0),         -- fixed, agreed up front
                status           TEXT   NOT NULL DEFAULT 'active'
                                 CHECK (status IN ('active', 'repaid', 'foreclosed')),
                due_at           TIMESTAMPTZ NOT NULL,
                warned_at        TIMESTAMPTZ,                              -- cover call raised
                foreclosed_price BIGINT,                                   -- price the bank took the shares at
                written_off      BIGINT NOT NULL DEFAULT 0 CHECK (written_off >= 0),
                created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                closed_at        TIMESTAMPTZ
            );
            
            CREATE INDEX IF NOT EXISTS pledge_loans_open ON pledge_loans (user_id) WHERE status = 'active';
            
            INSERT INTO settings (key, value) VALUES
                ('pledge_enabled',       'true'),
                ('pledge_ltv_bps',       '5000'),   -- debt may be up to 50% of the pledged shares' value
                ('pledge_fee_bps',       '200'),    -- fixed fee: 2% of the amount
                ('pledge_days',          '90'),
                ('pledge_call_bps',      '7000'),   -- warn when the debt reaches 70% of the value
                ('pledge_foreclose_bps', '8500')    -- take the shares when it reaches 85%
            ON CONFLICT (key) DO NOTHING;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS pledge_loans;
            ALTER TABLE positions DROP CONSTRAINT IF EXISTS positions_pledged_within_reserved;
            ALTER TABLE positions DROP COLUMN IF EXISTS pledged_qty;
            DELETE FROM settings WHERE key LIKE 'pledge\_%';
            SQL);
    }
};
