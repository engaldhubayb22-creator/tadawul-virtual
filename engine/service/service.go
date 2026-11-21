// Package service connects the in-memory order books to PostgreSQL.
//
// The engine is the single writer for everything a trade touches: orders,
// trades, positions and the cash ledger. Each command runs in one database
// transaction: reserve, match, settle, commit. If the transaction fails after
// the book was changed, the book is rebuilt from the stored open orders.
//
// Engine is not safe for concurrent use: feed it commands from one goroutine.
package service

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/book"
)

// Command is one instruction read from the orders queue.
type Command struct {
	Type    string `json:"type"` // "new" or "cancel"
	UserID  int64  `json:"user_id"`
	Symbol  string `json:"symbol,omitempty"`
	Side    string `json:"side,omitempty"` // "buy" or "sell"
	Kind    string `json:"kind,omitempty"` // "limit" or "market"
	Price   int64  `json:"price,omitempty"`
	Qty     int64  `json:"qty,omitempty"`
	Key     string `json:"key,omitempty"` // idempotency key
	TIF     string `json:"tif,omitempty"` // "" or "day": rest; "ioc": fill and cancel; "fok": all or nothing
	OrderID int64  `json:"order_id,omitempty"`
}

// Event is published after a command commits.
type Event struct {
	Type      string           `json:"type"` // "order", "trade" or "book"
	Symbol    string           `json:"symbol,omitempty"`
	UserID    int64            `json:"user_id,omitempty"`
	OrderID   int64            `json:"order_id,omitempty"`
	Key       string           `json:"key,omitempty"`
	Status    string           `json:"status,omitempty"`
	Reason    string           `json:"reason,omitempty"`
	FilledQty int64            `json:"filled_qty,omitempty"`
	Price     int64            `json:"price,omitempty"`
	Qty       int64            `json:"qty,omitempty"`
	TakerSide string           `json:"taker_side,omitempty"`
	Last      int64            `json:"last,omitempty"`
	Bids      []book.LevelView `json:"bids,omitempty"`
	Asks      []book.LevelView `json:"asks,omitempty"`

	// Chat messages travel on the same stream; the API publishes them and
	// the engine never does. Type is "chat" or "chat_delete".
	ID    int64  `json:"id,omitempty"`
	Room  string `json:"room,omitempty"`
	Alias string `json:"alias,omitempty"`
	Text  string `json:"text,omitempty"`
}

type company struct {
	id     int64
	symbol string
	tick   int64
	status string
	book   *book.Book
}

type Engine struct {
	db   *sql.DB
	Now  func() time.Time
	byID map[int64]*company
	bySy map[string]*company

	tz        *time.Location
	openMin   int
	closeMin  int
	enforced  bool
	limitBps  int64
	feeTenths int64        // commission in tenths of a basis point (15.5 bps = 155)
	days      map[int]bool // weekdays the market trades on, 0 = Sunday
	day       string
	refreshed time.Time
	DepthSize int

	// Ledger account ids by owner and kind. Accounts are never deleted, so an
	// id stays valid; the cache is dropped when a batch rolls back, because an
	// account created inside that batch no longer exists.
	accts map[acctKey]int64
	stmts map[string]*sql.Stmt // prepared statements by query text

	testHook func(Command) error // tests only: inject a failure after a command
}

type acctKey struct {
	user int64 // 0 for a system account
	kind string
}

func New(db *sql.DB) *Engine {
	return &Engine{
		db:        db,
		Now:       time.Now,
		byID:      map[int64]*company{},
		bySy:      map[string]*company{},
		accts:     map[acctKey]int64{},
		stmts:     map[string]*sql.Stmt{},
		DepthSize: 10,
	}
}

// Load reads settings and companies, opens the trading day and rebuilds every
// book from the stored open orders. Call it once before Process.
func (e *Engine) Load(ctx context.Context) error {
	if err := e.loadSettings(ctx); err != nil {
		return err
	}
	rows, err := e.db.QueryContext(ctx, `SELECT id, symbol, tick_size, status FROM companies`)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		c := &company{}
		if err := rows.Scan(&c.id, &c.symbol, &c.tick, &c.status); err != nil {
			return err
		}
		e.byID[c.id], e.bySy[c.symbol] = c, c
	}
	if err := rows.Err(); err != nil {
		return err
	}
	e.day = ""
	e.refreshed = e.Now()
	return e.ensureDay(ctx)
}

func (e *Engine) loadSettings(ctx context.Context) error {
	get := func(key string, dst any) error {
		var raw []byte
		err := e.db.QueryRowContext(ctx, `SELECT value FROM settings WHERE key = $1`, key).Scan(&raw)
		if err != nil {
			return fmt.Errorf("setting %s: %w", key, err)
		}
		return json.Unmarshal(raw, dst)
	}
	var open, close, tz string
	if err := get("market_open", &open); err != nil {
		return err
	}
	if err := get("market_close", &close); err != nil {
		return err
	}
	if err := get("market_timezone", &tz); err != nil {
		return err
	}
	if err := get("market_hours_enforced", &e.enforced); err != nil {
		return err
	}
	if err := get("daily_limit_bps", &e.limitBps); err != nil {
		return err
	}
	var feeBps float64
	if err := get("commission_bps", &feeBps); err != nil {
		return err
	}
	e.feeTenths = int64(feeBps*10 + 0.5)
	var days []int
	if err := get("market_days", &days); err != nil {
		return err
	}
	e.days = map[int]bool{}
	for _, d := range days {
		e.days[d] = true
	}
	loc, err := time.LoadLocation(tz)
	if err != nil {
		return err
	}
	e.tz = loc
	if e.openMin, err = parseHM(open); err != nil {
		return err
	}
	if e.closeMin, err = parseHM(close); err != nil {
		return err
	}
	return nil
}

