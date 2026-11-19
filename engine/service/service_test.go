package service

import (
	"context"
	"database/sql"
	"errors"
	"os"
	"testing"
	"time"

	_ "github.com/lib/pq"
)

// These tests need a real PostgreSQL. Set TEST_DATABASE_URL to an empty
// database the tests may wipe, e.g.
//
//	TEST_DATABASE_URL=postgres://tadawul:tadawul_dev@localhost:5432/tadawul_test?sslmode=disable
func testDB(t *testing.T) *sql.DB {
	t.Helper()
	url := os.Getenv("TEST_DATABASE_URL")
	if url == "" {
		t.Skip("TEST_DATABASE_URL not set")
	}
	db, err := sql.Open("postgres", url)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { db.Close() })
	schema, err := os.ReadFile("../../db/schema.sql")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`DROP SCHEMA public CASCADE; CREATE SCHEMA public;`); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(string(schema)); err != nil {
		t.Fatalf("schema: %v", err)
	}
	return db
}

type fixture struct {
	t      *testing.T
	db     *sql.DB
	e      *Engine
	buyer  int64
	seller int64
	keySeq int
}

const startCash = 10_000_000 // 100,000 SAR in halalas

func setup(t *testing.T) *fixture {
	db := testDB(t)
	f := &fixture{t: t, db: db}
	f.exec(`UPDATE settings SET value = 'false' WHERE key = 'market_hours_enforced'`)
	f.exec(`INSERT INTO companies (symbol, name, shares_outstanding, tick_size, ref_price)
	        VALUES ('2222', 'Test Co', 1000000, 1, 2700)`)
	f.buyer = f.user("buyer")
	f.seller = f.user("seller")
	f.grant(f.buyer, startCash)
	f.exec(`INSERT INTO positions (user_id, company_id, qty, cost_total)
	        VALUES ($1, 1, 1000, 2500000)`, f.seller)
	f.e = f.newEngine()
	return f
}

func (f *fixture) newEngine() *Engine {
	f.t.Helper()
	e := New(f.db)
	if err := e.Load(context.Background()); err != nil {
		f.t.Fatalf("load: %v", err)
	}
	return e
}

func (f *fixture) exec(q string, args ...any) {
	f.t.Helper()
	if _, err := f.db.Exec(q, args...); err != nil {
		f.t.Fatalf("%s: %v", q, err)
	}
}

func (f *fixture) int(q string, args ...any) int64 {
	f.t.Helper()
	var n sql.NullInt64
	if err := f.db.QueryRow(q, args...).Scan(&n); err != nil {
		f.t.Fatalf("%s: %v", q, err)
	}
	return n.Int64
}

func (f *fixture) user(alias string) int64 {
	return f.int(`INSERT INTO users (name, alias, mobile, password_hash)
	              VALUES ($1, $1, $1, 'x') RETURNING id`, alias)
}

func (f *fixture) grant(user, amount int64) {
	f.t.Helper()
	tx, err := f.db.Begin()
	if err != nil {
		f.t.Fatal(err)
	}
	f.exec(`INSERT INTO ledger_accounts (user_id, kind) VALUES ($1, 'cash') ON CONFLICT DO NOTHING`, user)
	cash := f.int(`SELECT id FROM ledger_accounts WHERE user_id = $1 AND kind = 'cash'`, user)
	treasury := f.int(`SELECT id FROM ledger_accounts WHERE kind = 'sys_treasury'`)
	if err := moveMoney(context.Background(), tx, treasury, cash, amount, "grant", user); err != nil {
		f.t.Fatal(err)
	}
	if err := tx.Commit(); err != nil {
		f.t.Fatal(err)
	}
}

func (f *fixture) balance(user int64, kind string) int64 {
	return f.int(`SELECT COALESCE((SELECT balance FROM ledger_accounts
	              WHERE user_id = $1 AND kind = $2), 0)`, user, kind)
}

