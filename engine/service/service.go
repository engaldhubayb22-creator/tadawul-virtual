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
	day       string
	refreshed time.Time
	DepthSize int

	testHook func(Command) error // tests only: inject a failure after a command
}

func New(db *sql.DB) *Engine {
	return &Engine{
		db:        db,
		Now:       time.Now,
		byID:      map[int64]*company{},
		bySy:      map[string]*company{},
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
	var added []*company
	for rows.Next() {
		n := &company{}
		if err := rows.Scan(&n.id, &n.symbol, &n.tick, &n.status); err != nil {
			rows.Close()
			return err
		}
		if c := e.byID[n.id]; c != nil {
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
			if _, err := e.db.ExecContext(ctx, `
				INSERT INTO daily_stats (company_id, trade_date, prev_close, low_limit, high_limit)
				SELECT c.id, $1::date, c.ref_price,
				       (((c.ref_price * (10000 - $2::bigint) + 9999) / 10000) + c.tick_size - 1) / c.tick_size * c.tick_size,
				       ((c.ref_price * (10000 + $2::bigint)) / 10000) / c.tick_size * c.tick_size
				FROM companies c WHERE c.id = $3
				ON CONFLICT (company_id, trade_date) DO NOTHING`, e.day, e.limitBps, c.id); err != nil {
				return err
			}
			if err := e.reloadBook(ctx, c); err != nil {
				return err
			}
		}
		e.byID[c.id], e.bySy[c.symbol] = c, c
	}
	e.refreshed = e.Now()
	return nil
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
		SELECT id, user_id, company_id, side, COALESCE(reserve_price, 0), qty - filled_qty
		FROM orders
		WHERE status IN ('open', 'partial') AND time_in_force = 'day'
		  AND (created_at AT TIME ZONE $2)::date < $1::date`, day, e.tz.String())
	if err != nil {
		return err
	}
	type stale struct {
		id, user, company, reserve, remaining int64
		side                                  string
	}
	var old []stale
	for rows.Next() {
		var s stale
		if err := rows.Scan(&s.id, &s.user, &s.company, &s.side, &s.reserve, &s.remaining); err != nil {
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
		if err := e.release(ctx, tx, s.user, s.company, s.side, s.reserve, s.remaining, s.id); err != nil {
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
		       (((p.prev * (10000 - $2::bigint) + 9999) / 10000) + c.tick_size - 1) / c.tick_size * c.tick_size,
		       ((p.prev * (10000 + $2::bigint)) / 10000) / c.tick_size * c.tick_size
		FROM companies c
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
	if err := c.book.Validate(o); err != nil {
		return []Event{reject(cmd, reason(err))}, nil
	}

	var reservePrice int64
	if o.Side == book.Buy {
		reservePrice = o.Price
		if o.Kind == book.Market {
			reservePrice = c.book.HighLimit
		}
		if reservePrice <= 0 {
			return []Event{reject(cmd, "no_reference_price")}, nil
		}
	}

	var active bool
	err := tx.QueryRowContext(ctx,
		`SELECT status = 'active' FROM users WHERE id = $1`, cmd.UserID).Scan(&active)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && !active) {
		return []Event{reject(cmd, "unknown_user")}, nil
	}
	if err != nil {
		return nil, err
	}

	var existing int64
	err = tx.QueryRowContext(ctx,
		`SELECT id FROM orders WHERE user_id = $1 AND idempotency_key = $2`, cmd.UserID, cmd.Key).Scan(&existing)
	if err == nil {
		return nil, nil // already processed: do nothing
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}

	// Reserve cash (buy) or shares (sell) before the order can match.
	if o.Side == book.Buy {
		ok, err := e.reserveCash(ctx, tx, cmd.UserID, reservePrice*o.Qty)
		if err != nil {
			return nil, err
		}
		if !ok {
			return []Event{reject(cmd, "insufficient_funds")}, nil
		}
	} else {
		res, err := tx.ExecContext(ctx, `
			UPDATE positions SET reserved_qty = reserved_qty + $1
			WHERE user_id = $2 AND company_id = $3 AND qty - reserved_qty >= $1`,
			o.Qty, cmd.UserID, c.id)
		if err != nil {
			return nil, err
		}
		if n, _ := res.RowsAffected(); n == 0 {
			return []Event{reject(cmd, "insufficient_shares")}, nil
		}
	}

	var orderID int64
	var price, reserve sql.NullInt64
	if o.Kind == book.Limit {
		price = sql.NullInt64{Int64: o.Price, Valid: true}
	}
	if o.Side == book.Buy {
		reserve = sql.NullInt64{Int64: reservePrice, Valid: true}
	}
	err = tx.QueryRowContext(ctx, `
		INSERT INTO orders (user_id, company_id, side, kind, price, qty, reserve_price, status, idempotency_key)
		VALUES ($1, $2, $3, $4, $5, $6, $7, 'open', $8) RETURNING id`,
		cmd.UserID, c.id, cmd.Side, cmd.Kind, price, o.Qty, reserve, cmd.Key).Scan(&orderID)
	if err != nil {
		return nil, err
	}
	o.ID = uint64(orderID)

	// From here the book changes: a later failure must rebuild it.
	touched[c] = true
	res, err := c.book.Submit(o)
	if err != nil {
		return nil, fmt.Errorf("submit after validate: %w", err)
	}
	return e.settle(ctx, tx, c, cmd, o, orderID, reservePrice, res)
}

func (e *Engine) settle(ctx context.Context, tx *sql.Tx, c *company, cmd Command, o book.Order,
	orderID, reservePrice int64, res book.Result) ([]Event, error) {

	var events []Event
	var filled int64
	for _, t := range res.Trades {
		filled += t.Qty
		buyer, seller := int64(t.BuyUserID), int64(t.SellUserID)
		buyOrder, sellOrder := int64(t.BuyOrderID), int64(t.SellOrderID)

		var tradeID int64
		err := tx.QueryRowContext(ctx, `
			INSERT INTO trades (company_id, buy_order_id, sell_order_id, buy_user_id, sell_user_id,
			                    price, qty, taker_side)
			VALUES ($1, $2, $3, $4, $5, $6, $7, $8) RETURNING id`,
			c.id, buyOrder, sellOrder, buyer, seller, t.Price, t.Qty, sideName(t.TakerSide)).Scan(&tradeID)
		if err != nil {
			return nil, err
		}

		// Cash: the buyer's reservation pays the seller. A resting buy order
		// reserved exactly its own price; an incoming one may have reserved more.
		buyerReserve := t.Price
		restingOrder := buyOrder
		if t.TakerSide == book.Buy {
			buyerReserve = reservePrice
			restingOrder = sellOrder
		}
		if err := e.transfer(ctx, tx, buyer, "reserved", seller, "cash", t.Price*t.Qty, "trade", tradeID); err != nil {
			return nil, err
		}
		if buyerReserve > t.Price {
			if err := e.transfer(ctx, tx, buyer, "reserved", buyer, "cash",
				(buyerReserve-t.Price)*t.Qty, "reserve_refund", buyOrder); err != nil {
				return nil, err
			}
		}

		// Shares: out of the seller's reserved position, into the buyer's.
		r, err := tx.ExecContext(ctx, `
			UPDATE positions
			SET cost_total = cost_total - (cost_total * $1 / qty),
			    qty = qty - $1, reserved_qty = reserved_qty - $1
			WHERE user_id = $2 AND company_id = $3 AND reserved_qty >= $1`,
			t.Qty, seller, c.id)
		if err != nil {
			return nil, err
		}
		if n, _ := r.RowsAffected(); n != 1 {
			return nil, fmt.Errorf("seller %d has no reserved shares for trade", seller)
		}
		if _, err := tx.ExecContext(ctx, `
			INSERT INTO positions (user_id, company_id, qty, cost_total) VALUES ($1, $2, $3, $4)
			ON CONFLICT (user_id, company_id)
			DO UPDATE SET qty = positions.qty + EXCLUDED.qty,
			              cost_total = positions.cost_total + EXCLUDED.cost_total`,
			buyer, c.id, t.Qty, t.Price*t.Qty); err != nil {
			return nil, err
		}

		var restStatus string
		var restFilled, restUser int64
		err = tx.QueryRowContext(ctx, `
			UPDATE orders
			SET filled_qty = filled_qty + $1,
			    status = CASE WHEN filled_qty + $1 >= qty THEN 'filled' ELSE 'partial' END,
			    updated_at = now()
			WHERE id = $2 RETURNING status, filled_qty, user_id`,
			t.Qty, restingOrder).Scan(&restStatus, &restFilled, &restUser)
		if err != nil {
			return nil, err
		}

		if _, err := tx.ExecContext(ctx, `
			INSERT INTO candles (company_id, interval, bucket, open, high, low, close, volume)
			VALUES ($1, '1m', date_trunc('minute', now()), $2, $2, $2, $2, $3)
			ON CONFLICT (company_id, interval, bucket)
			DO UPDATE SET high = GREATEST(candles.high, EXCLUDED.high),
			              low = LEAST(candles.low, EXCLUDED.low),
			              close = EXCLUDED.close,
			              volume = candles.volume + EXCLUDED.volume`,
			c.id, t.Price, t.Qty); err != nil {
			return nil, err
		}
		if _, err := tx.ExecContext(ctx, `
			UPDATE daily_stats SET volume = volume + $1 WHERE company_id = $2 AND trade_date = $3::date`,
			t.Qty, c.id, e.day); err != nil {
			return nil, err
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
	case res.Cancelled > 0:
		status = "cancelled"
	case filled > 0:
		status = "partial"
	}
	if _, err := tx.ExecContext(ctx,
		`UPDATE orders SET filled_qty = $1, status = $2, updated_at = now() WHERE id = $3`,
		filled, status, orderID); err != nil {
		return nil, err
	}
	if res.Cancelled > 0 {
		if err := e.release(ctx, tx, cmd.UserID, c.id, cmd.Side, reservePrice, res.Cancelled, orderID); err != nil {
			return nil, err
		}
	}
	ev := Event{Type: "order", Symbol: c.symbol, UserID: cmd.UserID, OrderID: orderID, Key: cmd.Key,
		Status: status, FilledQty: filled}
	if res.SelfTradeBlocked {
		ev.Reason = "self_trade_blocked"
	}
	return append(events, ev), nil
}

func (e *Engine) processCancel(ctx context.Context, tx *sql.Tx, cmd Command, touched map[*company]bool) ([]Event, error) {
	var user, companyID, reserve int64
	var side, status string
	err := tx.QueryRowContext(ctx, `
		SELECT user_id, company_id, side, COALESCE(reserve_price, 0), status FROM orders WHERE id = $1`,
		cmd.OrderID).Scan(&user, &companyID, &side, &reserve, &status)
	if errors.Is(err, sql.ErrNoRows) || (err == nil && user != cmd.UserID) {
		return []Event{reject(cmd, "unknown_order")}, nil
	}
	if err != nil {
		return nil, err
	}
	if status != "open" && status != "partial" {
		return []Event{reject(cmd, "not_open")}, nil
	}
	c := e.byID[companyID]
	if c == nil {
		return []Event{reject(cmd, "unknown_symbol")}, nil
	}
	touched[c] = true
	released, err := c.book.Cancel(uint64(cmd.OrderID))
	if err != nil {
		// The database says open but the book does not have it. Failing the
		// batch rebuilds the book from the database before the retry.
		return nil, fmt.Errorf("order %d is open in the database but not in the book", cmd.OrderID)
	}
	var filled int64
	if err := tx.QueryRowContext(ctx, `
		UPDATE orders SET status = 'cancelled', updated_at = now() WHERE id = $1 RETURNING filled_qty`,
		cmd.OrderID).Scan(&filled); err != nil {
		return nil, err
	}
	if err := e.release(ctx, tx, user, c.id, side, reserve, released, cmd.OrderID); err != nil {
		return nil, err
	}
	return []Event{{Type: "order", Symbol: c.symbol, UserID: user, OrderID: cmd.OrderID,
		Status: "cancelled", FilledQty: filled}}, nil
}

// release gives back what an unfilled quantity was holding.
func (e *Engine) release(ctx context.Context, tx *sql.Tx, user, companyID int64, side string,
	reservePrice, qty, orderID int64) error {
	if qty <= 0 {
		return nil
	}
	if side == "buy" {
		return e.transfer(ctx, tx, user, "reserved", user, "cash", reservePrice*qty, "reserve_release", orderID)
	}
	_, err := tx.ExecContext(ctx, `
		UPDATE positions SET reserved_qty = reserved_qty - $1 WHERE user_id = $2 AND company_id = $3`,
		qty, user, companyID)
	return err
}

func (e *Engine) account(ctx context.Context, tx *sql.Tx, user int64, kind string) (int64, error) {
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO ledger_accounts (user_id, kind) VALUES ($1, $2)
		ON CONFLICT (user_id, kind) DO NOTHING`, user, kind); err != nil {
		return 0, err
	}
	var id int64
	if err := tx.QueryRowContext(ctx,
		`SELECT id FROM ledger_accounts WHERE user_id = $1 AND kind = $2`, user, kind).Scan(&id); err != nil {
		return 0, err
	}
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
	return moveMoney(ctx, tx, from, to, amount, refType, refID)
}

func moveMoney(ctx context.Context, tx *sql.Tx, from, to, amount int64, refType string, refID int64) error {
	if _, err := tx.ExecContext(ctx,
		`UPDATE ledger_accounts SET balance = balance - $1 WHERE id = $2`, amount, from); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx,
		`UPDATE ledger_accounts SET balance = balance + $1 WHERE id = $2`, amount, to); err != nil {
		return err
	}
	_, err := tx.ExecContext(ctx, `
		INSERT INTO ledger_entries (debit_account_id, credit_account_id, amount, ref_type, ref_id)
		VALUES ($1, $2, $3, $4, $5)`, from, to, amount, refType, refID)
	return err
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
	var balance int64
	if err := tx.QueryRowContext(ctx,
		`SELECT balance FROM ledger_accounts WHERE id = $1 FOR UPDATE`, cash).Scan(&balance); err != nil {
		return false, err
	}
	if balance < amount {
		return false, nil
	}
	return true, moveMoney(ctx, tx, cash, reserved, amount, "order_reserve", 0)
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