func parseHM(s string) (int, error) {
	h, m, ok := strings.Cut(s, ":")
	hh, err1 := strconv.Atoi(h)
	mm, err2 := strconv.Atoi(m)
	if !ok || err1 != nil || err2 != nil || hh < 0 || hh > 23 || mm < 0 || mm > 59 {
		return 0, fmt.Errorf("bad time %q, want HH:MM", s)
	}
	return hh*60 + mm, nil
}

// refreshEvery is how quickly a change made in the admin panel (market
// hours, a trading halt) reaches the engine.
const refreshEvery = 5 * time.Second

// refresh re-reads the settings and each company's status, at most once
// every refreshEvery.
func (e *Engine) refresh(ctx context.Context) error {
	if e.Now().Sub(e.refreshed) < refreshEvery {
		return nil
	}
	if err := e.loadSettings(ctx); err != nil {
		return err
	}
	rows, err := e.db.QueryContext(ctx, `SELECT id, symbol, tick_size, status FROM companies`)
	if err != nil {
		return err
	}
	var added, listed []*company
	for rows.Next() {
		n := &company{}
		if err := rows.Scan(&n.id, &n.symbol, &n.tick, &n.status); err != nil {
			rows.Close()
			return err
		}
		if c := e.byID[n.id]; c != nil {
			if c.status == "pending" && n.status == "trading" {
				listed = append(listed, c)
			}
			c.status = n.status
		} else {
			added = append(added, n)
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	// A company announced after the engine started (an IPO): open its
	// trading day and give it an empty book.
	for _, c := range added {
		if e.day != "" {
			if err := e.openCompanyDay(ctx, c); err != nil {
				return err
			}
		}
		e.byID[c.id], e.bySy[c.symbol] = c, c
	}
	// An IPO that just listed: today's limits were opened at the normal
	// width while it was pending; reopen them at the first-days width.
	for _, c := range listed {
		if err := e.openCompanyDay(ctx, c); err != nil {
			return err
		}
	}
	e.refreshed = e.Now()
	return nil
}

// openCompanyDay sets today's limits for one company from its listing price
// (or yesterday's close) and rebuilds its book.
func (e *Engine) openCompanyDay(ctx context.Context, c *company) error {
	if _, err := e.db.ExecContext(ctx, `
		INSERT INTO daily_stats (company_id, trade_date, prev_close, low_limit, high_limit)
		SELECT c.id, $1::date, p.prev,
		       (p.prev * (10000 - w.bps) + 9999) / 10000, (p.prev * (10000 + w.bps)) / 10000
		FROM companies c
		CROSS JOIN LATERAL (
			SELECT CASE WHEN c.listed_at IS NOT NULL AND (
				SELECT count(*) FROM daily_stats d
				WHERE d.company_id = c.id AND d.trade_date >= c.listed_at AND d.trade_date < $1::date
			) < 3 THEN 3000 ELSE $2::bigint END AS bps
		) w
		CROSS JOIN LATERAL (
			SELECT COALESCE(
				(SELECT d.close FROM daily_stats d
				 WHERE d.company_id = c.id AND d.trade_date < $1::date AND d.close IS NOT NULL
				 ORDER BY d.trade_date DESC LIMIT 1),
				c.ref_price) AS prev
		) p
		WHERE c.id = $3
		ON CONFLICT (company_id, trade_date)
		DO UPDATE SET low_limit = EXCLUDED.low_limit, high_limit = EXCLUDED.high_limit`,
		e.day, e.limitBps, c.id); err != nil {
		return err
	}
	return e.reloadBook(ctx, c)
}

// Tick does the engine's housekeeping when no commands are arriving: it
// applies admin changes, picks up new companies and rolls the trading day.
func (e *Engine) Tick(ctx context.Context) error {
	if err := e.refresh(ctx); err != nil {
		return err
	}
	return e.ensureDay(ctx)
}

// MarketOpen reports whether trading is allowed right now.
func (e *Engine) MarketOpen() bool {
	if !e.enforced {
		return true
	}
	t := e.Now().In(e.tz)
	if !e.days[int(t.Weekday())] {
		return false
	}
	m := t.Hour()*60 + t.Minute()
	return m >= e.openMin && m < e.closeMin
}

// ensureDay opens a new trading day when the date changes: it records
// yesterday's close, expires day orders, sets today's price limits and
// rebuilds the books.
func (e *Engine) ensureDay(ctx context.Context) error {
	day := e.Now().In(e.tz).Format("2006-01-02")
	if day == e.day {
		return nil
	}
	tx, err := e.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()

	if _, err := tx.ExecContext(ctx, `
		UPDATE daily_stats d
		SET close = COALESCE(
			(SELECT t.price FROM trades t WHERE t.company_id = d.company_id ORDER BY t.id DESC LIMIT 1),
			d.prev_close)
		WHERE d.close IS NULL AND d.trade_date < $1::date`, day); err != nil {
		return err
	}

	// Expire day orders left over from earlier days and release what they hold.
	rows, err := tx.QueryContext(ctx, `
		SELECT id, user_id, company_id, side, qty - filled_qty
		FROM orders
		WHERE status IN ('open', 'partial') AND time_in_force = 'day'
		  AND (created_at AT TIME ZONE $2)::date < $1::date`, day, e.tz.String())
	if err != nil {
		return err
	}
	type stale struct {
		id, user, company, remaining int64
		side                         string
	}
	var old []stale
	for rows.Next() {
		var s stale
		if err := rows.Scan(&s.id, &s.user, &s.company, &s.side, &s.remaining); err != nil {
			rows.Close()
			return err
		}
		old = append(old, s)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	for _, s := range old {
		if err := e.release(ctx, tx, s.user, s.company, s.side, s.remaining, s.id); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx,
			`UPDATE orders SET status = 'expired', updated_at = now() WHERE id = $1`, s.id); err != nil {
			return err
		}
	}

	if _, err := tx.ExecContext(ctx, `
		INSERT INTO daily_stats (company_id, trade_date, prev_close, low_limit, high_limit)
		SELECT c.id, $1::date, p.prev,
		       (p.prev * (10000 - w.bps) + 9999) / 10000,
		       (p.prev * (10000 + w.bps)) / 10000
		FROM companies c
		CROSS JOIN LATERAL (
			-- Newly listed: 30% for the first three trading days, then the normal limit.
			SELECT CASE WHEN c.listed_at IS NOT NULL AND (
				SELECT count(*) FROM daily_stats d WHERE d.company_id = c.id AND d.trade_date >= c.listed_at
			) < 3 THEN 3000 ELSE $2::bigint END AS bps
		) w
		CROSS JOIN LATERAL (
			SELECT COALESCE(
				(SELECT d.close FROM daily_stats d
				 WHERE d.company_id = c.id AND d.trade_date < $1::date AND d.close IS NOT NULL
				 ORDER BY d.trade_date DESC LIMIT 1),
				c.ref_price) AS prev
		) p
		ON CONFLICT (company_id, trade_date) DO NOTHING`, day, e.limitBps); err != nil {
		return err
	}
	if err := tx.Commit(); err != nil {
		return err
	}
	e.day = day
	for _, c := range e.byID {
		if err := e.reloadBook(ctx, c); err != nil {
			return err
		}
	}
	return nil
}

// reloadBook rebuilds one company's book from the database.
func (e *Engine) reloadBook(ctx context.Context, c *company) error {
	b := book.New(c.symbol, c.tick)
	var low, high, prev int64
	err := e.db.QueryRowContext(ctx, `
		SELECT low_limit, high_limit, prev_close FROM daily_stats
		WHERE company_id = $1 AND trade_date = $2::date`, c.id, e.day).Scan(&low, &high, &prev)
	if err != nil {
		return fmt.Errorf("limits for %s: %w", c.symbol, err)
	}
	b.TickFn = book.BandTick
	// Limits must be prices a trader can actually enter: move each one
	// inward onto the price grid of its own band.
	_, snappedLow := book.NearestTicks(low)
	snappedHigh, _ := book.NearestTicks(high)
	if snappedLow != low || snappedHigh != high {
		if _, err := e.db.ExecContext(ctx, `
			UPDATE daily_stats SET low_limit = $1, high_limit = $2
			WHERE company_id = $3 AND trade_date = $4::date`, snappedLow, snappedHigh, c.id, e.day); err != nil {
			return err
		}
		low, high = snappedLow, snappedHigh
	}
	b.SetLimits(low, high)

	var last int64
	err = e.db.QueryRowContext(ctx,
		`SELECT price FROM trades WHERE company_id = $1 ORDER BY id DESC LIMIT 1`, c.id).Scan(&last)
	switch {
	case errors.Is(err, sql.ErrNoRows):
		b.SetLastPrice(prev)
	case err != nil:
		return err
	default:
		b.SetLastPrice(last)
	}

	rows, err := e.db.QueryContext(ctx, `
		SELECT id, user_id, side, price, qty - filled_qty FROM orders
		WHERE company_id = $1 AND status IN ('open', 'partial') AND price IS NOT NULL
		ORDER BY id`, c.id)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var id, user, price, remaining int64
		var side string
		if err := rows.Scan(&id, &user, &side, &price, &remaining); err != nil {
			return err
		}
		o := book.Order{ID: uint64(id), UserID: uint64(user), Side: parseSide(side),
			Price: price, Qty: remaining, Remaining: remaining}
		if err := b.Rest(o); err != nil {
			return fmt.Errorf("restore order %d: %w", id, err)
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	c.book = b
	return nil
}

func parseSide(s string) book.Side {
	switch s {
	case "buy":
		return book.Buy
	case "sell":
		return book.Sell
	}
	return 0
}

func sideName(s book.Side) string {
	if s == book.Buy {
		return "buy"
	}
	return "sell"
}

func reason(err error) string {
	switch {
	case errors.Is(err, book.ErrBadQty):
		return "bad_quantity"
	case errors.Is(err, book.ErrBadPrice):
		return "bad_price"
	case errors.Is(err, book.ErrBadTick):
		return "bad_tick"
	case errors.Is(err, book.ErrOutOfLimits):
		return "outside_daily_limits"
	case errors.Is(err, book.ErrBadSide):
		return "bad_side"
	case errors.Is(err, book.ErrBadKind):
		return "bad_kind"
	case errors.Is(err, book.ErrHalted):
		return "halted"
	}
	return "invalid"
}

// Process runs one command. See ProcessBatch.
func (e *Engine) Process(ctx context.Context, cmd Command) ([]Event, error) {
	return e.ProcessBatch(ctx, []Command{cmd})
}

// ProcessBatch applies the commands in order inside one database transaction
// and returns the events to publish. Committing many commands at once is what
// makes the engine fast: the cost of a commit is shared by the whole batch.
//
// A returned error is an infrastructure failure: nothing was applied, the
// affected books were rebuilt from the database, and the commands can be
// retried. Business rejections come back as an "order" event with a reason.
func (e *Engine) ProcessBatch(ctx context.Context, cmds []Command) ([]Event, error) {
	if err := e.refresh(ctx); err != nil {
		return nil, err
	}
	if err := e.ensureDay(ctx); err != nil {
		return nil, err
	}
	touched := map[*company]bool{}
	fail := func(err error) ([]Event, error) {
		e.accts = map[acctKey]int64{}
		for c := range touched {
			if rerr := e.reloadBook(ctx, c); rerr != nil {
				return nil, fmt.Errorf("%w (and reload of %s failed: %v)", err, c.symbol, rerr)
			}
		}
		return nil, err
	}
	tx, err := e.db.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	var events []Event
	for _, cmd := range cmds {
		var evs []Event
		var err error
		switch cmd.Type {
		case "new":
			evs, err = e.processNew(ctx, tx, cmd, touched)
		case "cancel":
			evs, err = e.processCancel(ctx, tx, cmd, touched)
		default:
			evs = []Event{reject(cmd, "unknown_command")}
		}
		if err == nil && e.testHook != nil {
			err = e.testHook(cmd)
		}
		if err != nil {
			tx.Rollback()
			return fail(err)
		}
		events = append(events, evs...)
	}
	if err := tx.Commit(); err != nil {
		return fail(err)
	}
	// One depth update per symbol that changed, after everything committed.
	syms := make([]*company, 0, len(touched))
	for c := range touched {
		syms = append(syms, c)
	}
	sort.Slice(syms, func(i, j int) bool { return syms[i].symbol < syms[j].symbol })
	for _, c := range syms {
		events = append(events, e.bookEvent(c))
	}
	return events, nil
}

func reject(cmd Command, why string) Event {
	return Event{Type: "order", Symbol: cmd.Symbol, UserID: cmd.UserID, OrderID: cmd.OrderID,
		Key: cmd.Key, Status: "rejected", Reason: why}
}

// fee is the commission on a trade value, charged to each side.
func (e *Engine) fee(value int64) int64 { return value * e.feeTenths / 100000 }

func (e *Engine) processNew(ctx context.Context, tx *sql.Tx, cmd Command, touched map[*company]bool) ([]Event, error) {
	c := e.bySy[cmd.Symbol]
	if c == nil {
		return []Event{reject(cmd, "unknown_symbol")}, nil
	}
	if c.status != "trading" {
		return []Event{reject(cmd, "halted")}, nil
	}
	if !e.MarketOpen() {
		return []Event{reject(cmd, "market_closed")}, nil
	}
	if cmd.Key == "" || cmd.UserID <= 0 {
		return []Event{reject(cmd, "invalid")}, nil
	}
	o := book.Order{UserID: uint64(cmd.UserID), Side: parseSide(cmd.Side), Qty: cmd.Qty}
	switch cmd.Kind {
	case "limit":
		o.Kind, o.Price = book.Limit, cmd.Price
	case "market":
		o.Kind = book.Market
	}
	tif := cmd.TIF
	switch tif {
	case "", "day":
		tif = "day"
	case "ioc":
		o.TIF = book.IOC
	case "fok":
		o.TIF = book.FOK
	default:
		return []Event{reject(cmd, "invalid")}, nil
	}
	if err := c.book.Validate(o); err != nil {
		return []Event{reject(cmd, reason(err))}, nil
	}

	// The worst price the order may trade at: the trader's limit, or for a
	// market order five price steps beyond the best price available now.
	worst := o.Price
	if o.Kind == book.Market {
		cap, ok := c.book.MarketCap(o.Side)
		if !ok {
			return []Event{reject(cmd, "no_liquidity")}, nil
		}
		worst = cap
	}
	if o.TIF == book.FOK && c.book.Fillable(o.Side, o.UserID, worst, o.Qty) < o.Qty {
		return []Event{reject(cmd, "fok_not_filled")}, nil
	}

	// The first order from a user creates their ledger accounts, which needs
	// the user to exist. After that the ids come from memory.
	_, haveCash := e.accts[acctKey{cmd.UserID, "cash"}]
	_, haveHeld := e.accts[acctKey{cmd.UserID, "reserved"}]
	if !haveCash || !haveHeld {
		var known bool
		if err := e.row(ctx, tx,
			`SELECT EXISTS (SELECT 1 FROM users WHERE id = $1 AND status = 'active')`, cmd.UserID).Scan(&known); err != nil {
			return nil, err
		}
		if !known {
			return []Event{reject(cmd, "unknown_user")}, nil
		}
	}
	cash, err := e.account(ctx, tx, cmd.UserID, "cash")
	if err != nil {
		return nil, err
	}
	heldAcct, err := e.account(ctx, tx, cmd.UserID, "reserved")
	if err != nil {
		return nil, err
	}

	var price, reserve sql.NullInt64
	if o.Kind == book.Limit {
		price = sql.NullInt64{Int64: o.Price, Valid: true}
	}

	// One statement checks the user and the idempotency key, holds what the
	// order could cost at its worst price with commission (buy) or the shares
	// (sell), and stores the order. Nothing is written unless all of it holds.
	var active sql.NullBool
	var existing, newID sql.NullInt64
	var reserved int64
	if o.Side == book.Buy {
		reserved = worst*o.Qty + e.fee(worst*o.Qty)
		reserve = sql.NullInt64{Int64: worst, Valid: true}
		err = e.row(ctx, tx, `
			WITH chk AS (
				SELECT (SELECT status = 'active' FROM users WHERE id = $1::bigint) AS active,
				       (SELECT id FROM orders WHERE user_id = $1::bigint AND idempotency_key = $2::text) AS existing),
			taken AS (
				UPDATE ledger_accounts SET balance = balance - $5::bigint
				WHERE id = $3::bigint AND balance >= $5::bigint
				  AND (SELECT active AND existing IS NULL FROM chk) RETURNING id),
			held AS (
				UPDATE ledger_accounts SET balance = balance + $5::bigint
				WHERE id = $4::bigint AND EXISTS (SELECT 1 FROM taken) RETURNING id),
			entry AS (
				INSERT INTO ledger_entries (debit_account_id, credit_account_id, amount, ref_type, ref_id)
				SELECT $3::bigint, $4::bigint, $5::bigint, 'order_reserve', 0 WHERE EXISTS (SELECT 1 FROM taken)),
			ins AS (
				INSERT INTO orders (user_id, company_id, side, kind, price, qty, reserve_price, reserved_amount,
				                    status, time_in_force, idempotency_key)
				SELECT $1::bigint, $6::bigint, 'buy', $7::text, $8::bigint, $9::bigint, $10::bigint, $5::bigint,
				       'open', $11::text, $2::text
				WHERE EXISTS (SELECT 1 FROM taken) RETURNING id)
			SELECT chk.active, chk.existing, (SELECT id FROM ins) FROM chk`,
			cmd.UserID, cmd.Key, cash, heldAcct, reserved, c.id, cmd.Kind, price, o.Qty, reserve, tif,
		).Scan(&active, &existing, &newID)
	} else {
		err = e.row(ctx, tx, `
			WITH chk AS (
				SELECT (SELECT status = 'active' FROM users WHERE id = $1::bigint) AS active,
				       (SELECT id FROM orders WHERE user_id = $1::bigint AND idempotency_key = $2::text) AS existing),
			res AS (
				UPDATE positions SET reserved_qty = reserved_qty + $3::bigint
				WHERE user_id = $1::bigint AND company_id = $4::bigint AND qty - reserved_qty >= $3::bigint
				  AND (SELECT active AND existing IS NULL FROM chk) RETURNING user_id),
			ins AS (
				INSERT INTO orders (user_id, company_id, side, kind, price, qty, reserved_amount,
				                    status, time_in_force, idempotency_key)
				SELECT $1::bigint, $4::bigint, 'sell', $5::text, $6::bigint, $3::bigint, 0, 'open', $7::text, $2::text
				WHERE EXISTS (SELECT 1 FROM res) RETURNING id)
			SELECT chk.active, chk.existing, (SELECT id FROM ins) FROM chk`,
			cmd.UserID, cmd.Key, o.Qty, c.id, cmd.Kind, price, tif,
		).Scan(&active, &existing, &newID)
	}
	if err != nil {
		return nil, err
	}
	switch {
	case !active.Bool:
		return []Event{reject(cmd, "unknown_user")}, nil
	case existing.Valid:
		return nil, nil // already processed: do nothing
	case !newID.Valid && o.Side == book.Buy:
		return []Event{reject(cmd, "insufficient_funds")}, nil
	case !newID.Valid:
		return []Event{reject(cmd, "insufficient_shares")}, nil
	}
	orderID := newID.Int64
	o.ID = uint64(orderID)

	// From here the book changes: a later failure must rebuild it.
	touched[c] = true
	res, err := c.book.Submit(o)
	if err != nil {
		return nil, fmt.Errorf("submit after validate: %w", err)
	}
	return e.settle(ctx, tx, c, cmd, o, orderID, reserved, res)
}

func (e *Engine) settle(ctx context.Context, tx *sql.Tx, c *company, cmd Command, o book.Order,
	orderID, reserved int64, res book.Result) ([]Event, error) {

	commission, err := e.systemAccount(ctx, tx, "sys_commission")
	if err != nil {
		return nil, err
	}
	initial := reserved // what the order held when it was stored
	var events []Event
	var filled int64
	for _, t := range res.Trades {
		filled += t.Qty
		buyer, seller := int64(t.BuyUserID), int64(t.SellUserID)
		buyOrder, sellOrder := int64(t.BuyOrderID), int64(t.SellOrderID)
		value := t.Price * t.Qty
		fee := e.fee(value)
		if o.Side == book.Buy {
			reserved -= value + fee // paid out of what this order holds
		}

		restingOrder := buyOrder
		if t.TakerSide == book.Buy {
			restingOrder = sellOrder
		}
		buyerHeld, err := e.account(ctx, tx, buyer, "reserved")
		if err != nil {
			return nil, err
		}
		sellerCash, err := e.account(ctx, tx, seller, "cash")
		if err != nil {
			return nil, err
		}

		// The whole trade in one statement: the trade row; cash from the
		// buyer's held money to the seller with commission from each side;
		// shares out of the seller's reserved position into the buyer's; the
		// resting order's progress; the candle and the day's volume.
		var restStatus, restSide string
		var restFilled, restUser, restHeld, sold, balances int64
		err = e.row(ctx, tx, `
			WITH tr AS (
				INSERT INTO trades (company_id, buy_order_id, sell_order_id, buy_user_id, sell_user_id,
				                    price, qty, taker_side)
				VALUES ($1::bigint, $2::bigint, $3::bigint, $4::bigint, $5::bigint, $6::bigint, $7::bigint, $8::text)
				RETURNING id),
			bal AS (
				UPDATE ledger_accounts
				SET balance = balance + CASE id WHEN $9::bigint THEN -($12::bigint + $13::bigint)
				                                WHEN $10::bigint THEN $12::bigint - $13::bigint
				                                ELSE 2 * $13::bigint END
				WHERE id IN ($9::bigint, $10::bigint, $11::bigint) RETURNING id),
			ent AS (
				INSERT INTO ledger_entries (debit_account_id, credit_account_id, amount, ref_type, ref_id)
				SELECT x.d, x.c, x.amount, x.kind, tr.id
				FROM tr, (VALUES ($9::bigint, $10::bigint, $12::bigint, 'trade'),
				                 ($9::bigint, $11::bigint, $13::bigint, 'commission'),
				                 ($10::bigint, $11::bigint, $13::bigint, 'commission')) AS x (d, c, amount, kind)
				WHERE x.amount > 0),
			ps AS (
				UPDATE positions
				SET cost_total = cost_total - (cost_total * $7::bigint / qty),
				    qty = qty - $7::bigint, reserved_qty = reserved_qty - $7::bigint
				WHERE user_id = $5::bigint AND company_id = $1::bigint AND reserved_qty >= $7::bigint
				RETURNING user_id),
			pb AS (
				INSERT INTO positions (user_id, company_id, qty, cost_total)
				VALUES ($4::bigint, $1::bigint, $7::bigint, $12::bigint)
				ON CONFLICT (user_id, company_id)
				DO UPDATE SET qty = positions.qty + EXCLUDED.qty,
				              cost_total = positions.cost_total + EXCLUDED.cost_total),
			cd AS (
				INSERT INTO candles (company_id, interval, bucket, open, high, low, close, volume)
				VALUES ($1::bigint, '1m', date_trunc('minute', now()), $6::bigint, $6::bigint, $6::bigint, $6::bigint, $7::bigint)
				ON CONFLICT (company_id, interval, bucket)
				DO UPDATE SET high = GREATEST(candles.high, EXCLUDED.high),
				              low = LEAST(candles.low, EXCLUDED.low),
				              close = EXCLUDED.close,
				              volume = candles.volume + EXCLUDED.volume),
			ds AS (
				UPDATE daily_stats SET volume = volume + $7::bigint
				WHERE company_id = $1::bigint AND trade_date = $15::date),
			o AS (SELECT reserved_amount FROM orders WHERE id = $14::bigint FOR UPDATE),
			ro AS (
				UPDATE orders
				SET filled_qty = orders.filled_qty + $7::bigint,
				    status = CASE WHEN orders.filled_qty + $7::bigint >= orders.qty THEN 'filled' ELSE 'partial' END,
				    -- a resting buy pays this trade out of what it holds; once
				    -- filled, whatever is left is returned below
				    reserved_amount = CASE WHEN orders.side <> 'buy' THEN orders.reserved_amount
				                           WHEN orders.filled_qty + $7::bigint >= orders.qty THEN 0
				                           ELSE orders.reserved_amount - ($12::bigint + $13::bigint) END,
				    updated_at = now()
				FROM o WHERE orders.id = $14::bigint
				RETURNING orders.status, orders.filled_qty, orders.user_id, orders.side, o.reserved_amount AS held)
			SELECT ro.status, ro.filled_qty, ro.user_id, ro.side, ro.held,
			       (SELECT count(*) FROM ps), (SELECT count(*) FROM bal)
			FROM ro`,
			c.id, buyOrder, sellOrder, buyer, seller, t.Price, t.Qty, sideName(t.TakerSide),
			buyerHeld, sellerCash, commission, value, fee, restingOrder, e.day,
		).Scan(&restStatus, &restFilled, &restUser, &restSide, &restHeld, &sold, &balances)
		if err != nil {
			return nil, err
		}
		if sold != 1 {
			return nil, fmt.Errorf("seller %d has no reserved shares for trade", seller)
		}
		if balances != 3 {
			return nil, fmt.Errorf("trade money between accounts %d and %d did not apply", buyerHeld, sellerCash)
		}
		// A resting buy that is now filled gets back anything still held for it.
		if restStatus == "filled" && restSide == "buy" {
			left := restHeld - (value + fee)
			if err := e.transfer(ctx, tx, restUser, "reserved", restUser, "cash", left, "reserve_release", restingOrder); err != nil {
				return nil, err
			}
		}

		events = append(events,
			Event{Type: "trade", Symbol: c.symbol, Price: t.Price, Qty: t.Qty, TakerSide: sideName(t.TakerSide)},
			Event{Type: "order", Symbol: c.symbol, UserID: restUser, OrderID: restingOrder,
				Status: restStatus, FilledQty: restFilled})
	}

	status := "open"
	switch {
	case filled == o.Qty:
		status = "filled"
	case res.Rested == 0:
		status = "cancelled"
	case filled > 0:
		status = "partial"
	}
	// A market order whose remainder rests carries the price it rests at.
	var restPrice sql.NullInt64
	if res.Rested > 0 {
		restPrice = sql.NullInt64{Int64: res.RestPrice, Valid: true}
	}
	// What the order keeps held from here on: a buy that stays in the book
	// keeps only what its remainder can still cost; anything else keeps nothing.
	ended := status == "filled" || status == "cancelled"
	var keep int64
	if o.Side == book.Buy && res.Rested > 0 {
		keep = min(reserved, res.Rested*res.RestPrice+e.fee(res.Rested*res.RestPrice))
	}
	// A limit order that simply rests is already stored exactly as it is.
	if status != "open" || o.Kind != book.Limit || keep != initial {
		if _, err := e.exec(ctx, tx, `
			UPDATE orders SET filled_qty = $1, status = $2, price = COALESCE($3, price),
			                  reserved_amount = $4, updated_at = now()
			WHERE id = $5`, filled, status, restPrice, keep, orderID); err != nil {
			return nil, err
		}
	}
	// Cash the order no longer needs comes back at once: what better prices
	// saved, and everything left when the order has ended.
	if o.Side == book.Buy && reserved > keep {
		ref := "reserve_refund"
		if ended {
			ref = "reserve_release"
		}
		if err := e.transfer(ctx, tx, cmd.UserID, "reserved", cmd.UserID, "cash", reserved-keep, ref, orderID); err != nil {
			return nil, err
		}
	}
	if o.Side == book.Sell && ended && res.Cancelled > 0 {
		if _, err := e.exec(ctx, tx, `
			UPDATE positions SET reserved_qty = reserved_qty - $1 WHERE user_id = $2 AND company_id = $3`,
			res.Cancelled, cmd.UserID, c.id); err != nil {
			return nil, err
		}
	}
	ev := Event{Type: "order", Symbol: c.symbol, UserID: cmd.UserID, OrderID: orderID, Key: cmd.Key,
		Status: status, FilledQty: filled, Price: res.RestPrice}
	if res.SelfTradeBlocked {
		ev.Reason = "self_trade_blocked"
	}
	return append(events, ev), nil
}

func (e *Engine) processCancel(ctx context.Context, tx *sql.Tx, cmd Command, touched map[*company]bool) ([]Event, error) {
	// Mark the order cancelled and take back what it holds in one statement.
	// It only applies to the owner's order while it is still open.
	user := cmd.UserID
	var companyID, filled, held int64
	var side string
	err := e.row(ctx, tx, `
		WITH o AS (
			SELECT id, reserved_amount FROM orders
			WHERE id = $1 AND user_id = $2 AND status IN ('open', 'partial') FOR UPDATE)
		UPDATE orders SET status = 'cancelled', reserved_amount = 0, updated_at = now()
		FROM o WHERE orders.id = o.id
		RETURNING orders.company_id, orders.side, orders.filled_qty, o.reserved_amount`,
		cmd.OrderID, cmd.UserID).Scan(&companyID, &side, &filled, &held)
	if errors.Is(err, sql.ErrNoRows) {
		var owner int64
		err := e.row(ctx, tx, `SELECT user_id FROM orders WHERE id = $1`, cmd.OrderID).Scan(&owner)
		if errors.Is(err, sql.ErrNoRows) || (err == nil && owner != cmd.UserID) {
			return []Event{reject(cmd, "unknown_order")}, nil
		}
		if err != nil {
			return nil, err
		}
		return []Event{reject(cmd, "not_open")}, nil
	}
	if err != nil {
		return nil, err
	}
	c := e.byID[companyID]
	if c == nil {
		return nil, fmt.Errorf("order %d belongs to a company the engine has not loaded", cmd.OrderID)
	}
	touched[c] = true
	released, err := c.book.Cancel(uint64(cmd.OrderID))
	if err != nil {
		// The database says open but the book does not have it. Failing the
		// batch rebuilds the book from the database before the retry.
		return nil, fmt.Errorf("order %d is open in the database but not in the book", cmd.OrderID)
	}
	if side == "buy" {
		err = e.transfer(ctx, tx, user, "reserved", user, "cash", held, "reserve_release", cmd.OrderID)
	} else if released > 0 {
		_, err = e.exec(ctx, tx, `
			UPDATE positions SET reserved_qty = reserved_qty - $1 WHERE user_id = $2 AND company_id = $3`,
			released, user, c.id)
	}
	if err != nil {
		return nil, err
	}
	return []Event{{Type: "order", Symbol: c.symbol, UserID: user, OrderID: cmd.OrderID,
		Status: "cancelled", FilledQty: filled}}, nil
}

// release gives back what an order that has ended was still holding: for a
// buy, all cash left held for it; for a sell, the unsold shares.
func (e *Engine) release(ctx context.Context, tx *sql.Tx, user, companyID int64, side string,
	unsoldQty, orderID int64) error {
	if side == "buy" {
		var held int64
		if err := e.row(ctx, tx, `
			WITH o AS (SELECT reserved_amount FROM orders WHERE id = $1 FOR UPDATE)
			UPDATE orders SET reserved_amount = 0 FROM o WHERE id = $1 RETURNING o.reserved_amount`,
			orderID).Scan(&held); err != nil {
			return err
		}
		if held <= 0 {
			return nil
		}
		return e.transfer(ctx, tx, user, "reserved", user, "cash", held, "reserve_release", orderID)
	}
	if unsoldQty <= 0 {
		return nil
	}
	_, err := e.exec(ctx, tx, `
		UPDATE positions SET reserved_qty = reserved_qty - $1 WHERE user_id = $2 AND company_id = $3`,
		unsoldQty, user, companyID)
	return err
}

func (e *Engine) systemAccount(ctx context.Context, tx *sql.Tx, kind string) (int64, error) {
	key := acctKey{0, kind}
	if id, ok := e.accts[key]; ok {
		return id, nil
	}
	var id int64
	err := e.row(ctx, tx,
		`SELECT id FROM ledger_accounts WHERE user_id IS NULL AND kind = $1`, kind).Scan(&id)
	if err == nil {
		e.accts[key] = id
	}
	return id, err
}

// account returns the id of a user's ledger account, creating it on first use.
func (e *Engine) account(ctx context.Context, tx *sql.Tx, user int64, kind string) (int64, error) {
	key := acctKey{user, kind}
	if id, ok := e.accts[key]; ok {
		return id, nil
	}
	var id int64
	err := e.row(ctx, tx, `
		WITH ins AS (
			INSERT INTO ledger_accounts (user_id, kind) VALUES ($1, $2)
			ON CONFLICT (user_id, kind) DO NOTHING RETURNING id)
		SELECT id FROM ins
		UNION ALL
		SELECT id FROM ledger_accounts WHERE user_id = $1 AND kind = $2
		LIMIT 1`, user, kind).Scan(&id)
	if err != nil {
		return 0, err
	}
	e.accts[key] = id
	return id, nil
}

// transfer moves amount out of the debit account and into the credit account
// and records the ledger entry.
func (e *Engine) transfer(ctx context.Context, tx *sql.Tx, fromUser int64, fromKind string,
	toUser int64, toKind string, amount int64, refType string, refID int64) error {
	if amount <= 0 {
		return nil
	}
	from, err := e.account(ctx, tx, fromUser, fromKind)
	if err != nil {
		return err
	}
	to, err := e.account(ctx, tx, toUser, toKind)
	if err != nil {
		return err
	}
	return e.move(ctx, tx, from, to, amount, refType, refID)
}

// moveMoneySQL changes both balances and writes the ledger entry in one statement.
const moveMoneySQL = `
		WITH moved AS (
			UPDATE ledger_accounts
			SET balance = balance + CASE WHEN id = $1::bigint THEN -$3::bigint ELSE $3::bigint END
			WHERE id IN ($1::bigint, $2::bigint) RETURNING id)
		INSERT INTO ledger_entries (debit_account_id, credit_account_id, amount, ref_type, ref_id)
		SELECT $1::bigint, $2::bigint, $3::bigint, $4, $5::bigint WHERE (SELECT count(*) FROM moved) = 2`

func moveMoney(ctx context.Context, tx *sql.Tx, from, to, amount int64, refType string, refID int64) error {
	res, err := tx.ExecContext(ctx, moveMoneySQL, from, to, amount, refType, refID)
	return moved(res, err, from, to)
}

// move is moveMoney through the engine's prepared statements.
func (e *Engine) move(ctx context.Context, tx *sql.Tx, from, to, amount int64, refType string, refID int64) error {
	res, err := e.exec(ctx, tx, moveMoneySQL, from, to, amount, refType, refID)
	return moved(res, err, from, to)
}

func moved(res sql.Result, err error, from, to int64) error {
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n != 1 {
		return fmt.Errorf("money move between accounts %d and %d did not apply", from, to)
	}
	return nil
}

// stmt returns the statement prepared once for this query text, bound to tx.
// Preparing lets PostgreSQL plan each statement once instead of on every call.
func (e *Engine) stmt(ctx context.Context, tx *sql.Tx, query string) (*sql.Stmt, error) {
	st, ok := e.stmts[query]
	if !ok {
		var err error
		if st, err = e.db.PrepareContext(ctx, query); err != nil {
			return nil, err
		}
		e.stmts[query] = st
	}
	return tx.StmtContext(ctx, st), nil
}

func (e *Engine) row(ctx context.Context, tx *sql.Tx, query string, args ...any) *sql.Row {
	st, err := e.stmt(ctx, tx, query)
	if err != nil {
		return tx.QueryRowContext(ctx, query, args...) // reports the same error when scanned
	}
	return st.QueryRowContext(ctx, args...)
}

func (e *Engine) exec(ctx context.Context, tx *sql.Tx, query string, args ...any) (sql.Result, error) {
	st, err := e.stmt(ctx, tx, query)
	if err != nil {
		return nil, err
	}
	return st.ExecContext(ctx, args...)
}

// reserveCash moves amount from the user's cash to their reserved account.
// It returns false, without changing anything, if cash is insufficient.
func (e *Engine) reserveCash(ctx context.Context, tx *sql.Tx, user, amount int64) (bool, error) {
	cash, err := e.account(ctx, tx, user, "cash")
	if err != nil {
		return false, err
	}
	reserved, err := e.account(ctx, tx, user, "reserved")
	if err != nil {
		return false, err
	}
	// Take the cash only if there is enough, add it to the held account and
	// write the ledger entry, all in one statement.
	res, err := e.exec(ctx, tx, `
		WITH taken AS (
			UPDATE ledger_accounts SET balance = balance - $3::bigint
			WHERE id = $1::bigint AND balance >= $3::bigint RETURNING id),
		held AS (
			UPDATE ledger_accounts SET balance = balance + $3::bigint
			WHERE id = $2::bigint AND EXISTS (SELECT 1 FROM taken) RETURNING id)
		INSERT INTO ledger_entries (debit_account_id, credit_account_id, amount, ref_type, ref_id)
		SELECT $1::bigint, $2::bigint, $3::bigint, 'order_reserve', 0
		WHERE EXISTS (SELECT 1 FROM taken) AND EXISTS (SELECT 1 FROM held)`,
		cash, reserved, amount)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n == 1, nil
}

func (e *Engine) bookEvent(c *company) Event {
	bids, asks := c.book.Depth(e.DepthSize)
	return Event{Type: "book", Symbol: c.symbol, Last: c.book.LastPrice(), Bids: bids, Asks: asks}
}

// Snapshot returns the current depth and last price for a symbol.
func (e *Engine) Snapshot(symbol string) (Event, bool) {
	c := e.bySy[symbol]
	if c == nil || c.book == nil {
		return Event{}, false
	}
	return e.bookEvent(c), true
}

// Symbols lists the loaded symbols.
func (e *Engine) Symbols() []string {
	out := make([]string, 0, len(e.bySy))
	for s := range e.bySy {
		out = append(out, s)
	}
	return out
}