func (f *fixture) send(c Command) []Event {
	f.t.Helper()
	if c.Type == "" {
		c.Type = "new"
	}
	if c.Type == "new" {
		if c.Symbol == "" {
			c.Symbol = "2222"
		}
		if c.Key == "" {
			f.keySeq++
			c.Key = "k" + time.Now().Format("150405.000000") + string(rune('a'+f.keySeq%26)) + string(rune('a'+f.keySeq/26%26))
		}
	}
	ev, err := f.e.Process(context.Background(), c)
	if err != nil {
		f.t.Fatalf("process %+v: %v", c, err)
	}
	return ev
}

// orderEvent returns the last "order" event for the given user.
func orderEvent(t *testing.T, events []Event, user int64) Event {
	t.Helper()
	for i := len(events) - 1; i >= 0; i-- {
		if events[i].Type == "order" && events[i].UserID == user {
			return events[i]
		}
	}
	t.Fatalf("no order event for user %d in %+v", user, events)
	return Event{}
}

// checkLedger verifies the double-entry invariants: money is only moved,
// never created, and every cached balance equals the sum of its entries.
func (f *fixture) checkLedger() {
	f.t.Helper()
	if total := f.int(`SELECT sum(balance) FROM ledger_accounts`); total != 0 {
		f.t.Fatalf("ledger does not sum to zero: %d", total)
	}
	bad := f.int(`
		SELECT count(*) FROM ledger_accounts a
		WHERE a.balance <>
		      COALESCE((SELECT sum(amount) FROM ledger_entries WHERE credit_account_id = a.id), 0)
		    - COALESCE((SELECT sum(amount) FROM ledger_entries WHERE debit_account_id = a.id), 0)`)
	if bad != 0 {
		f.t.Fatalf("%d accounts disagree with their ledger entries", bad)
	}
	if n := f.int(`SELECT count(*) FROM positions WHERE reserved_qty > qty OR qty < 0`); n != 0 {
		f.t.Fatalf("%d invalid positions", n)
	}
}

func TestLimitMatchSettlesCashAndShares(t *testing.T) {
	f := setup(t)

	ev := f.send(Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2700, Qty: 100})
	sellOrder := orderEvent(t, ev, f.seller)
	if sellOrder.Status != "open" {
		t.Fatalf("sell order: %+v", sellOrder)
	}

	ev = f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2750, Qty: 60})
	if got := orderEvent(t, ev, f.buyer); got.Status != "filled" || got.FilledQty != 60 {
		t.Fatalf("buy order: %+v", got)
	}
	if got := orderEvent(t, ev, f.seller); got.Status != "partial" || got.FilledQty != 60 {
		t.Fatalf("resting order: %+v", got)
	}

	// Trade is at the resting price 2700: 60 * 2700 = 162,000.
	if got := f.balance(f.buyer, "cash"); got != startCash-162000 {
		t.Errorf("buyer cash = %d", got)
	}
	if got := f.balance(f.buyer, "reserved"); got != 0 {
		t.Errorf("buyer reserved = %d, want 0 (the 50-halala difference must be refunded)", got)
	}
	if got := f.balance(f.seller, "cash"); got != 162000 {
		t.Errorf("seller cash = %d", got)
	}
	if q := f.int(`SELECT qty FROM positions WHERE user_id = $1`, f.buyer); q != 60 {
		t.Errorf("buyer shares = %d", q)
	}
	if c := f.int(`SELECT cost_total FROM positions WHERE user_id = $1`, f.buyer); c != 162000 {
		t.Errorf("buyer cost = %d", c)
	}
	var qty, reserved, cost int64
	if err := f.db.QueryRow(`SELECT qty, reserved_qty, cost_total FROM positions WHERE user_id = $1`,
		f.seller).Scan(&qty, &reserved, &cost); err != nil {
		t.Fatal(err)
	}
	if qty != 940 || reserved != 40 || cost != 2350000 {
		t.Errorf("seller position qty=%d reserved=%d cost=%d", qty, reserved, cost)
	}
	if n := f.int(`SELECT count(*) FROM trades`); n != 1 {
		t.Errorf("trades = %d", n)
	}
	if v := f.int(`SELECT volume FROM candles WHERE interval = '1m'`); v != 60 {
		t.Errorf("candle volume = %d", v)
	}
	f.checkLedger()

	// A restart must rebuild the same book from the database.
	f.e = f.newEngine()
	snap, _ := f.e.Snapshot("2222")
	if len(snap.Asks) != 1 || snap.Asks[0].Price != 2700 || snap.Asks[0].Qty != 40 || len(snap.Bids) != 0 {
		t.Fatalf("book after restart: %+v", snap)
	}
	if snap.Last != 2700 {
		t.Errorf("last after restart = %d", snap.Last)
	}

	// Cancelling releases the remaining reserved shares.
	ev = f.send(Command{Type: "cancel", UserID: f.seller, OrderID: sellOrder.OrderID})
	if got := orderEvent(t, ev, f.seller); got.Status != "cancelled" {
		t.Fatalf("cancel: %+v", got)
	}
	if r := f.int(`SELECT reserved_qty FROM positions WHERE user_id = $1`, f.seller); r != 0 {
		t.Errorf("reserved shares after cancel = %d", r)
	}
	ev = f.send(Command{Type: "cancel", UserID: f.seller, OrderID: sellOrder.OrderID})
	if got := orderEvent(t, ev, f.seller); got.Reason != "not_open" {
		t.Errorf("second cancel: %+v", got)
	}
	f.checkLedger()
}

