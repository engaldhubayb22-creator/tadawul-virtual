-- Tadawul Virtual: phase 1 core schema (PostgreSQL 16).
-- Money is BIGINT in halalas (1 SAR = 100 halalas). Quantities are BIGINT shares.
-- This file is the reference design; Laravel migrations will be generated from it.

BEGIN;

CREATE TABLE users (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT        NOT NULL,
    alias         TEXT        NOT NULL UNIQUE,          -- public display name
    mobile        TEXT        NOT NULL UNIQUE,
    password_hash TEXT        NOT NULL,
    role          TEXT        NOT NULL DEFAULT 'trader'
                  CHECK (role IN ('trader', 'moderator', 'admin', 'bot')),
    status        TEXT        NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active', 'suspended', 'banned')),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Settings the admin panel changes at runtime (starting balance, trading
-- hours, commission, fluctuation limit, ...).
CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      JSONB       NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO settings (key, value) VALUES
    ('starting_balance_halalas', '10000000'),            -- 100,000 SAR, placeholder
    ('market_open',              '"10:00"'),
    ('market_close',             '"22:00"'),
    ('market_timezone',          '"Asia/Riyadh"'),
    ('daily_limit_bps',          '1000'),                -- 10%
    ('market_hours_enforced',    'true'),
    ('commission_bps',           '0');

-- ---------------------------------------------------------------------------
-- Double-entry ledger
-- ---------------------------------------------------------------------------

CREATE TABLE ledger_accounts (
    id       BIGSERIAL PRIMARY KEY,
    user_id  BIGINT REFERENCES users (id),               -- NULL for system accounts
    kind     TEXT   NOT NULL
             CHECK (kind IN ('cash', 'reserved', 'financing',
                             'sys_treasury', 'sys_commission', 'sys_financing')),
    -- Cached balance, updated in the same transaction as the entry.
    balance  BIGINT NOT NULL DEFAULT 0,
    UNIQUE (user_id, kind),
    -- User accounts can never go negative; system accounts can.
    CHECK (user_id IS NULL OR balance >= 0)
);

-- Only one account of each system kind.
CREATE UNIQUE INDEX ledger_accounts_system_kind
    ON ledger_accounts (kind) WHERE user_id IS NULL;

INSERT INTO ledger_accounts (user_id, kind) VALUES
    (NULL, 'sys_treasury'), (NULL, 'sys_commission'), (NULL, 'sys_financing');

-- Append-only. Every movement debits one account and credits another.
CREATE TABLE ledger_entries (
    id                BIGSERIAL PRIMARY KEY,
    debit_account_id  BIGINT      NOT NULL REFERENCES ledger_accounts (id),
    credit_account_id BIGINT      NOT NULL REFERENCES ledger_accounts (id),
    amount            BIGINT      NOT NULL CHECK (amount > 0),
    ref_type          TEXT        NOT NULL,              -- 'trade', 'order_reserve', 'grant', ...
    ref_id            BIGINT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (debit_account_id <> credit_account_id)
);

CREATE INDEX ledger_entries_debit  ON ledger_entries (debit_account_id, id);
CREATE INDEX ledger_entries_credit ON ledger_entries (credit_account_id, id);
CREATE INDEX ledger_entries_ref    ON ledger_entries (ref_type, ref_id);

CREATE FUNCTION ledger_entries_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'ledger_entries is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER ledger_entries_no_update
    BEFORE UPDATE OR DELETE ON ledger_entries
    FOR EACH ROW EXECUTE FUNCTION ledger_entries_immutable();

-- ---------------------------------------------------------------------------
-- Market
-- ---------------------------------------------------------------------------

