-- Tadawul Virtual: phase 1 core schema (PostgreSQL 16).
-- Money is BIGINT in halalas (1 SAR = 100 halalas). Quantities are BIGINT shares.
-- This file is the single source of truth for the schema. The Laravel migration
-- in api/database/migrations loads it as is; do not add BEGIN/COMMIT here.


CREATE TABLE users (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT        NOT NULL,
    alias         TEXT        NOT NULL UNIQUE,          -- public display name
    mobile        TEXT        NOT NULL UNIQUE,
    password      TEXT        NOT NULL,                 -- bcrypt hash
    role          TEXT        NOT NULL DEFAULT 'trader'
                  CHECK (role IN ('trader', 'moderator', 'admin', 'bot')),
    status        TEXT        NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active', 'suspended', 'banned')),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- API tokens (Laravel Sanctum).
CREATE TABLE personal_access_tokens (
    id             BIGSERIAL PRIMARY KEY,
    tokenable_type TEXT        NOT NULL,
    tokenable_id   BIGINT      NOT NULL,
    name           TEXT        NOT NULL,
    token          VARCHAR(64) NOT NULL UNIQUE,
    abilities      TEXT,
    last_used_at   TIMESTAMP,
    expires_at     TIMESTAMP,
    created_at     TIMESTAMP,
    updated_at     TIMESTAMP
);

CREATE INDEX personal_access_tokens_owner
    ON personal_access_tokens (tokenable_type, tokenable_id);

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
    ('commission_bps',           '15.5'),                -- charged to each side of a trade
    ('market_days',              '[0,1,2,3,4,5,6]');     -- 0 = Sunday ... 6 = Saturday

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

CREATE OR REPLACE FUNCTION ledger_entries_immutable() RETURNS trigger AS $$
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
    -- Issued shares. Every one of them is accounted for: founder_shares are
    -- held outside the market and never trade; the rest is the free float,
    -- which must equal the sum of all positions in the company.
    shares_outstanding BIGINT NOT NULL CHECK (shares_outstanding > 0),
    founder_shares     BIGINT NOT NULL DEFAULT 0 CHECK (founder_shares >= 0),
    par_value          BIGINT NOT NULL DEFAULT 1000 CHECK (par_value > 0),   -- nominal value per share, 10.00
    -- Virtual fundamentals, set by the admin: total shareholders' equity and
    -- trailing twelve-month net income, in halalas.
    equity             BIGINT NOT NULL DEFAULT 0,
    net_income         BIGINT NOT NULL DEFAULT 0,
    tick_size          BIGINT NOT NULL DEFAULT 1 CHECK (tick_size > 0),
    -- Listing price: the reference for the first trading day's limits.
    ref_price          BIGINT NOT NULL CHECK (ref_price > 0),
    -- Set when an IPO lists: the first three trading days get wider limits.
    listed_at          DATE,
    status             TEXT   NOT NULL DEFAULT 'trading'
                       CHECK (status IN ('pending', 'trading', 'halted', 'delisted')),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Company file, written by the admin and shown on the company's page.
    about              TEXT   NOT NULL DEFAULT '',
    founded_year       INT    CHECK (founded_year BETWEEN 1800 AND 2200),
    headquarters       TEXT   NOT NULL DEFAULT '',
    website            TEXT   NOT NULL DEFAULT '',
    CHECK (founder_shares < shares_outstanding)
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
    -- Worst price a buy order may pay: its limit, or for a market order five
    -- price steps above the best offer when it arrived. NULL for sell orders.
    reserve_price   BIGINT CHECK (reserve_price > 0),
    filled_qty      BIGINT NOT NULL DEFAULT 0 CHECK (filled_qty >= 0),
    status          TEXT   NOT NULL DEFAULT 'new'
                    CHECK (status IN ('new', 'open', 'partial', 'filled',
                                      'cancelled', 'rejected', 'expired')),
    -- day/gtc rest in the book; ioc fills what it can; fok fills all or nothing.
    time_in_force   TEXT   NOT NULL DEFAULT 'day' CHECK (time_in_force IN ('day', 'gtc', 'ioc', 'fok')),
    -- Cash still held for this buy order (price and commission). Released when the order ends.
    reserved_amount BIGINT NOT NULL DEFAULT 0 CHECK (reserved_amount >= 0),
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
    -- Shares that cannot be sold: those in open sell orders plus pledged ones.
    reserved_qty BIGINT NOT NULL DEFAULT 0 CHECK (reserved_qty >= 0),
    cost_total   BIGINT NOT NULL DEFAULT 0 CHECK (cost_total >= 0),    -- avg cost = cost_total / qty
    -- Shares pledged to the bank against a loan. They are counted inside
    -- reserved_qty as well, which is what keeps the engine from selling them.
    pledged_qty  BIGINT NOT NULL DEFAULT 0 CHECK (pledged_qty >= 0),
    PRIMARY KEY (user_id, company_id),
    CHECK (reserved_qty <= qty),
    CONSTRAINT positions_pledged_within_reserved CHECK (pledged_qty <= reserved_qty)
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


-- ---------------------------------------------------------------------------
-- Market maker and news
-- ---------------------------------------------------------------------------

-- One row per company the admin has tuned; companies without a row use the
-- column defaults. The bot re-reads this table on every cycle.
CREATE TABLE bot_configs (
    company_id   BIGINT  PRIMARY KEY REFERENCES companies (id),
    enabled      BOOLEAN NOT NULL DEFAULT true,
    spread_bps   INT     NOT NULL DEFAULT 10 CHECK (spread_bps BETWEEN 1 AND 500),  -- gap between quote levels
    levels       INT     NOT NULL DEFAULT 5  CHECK (levels BETWEEN 1 AND 10),
    qty_min      BIGINT  NOT NULL DEFAULT 100,
    qty_max      BIGINT  NOT NULL DEFAULT 2000,
    noise_pct    INT     NOT NULL DEFAULT 70 CHECK (noise_pct BETWEEN 0 AND 100),   -- chance per cycle of a random trade
    -- Scenario: steer the price toward target_price until target_until.
    target_price BIGINT  CHECK (target_price > 0),
    target_until TIMESTAMPTZ,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (qty_min > 0 AND qty_min <= qty_max)
);

CREATE TABLE news (
    id         BIGSERIAL PRIMARY KEY,
    company_id BIGINT REFERENCES companies (id),         -- NULL: market-wide
    title      TEXT        NOT NULL,
    body       TEXT        NOT NULL DEFAULT '',
    impact_bps INT         NOT NULL DEFAULT 0,           -- the price move the bots were asked to make
    created_by BIGINT REFERENCES users (id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX news_recent ON news (created_at DESC);

INSERT INTO settings (key, value) VALUES ('bots_enabled', 'true');

-- Who changed what in the admin panel.
CREATE TABLE audit_logs (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT REFERENCES users (id),
    action     TEXT        NOT NULL,
    details    JSONB       NOT NULL DEFAULT '{}',
    ip         TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- IPOs
-- ---------------------------------------------------------------------------

-- The company row is created with status 'pending' when the IPO is announced
-- and switches to 'trading' when the IPO is allocated and listed.
CREATE TABLE ipos (
    id             BIGSERIAL PRIMARY KEY,
    company_id     BIGINT NOT NULL UNIQUE REFERENCES companies (id),
    price          BIGINT NOT NULL CHECK (price > 0),
    shares_offered BIGINT NOT NULL CHECK (shares_offered > 0),
    min_qty        BIGINT NOT NULL CHECK (min_qty > 0),
    max_qty        BIGINT NOT NULL,
    maker_shares   BIGINT NOT NULL DEFAULT 0 CHECK (maker_shares >= 0),  -- issued to the market maker at listing
    closes_at      TIMESTAMPTZ NOT NULL,
    status         TEXT   NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'listed', 'cancelled')),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (min_qty <= max_qty)
);

CREATE TABLE ipo_subscriptions (
    ipo_id        BIGINT NOT NULL REFERENCES ipos (id),
    user_id       BIGINT NOT NULL REFERENCES users (id),
    qty_requested BIGINT NOT NULL CHECK (qty_requested > 0),
    qty_allocated BIGINT CHECK (qty_allocated >= 0),     -- NULL until allocation
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (ipo_id, user_id),
    CHECK (qty_allocated IS NULL OR qty_allocated <= qty_requested)
);

-- ---------------------------------------------------------------------------
-- Margin financing
-- ---------------------------------------------------------------------------

-- A fixed-fee financing: the fee is agreed up front and does not grow.
CREATE TABLE financings (
    id               BIGSERIAL PRIMARY KEY,
    user_id          BIGINT NOT NULL REFERENCES users (id),
    principal        BIGINT NOT NULL CHECK (principal > 0),
    fee              BIGINT NOT NULL CHECK (fee >= 0),
    status           TEXT   NOT NULL DEFAULT 'active'
                     CHECK (status IN ('active', 'liquidating', 'repaid', 'liquidated', 'defaulted')),
    due_at           TIMESTAMPTZ NOT NULL,
    margin_called_at TIMESTAMPTZ,
    written_off      BIGINT NOT NULL DEFAULT 0 CHECK (written_off >= 0),  -- unpaid amount on default
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_at        TIMESTAMPTZ
);

-- One open financing per user at a time.
CREATE UNIQUE INDEX financings_one_open ON financings (user_id) WHERE status IN ('active', 'liquidating');

INSERT INTO settings (key, value) VALUES
    ('financing_enabled',       'true'),
    ('financing_max_ratio_bps', '10000'),   -- may owe up to 100% of own equity
    ('financing_fee_bps',       '100'),     -- fixed fee: 1% of the amount (placeholder)
    ('financing_days',          '30'),
    ('margin_call_bps',         '3000'),    -- warn when equity falls to 30% of assets
    ('liquidation_bps',         '2000');    -- sell out when it falls to 20%

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

-- ---------------------------------------------------------------------------
-- Chat and forum
-- ---------------------------------------------------------------------------

ALTER TABLE users ADD COLUMN chat_muted_until TIMESTAMPTZ;

-- room is 'general' or a company symbol.
CREATE TABLE chat_messages (
    id         BIGSERIAL PRIMARY KEY,
    room       TEXT        NOT NULL,
    user_id    BIGINT      NOT NULL REFERENCES users (id),
    body       TEXT        NOT NULL CHECK (char_length(body) BETWEEN 1 AND 300),
    deleted_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX chat_messages_room ON chat_messages (room, id DESC);

CREATE TABLE forum_threads (
    id           BIGSERIAL PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES users (id),
    company_id   BIGINT REFERENCES companies (id),       -- NULL: general discussion
    title        TEXT        NOT NULL CHECK (char_length(title) BETWEEN 3 AND 150),
    body         TEXT        NOT NULL CHECK (char_length(body) BETWEEN 1 AND 10000),
    reply_count  INT         NOT NULL DEFAULT 0,
    last_post_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX forum_threads_recent ON forum_threads (last_post_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE forum_posts (
    id         BIGSERIAL PRIMARY KEY,
    thread_id  BIGINT      NOT NULL REFERENCES forum_threads (id),
    user_id    BIGINT      NOT NULL REFERENCES users (id),
    body       TEXT        NOT NULL CHECK (char_length(body) BETWEEN 1 AND 10000),
    deleted_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX forum_posts_thread ON forum_posts (thread_id, id);

-- ---------------------------------------------------------------------------
-- Forum sections
-- ---------------------------------------------------------------------------

-- Two levels, as in a classic bulletin board: a category (parent_id NULL)
-- holds forums, and threads are posted in a forum.
CREATE TABLE IF NOT EXISTS forum_boards (
    id          SERIAL PRIMARY KEY,
    parent_id   INT REFERENCES forum_boards (id),
    name        TEXT NOT NULL CHECK (char_length(name) BETWEEN 2 AND 80),
    description TEXT NOT NULL DEFAULT '',
    position    INT  NOT NULL DEFAULT 0,
    staff_only  BOOLEAN NOT NULL DEFAULT false      -- only staff may open threads (announcements)
);

ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS board_id INT REFERENCES forum_boards (id);
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS views INT NOT NULL DEFAULT 0;
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS pinned BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS locked BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS last_post_user_id BIGINT REFERENCES users (id);
ALTER TABLE forum_threads ADD COLUMN IF NOT EXISTS edited_at TIMESTAMPTZ;
ALTER TABLE forum_posts ADD COLUMN IF NOT EXISTS edited_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS forum_signature TEXT NOT NULL DEFAULT '';

CREATE INDEX IF NOT EXISTS forum_threads_board ON forum_threads (board_id, pinned DESC, last_post_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS forum_threads_user ON forum_threads (user_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS forum_posts_user ON forum_posts (user_id) WHERE deleted_at IS NULL;

-- Images and attachments members upload to use in forum posts. The file
-- itself is on disk under a random name; `name` is what the member called it.
CREATE TABLE IF NOT EXISTS forum_files (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT NOT NULL REFERENCES users (id),
    name       TEXT   NOT NULL,
    path       TEXT   NOT NULL,
    mime       TEXT   NOT NULL,
    size       BIGINT NOT NULL CHECK (size > 0),
    is_image   BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS forum_files_user ON forum_files (user_id, created_at);

-- A starting layout, only when no section exists yet. The admin can change it.
DO $$
DECLARE general INT; market INT; site INT;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM forum_boards) THEN
        INSERT INTO forum_boards (name, position) VALUES ('القسم العام', 1) RETURNING id INTO general;
        INSERT INTO forum_boards (name, position) VALUES ('منتديات السوق', 2) RETURNING id INTO market;
        INSERT INTO forum_boards (name, position) VALUES ('خدمات الموقع', 3) RETURNING id INTO site;
        INSERT INTO forum_boards (parent_id, name, description, position, staff_only) VALUES
            (general, 'إعلانات الإدارة', 'إعلانات وتنبيهات إدارة الموقع', 1, true),
            (general, 'المنتدى العام', 'نقاشات السوق العامة وكل ما لا يخص شركة بعينها', 2, false),
            (general, 'الترحيب والتعارف', 'رحّب بالأعضاء الجدد وعرّف بنفسك', 3, false),
            (market, 'تحليل الأسهم', 'تحليلات الشركات ونقاش أسهمها', 1, false),
            (market, 'التحليل الفني', 'الشارتات والنماذج الفنية والدعوم والمقاومات', 2, false),
            (market, 'أخبار الشركات وإعلاناتها', 'نقاش أخبار الشركات وإعلاناتها وأثرها', 3, false),
            (market, 'المحافظ والتوصيات', 'متابعة المحافظ وتوصيات الأعضاء', 4, false),
            (market, 'الاكتتابات', 'الاكتتابات المطروحة والإدراجات الجديدة', 5, false),
            (site, 'الاقتراحات والشكاوى', 'اقتراحاتك لتطوير الموقع وبلاغات المشاكل', 1, false),
            (site, 'شرح استخدام الموقع', 'أسئلة وشروحات عن التداول والبنك وبقية الخدمات', 2, false);
    END IF;
    -- Threads written before sections existed: company threads go to stock analysis, the rest to the general forum.
    UPDATE forum_threads t SET board_id = (
        SELECT id FROM forum_boards WHERE parent_id IS NOT NULL AND name =
            CASE WHEN t.company_id IS NULL THEN 'المنتدى العام' ELSE 'تحليل الأسهم' END LIMIT 1)
    WHERE t.board_id IS NULL;
END $$;