func TestRestingBuyReservesAndCancelRefunds(t *testing.T) {
	f := setup(t)
	ev := f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2600, Qty: 100})
	o := orderEvent(t, ev, f.buyer)
	if f.balance(f.buyer, "reserved") != 260000 || f.balance(f.buyer, "cash") != startCash-260000 {
		t.Fatalf("reservation wrong: cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	// Another user cannot cancel it.
	ev = f.send(Command{Type: "cancel", UserID: f.seller, OrderID: o.OrderID})
	if got := orderEvent(t, ev, f.seller); got.Reason != "unknown_order" {
		t.Fatalf("foreign cancel: %+v", got)
	}
	f.send(Command{Type: "cancel", UserID: f.buyer, OrderID: o.OrderID})
	if f.balance(f.buyer, "reserved") != 0 || f.balance(f.buyer, "cash") != startCash {
		t.Fatalf("refund wrong: cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	f.checkLedger()
}

func TestMarketBuyReservesAtHighLimitAndRefunds(t *testing.T) {
	f := setup(t)
	f.send(Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2710, Qty: 10})
	ev := f.send(Command{UserID: f.buyer, Side: "buy", Kind: "market", Qty: 25})
	got := orderEvent(t, ev, f.buyer)
	// Only 10 shares were on offer: 10 fill, 15 are cancelled.
	if got.Status != "cancelled" || got.FilledQty != 10 {
		t.Fatalf("market order: %+v", got)
	}
	if f.balance(f.buyer, "cash") != startCash-27100 || f.balance(f.buyer, "reserved") != 0 {
		t.Fatalf("cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	f.checkLedger()
}

func TestRejections(t *testing.T) {
	f := setup(t)
	cases := []struct {
		name string
		cmd  Command
		want string
	}{
		{"insufficient funds", Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2700, Qty: 1_000_000}, "insufficient_funds"},
		{"insufficient shares", Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2700, Qty: 1001}, "insufficient_shares"},
		{"no shares at all", Command{UserID: f.buyer, Side: "sell", Kind: "limit", Price: 2700, Qty: 1}, "insufficient_shares"},
		{"above high limit", Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2971, Qty: 1}, "outside_daily_limits"},
		{"below low limit", Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2429, Qty: 1}, "outside_daily_limits"},
		{"unknown symbol", Command{UserID: f.buyer, Symbol: "9999", Side: "buy", Kind: "limit", Price: 2700, Qty: 1}, "unknown_symbol"},
		{"unknown user", Command{UserID: 9999, Side: "buy", Kind: "limit", Price: 2700, Qty: 1}, "unknown_user"},
		{"zero qty", Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2700, Qty: 0}, "bad_quantity"},
		{"bad kind", Command{UserID: f.buyer, Side: "buy", Kind: "stop", Price: 2700, Qty: 1}, "bad_kind"},
	}
	for _, c := range cases {
		ev := f.send(c.cmd)
		if got := orderEvent(t, ev, c.cmd.UserID); got.Status != "rejected" || got.Reason != c.want {
			t.Errorf("%s: got %+v, want reason %s", c.name, got, c.want)
		}
	}
	if n := f.int(`SELECT count(*) FROM orders`); n != 0 {
		t.Errorf("rejected orders were stored: %d", n)
	}
	if f.balance(f.buyer, "cash") != startCash || f.balance(f.buyer, "reserved") != 0 {
		t.Errorf("a rejected order moved money")
	}
	f.checkLedger()
}

