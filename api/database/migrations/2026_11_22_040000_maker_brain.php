<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The market maker's analysis tables. db/schema.sql has them for a fresh database; this is safe to run again. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------------
-- The market maker's analysis ("brain")
-- ---------------------------------------------------------------------------

-- The maker reads the market and every share on its own, decides what kind
-- of move each one is in, and writes the orders it would place. In shadow
-- mode nothing here places an order: the plan is only shown to the admin.

ALTER TABLE companies ADD COLUMN IF NOT EXISTS maker_reserve_open BOOLEAN NOT NULL DEFAULT false;  -- admin released the locked reserve

-- The analysis reads each day's trades across all companies.
CREATE INDEX IF NOT EXISTS trades_time ON trades (executed_at);

-- 15-minute and hourly bars, kept up to date from the minute candles.
CREATE TABLE IF NOT EXISTS maker_bars (
    company_id BIGINT NOT NULL REFERENCES companies (id),
    width      INT    NOT NULL,                 -- minutes
    bucket     TIMESTAMPTZ NOT NULL,
    open       BIGINT NOT NULL,
    high       BIGINT NOT NULL,
    low        BIGINT NOT NULL,
    close      BIGINT NOT NULL,
    volume     BIGINT NOT NULL,
    PRIMARY KEY (company_id, width, bucket)
);

-- What the fund held in each company when the session began: the base of the daily sell cap.
CREATE TABLE IF NOT EXISTS maker_days (
    company_id BIGINT NOT NULL REFERENCES companies (id),
    trade_date DATE   NOT NULL,
    start_qty  BIGINT NOT NULL,
    PRIMARY KEY (company_id, trade_date)
);

-- The latest reading and plan per company.
CREATE TABLE IF NOT EXISTS maker_state (
    company_id  BIGINT PRIMARY KEY REFERENCES companies (id),
    regime      TEXT  NOT NULL,                 -- what the maker acts on (after any admin override)
    auto_regime TEXT  NOT NULL,                 -- what the analysis alone says
    data        JSONB NOT NULL,
    plan        JSONB NOT NULL,
    reason      TEXT  NOT NULL DEFAULT '',
    computed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS maker_market (
    id          INT PRIMARY KEY CHECK (id = 1),
    regime      TEXT  NOT NULL,
    auto_regime TEXT  NOT NULL,
    data        JSONB NOT NULL,
    computed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- The admin's say over the direction of a company, or of the market (company_id NULL). It lapses on its own.
CREATE TABLE IF NOT EXISTS maker_overrides (
    id         BIGSERIAL PRIMARY KEY,
    company_id BIGINT REFERENCES companies (id),
    mode       TEXT NOT NULL CHECK (mode IN ('follow', 'oppose', 'up', 'down', 'range')),
    expires_at TIMESTAMPTZ NOT NULL,
    created_by BIGINT REFERENCES users (id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS maker_overrides_company ON maker_overrides (company_id, id DESC);

-- Things the admin should know: accumulation, concentration, suspected groups, and patterns forming.
CREATE TABLE IF NOT EXISTS maker_alerts (
    id          BIGSERIAL PRIMARY KEY,
    type        TEXT   NOT NULL,
    company_id  BIGINT REFERENCES companies (id),
    user_id     BIGINT REFERENCES users (id),
    title       TEXT   NOT NULL,
    details     JSONB  NOT NULL DEFAULT '{}',
    day         DATE   NOT NULL DEFAULT current_date,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at TIMESTAMPTZ,
    resolution  TEXT,
    resolved_by BIGINT REFERENCES users (id)
);

-- One alert of a kind per company and member per day.
CREATE UNIQUE INDEX IF NOT EXISTS maker_alerts_once
    ON maker_alerts (type, COALESCE(company_id, 0), COALESCE(user_id, 0), day);

INSERT INTO settings (key, value) VALUES
    ('maker_brain',             '"shadow"'),  -- off | shadow (analyse and plan, place nothing)
    ('maker_confirm_runs',      '3'),         -- runs a new reading must last before the maker acts on it
    ('maker_reserve_bps',       '1500'),      -- share of the free float the fund never sells without the admin
    ('maker_sell_cap_bps',      '100'),       -- daily sell cap: share of the fund's holding at session start
    ('maker_wave_cap_bps',      '200'),       -- the same cap while an unexplained run is on
    ('maker_wave_days',         '3'),         -- a run is more than this many rising sessions in a row
    ('maker_wave_rise_bps',     '1500'),      -- ...that add up to at least this much
    ('maker_accum_holder_bps',  '1000'),      -- accumulation: a member holds this share of the free float
    ('maker_accum_volume_bps',  '3000'),      -- ...or bought this share of the day's volume
    ('maker_accum_buy_bps',     '12000')      -- then the maker aims to buy this much of what it sold that day
ON CONFLICT (key) DO NOTHING;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS maker_alerts, maker_overrides, maker_market, maker_state, maker_days, maker_bars;
            ALTER TABLE companies DROP COLUMN IF EXISTS maker_reserve_open;
            DELETE FROM settings WHERE key LIKE 'maker\_%';
            SQL);
    }
};