CREATE TABLE sectors (
    id   SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE companies (
    id                 BIGSERIAL PRIMARY KEY,
    symbol             TEXT   NOT NULL UNIQUE,
    name               TEXT   NOT NULL,
    sector_id          INT    REFERENCES sectors (id),
    shares_outstanding BIGINT NOT NULL CHECK (shares_outstanding > 0),
    tick_size          BIGINT NOT NULL DEFAULT 1 CHECK (tick_size > 0),
    -- Listing price: the reference for the first trading day's limits.
    ref_price          BIGINT NOT NULL CHECK (ref_price > 0),
    status             TEXT   NOT NULL DEFAULT 'trading'
                       CHECK (status IN ('pending', 'trading', 'halted', 'delisted')),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE orders (
    id              BIGSERIAL PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users (id),
    company_id      BIGINT NOT NULL REFERENCES companies (id),
    side            TEXT   NOT NULL CHECK (side IN ('buy', 'sell')),
    kind            TEXT   NOT NULL CHECK (kind IN ('limit', 'market', 'stop')),
    price           BIGINT CHECK (price > 0),            -- NULL for market orders
    stop_price      BIGINT CHECK (stop_price > 0),
    qty             BIGINT NOT NULL CHECK (qty > 0),
    -- Per-share amount reserved for a buy order: the limit price, or the
    -- day's high limit for a market order. NULL for sell orders.
    reserve_price   BIGINT CHECK (reserve_price > 0),
    filled_qty      BIGINT NOT NULL DEFAULT 0 CHECK (filled_qty >= 0),
    status          TEXT   NOT NULL DEFAULT 'new'
                    CHECK (status IN ('new', 'open', 'partial', 'filled',
                                      'cancelled', 'rejected', 'expired')),
    time_in_force   TEXT   NOT NULL DEFAULT 'day' CHECK (time_in_force IN ('day', 'gtc')),
    -- Client-supplied key: the same request sent twice creates one order.
    idempotency_key TEXT   NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (user_id, idempotency_key),
    CHECK (filled_qty <= qty),
    CHECK (kind <> 'limit' OR price IS NOT NULL),
    CHECK (kind <> 'stop'  OR stop_price IS NOT NULL)
);

CREATE INDEX orders_user_open ON orders (user_id, id)
    WHERE status IN ('new', 'open', 'partial');
CREATE INDEX orders_company_open ON orders (company_id, id)
    WHERE status IN ('new', 'open', 'partial');

CREATE TABLE trades (
    id            BIGSERIAL PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies (id),
    buy_order_id  BIGINT NOT NULL REFERENCES orders (id),
    sell_order_id BIGINT NOT NULL REFERENCES orders (id),
    buy_user_id   BIGINT NOT NULL REFERENCES users (id),
    sell_user_id  BIGINT NOT NULL REFERENCES users (id),
    price         BIGINT NOT NULL CHECK (price > 0),
    qty           BIGINT NOT NULL CHECK (qty > 0),
    taker_side    TEXT   NOT NULL CHECK (taker_side IN ('buy', 'sell')),
    executed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (buy_user_id <> sell_user_id)
);

CREATE INDEX trades_company_time ON trades (company_id, executed_at DESC);
CREATE INDEX trades_buy_user     ON trades (buy_user_id, id);
CREATE INDEX trades_sell_user    ON trades (sell_user_id, id);

CREATE TABLE positions (
    user_id      BIGINT NOT NULL REFERENCES users (id),
    company_id   BIGINT NOT NULL REFERENCES companies (id),
    qty          BIGINT NOT NULL DEFAULT 0 CHECK (qty >= 0),
    reserved_qty BIGINT NOT NULL DEFAULT 0 CHECK (reserved_qty >= 0),  -- in open sell orders
    cost_total   BIGINT NOT NULL DEFAULT 0 CHECK (cost_total >= 0),    -- avg cost = cost_total / qty
    PRIMARY KEY (user_id, company_id),
    CHECK (reserved_qty <= qty)
);

CREATE INDEX positions_company ON positions (company_id, qty DESC);     -- top holders

CREATE TABLE candles (
    company_id BIGINT      NOT NULL REFERENCES companies (id),
    interval   TEXT        NOT NULL CHECK (interval IN ('1m', '5m', '15m', '1h', '1d')),
    bucket     TIMESTAMPTZ NOT NULL,
    open       BIGINT      NOT NULL,
    high       BIGINT      NOT NULL,
    low        BIGINT      NOT NULL,
    close      BIGINT      NOT NULL,
    volume     BIGINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, interval, bucket),
    CHECK (low <= open AND low <= close AND high >= open AND high >= close)
);

CREATE TABLE daily_stats (
    company_id  BIGINT NOT NULL REFERENCES companies (id),
    trade_date  DATE   NOT NULL,
    prev_close  BIGINT NOT NULL CHECK (prev_close > 0),
    low_limit   BIGINT NOT NULL,
    high_limit  BIGINT NOT NULL,
    close       BIGINT,                                  -- set at market close
    volume      BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, trade_date),
    CHECK (low_limit <= prev_close AND prev_close <= high_limit)
);

COMMIT;