func TestDuplicateKeyIsAppliedOnce(t *testing.T) {
	f := setup(t)
	cmd := Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2600, Qty: 10, Key: "same-key"}
	f.send(cmd)
	if ev := f.send(cmd); len(ev) != 0 {
		t.Fatalf("duplicate produced events: %+v", ev)
	}
	if n := f.int(`SELECT count(*) FROM orders`); n != 1 {
		t.Fatalf("orders = %d", n)
	}
	if f.balance(f.buyer, "reserved") != 26000 {
		t.Fatalf("reserved = %d, want one reservation", f.balance(f.buyer, "reserved"))
	}
}

func TestMarketHours(t *testing.T) {
	f := setup(t)
	f.e.enforced = true
	riyadh := f.e.tz
	today := time.Now().In(riyadh)
	at := func(h, m int) func() time.Time {
		return func() time.Time { return time.Date(today.Year(), today.Month(), today.Day(), h, m, 0, 0, riyadh) }
	}
	cmd := Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2600, Qty: 1}

	for _, c := range []struct {
		h, m int
		open bool
	}{{9, 59, false}, {10, 0, true}, {21, 59, true}, {22, 0, false}} {
		f.e.Now = at(c.h, c.m)
		ev := f.send(cmd)
		got := orderEvent(t, ev, f.buyer)
		if c.open && got.Status != "open" {
			t.Errorf("%02d:%02d: want accepted, got %+v", c.h, c.m, got)
		}
		if !c.open && got.Reason != "market_closed" {
			t.Errorf("%02d:%02d: want market_closed, got %+v", c.h, c.m, got)
		}
	}
}

func TestNewDayExpiresOrdersAndMovesLimits(t *testing.T) {
	f := setup(t)
	f.send(Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2900, Qty: 50})
	f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2900, Qty: 20}) // last = 2900
	f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2500, Qty: 10}) // rests

	f.e.Now = func() time.Time { return time.Now().Add(48 * time.Hour) }
	// 2900 * 1.10 = 3190 is tradable on the new day; it was not on day one.
	ev := f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 3190, Qty: 1})
	if got := orderEvent(t, ev, f.buyer); got.Status != "open" {
		t.Fatalf("order on new day: %+v", got)
	}
	if n := f.int(`SELECT count(*) FROM orders WHERE status = 'expired'`); n != 2 {
		t.Errorf("expired orders = %d, want 2", n)
	}
	if c := f.int(`SELECT close FROM daily_stats ORDER BY trade_date LIMIT 1`); c != 2900 {
		t.Errorf("first day close = %d", c)
	}
	var prev, low, high int64
	if err := f.db.QueryRow(`SELECT prev_close, low_limit, high_limit FROM daily_stats
		ORDER BY trade_date DESC LIMIT 1`).Scan(&prev, &low, &high); err != nil {
		t.Fatal(err)
	}
	if prev != 2900 || low != 2610 || high != 3190 {
		t.Errorf("new day prev=%d low=%d high=%d", prev, low, high)
	}
	// Expired orders gave back everything: only the new 3190 order holds cash.
	if r := f.balance(f.buyer, "reserved"); r != 3190 {
		t.Errorf("buyer reserved = %d", r)
	}
	if r := f.int(`SELECT reserved_qty FROM positions WHERE user_id = $1`, f.seller); r != 0 {
		t.Errorf("seller reserved shares = %d", r)
	}
	f.checkLedger()
}

func TestBatchCommitsTogether(t *testing.T) {
	f := setup(t)
	ev, err := f.e.ProcessBatch(context.Background(), []Command{
		{Type: "new", UserID: f.seller, Symbol: "2222", Side: "sell", Kind: "limit", Price: 2700, Qty: 100, Key: "a"},
		{Type: "new", UserID: f.buyer, Symbol: "2222", Side: "buy", Kind: "limit", Price: 2700, Qty: 30, Key: "b"},
		{Type: "new", UserID: f.buyer, Symbol: "2222", Side: "buy", Kind: "limit", Price: 2700, Qty: 30, Key: "b"}, // duplicate
		{Type: "new", UserID: f.buyer, Symbol: "2222", Side: "buy", Kind: "limit", Price: 2600, Qty: 10, Key: "c"},
	})
	if err != nil {
		t.Fatal(err)
	}
	// The resting bid (key c) is cancelled in a second batch by its id.
	var bid int64
	books := 0
	for _, e := range ev {
		if e.Key == "c" {
			bid = e.OrderID
		}
		if e.Type == "book" {
			books++
		}
	}
	if books != 1 {
		t.Errorf("want one book event per touched symbol, got %d", books)
	}
	if _, err := f.e.ProcessBatch(context.Background(), []Command{
		{Type: "cancel", UserID: f.buyer, OrderID: bid},
	}); err != nil {
		t.Fatal(err)
	}
	if n := f.int(`SELECT count(*) FROM orders`); n != 3 {
		t.Errorf("orders = %d, want 3 (duplicate ignored)", n)
	}
	if n := f.int(`SELECT count(*) FROM trades`); n != 1 {
		t.Errorf("trades = %d", n)
	}
	if got := f.balance(f.buyer, "cash"); got != startCash-30*2700 {
		t.Errorf("buyer cash = %d", got)
	}
	if got := f.balance(f.buyer, "reserved"); got != 0 {
		t.Errorf("buyer reserved = %d", got)
	}
	snap, _ := f.e.Snapshot("2222")
	if len(snap.Bids) != 0 || len(snap.Asks) != 1 || snap.Asks[0].Qty != 70 {
		t.Errorf("book = %+v", snap)
	}
	f.checkLedger()
}

func TestFailedBatchChangesNothing(t *testing.T) {
	f := setup(t)
	f.send(Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2700, Qty: 100})

	boom := errors.New("injected failure")
	f.e.testHook = func(c Command) error {
		if c.Key == "second" {
			return boom
		}
		return nil
	}
	_, err := f.e.ProcessBatch(context.Background(), []Command{
		{Type: "new", UserID: f.buyer, Symbol: "2222", Side: "buy", Kind: "limit", Price: 2700, Qty: 40, Key: "first"},
		{Type: "new", UserID: f.buyer, Symbol: "2222", Side: "buy", Kind: "limit", Price: 2650, Qty: 5, Key: "second"},
	})
	if !errors.Is(err, boom) {
		t.Fatalf("err = %v", err)
	}
	f.e.testHook = nil

	// Nothing from the failed batch is in the database...
	if n := f.int(`SELECT count(*) FROM trades`); n != 0 {
		t.Errorf("trades = %d", n)
	}
	if n := f.int(`SELECT count(*) FROM orders`); n != 1 {
		t.Errorf("orders = %d", n)
	}
	if f.balance(f.buyer, "cash") != startCash || f.balance(f.buyer, "reserved") != 0 {
		t.Errorf("failed batch moved money")
	}
	// ...and the in-memory book was rebuilt to match: the full 100 is back.
	snap, _ := f.e.Snapshot("2222")
	if len(snap.Asks) != 1 || snap.Asks[0].Qty != 100 || len(snap.Bids) != 0 {
		t.Fatalf("book after failed batch = %+v", snap)
	}
	// The same commands then apply cleanly.
	ev := f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2700, Qty: 40, Key: "first"})
	if got := orderEvent(t, ev, f.buyer); got.Status != "filled" {
		t.Fatalf("retry: %+v", got)
	}
	f.checkLedger()
}
