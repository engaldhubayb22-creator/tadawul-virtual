package service

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
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
	f.exec(`UPDATE settings SET value = '0' WHERE key = 'commission_bps'`) // most tests check exact cash
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
	return f.int(`INSERT INTO users (name, alias, mobile, password)
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

func TestMarketBuyHoldsTheCapThenRestsAtLastPrice(t *testing.T) {
	f := setup(t)
	f.send(Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2710, Qty: 10})
	// Best offer 27.10, step 0.02: the order may pay up to 27.20 and holds 25 x 27.20 = 680.00.
	ev := f.send(Command{UserID: f.buyer, Side: "buy", Kind: "market", Qty: 25})
	got := orderEvent(t, ev, f.buyer)
	// Only 10 were on offer: 10 fill at 27.10; the other 15 rest as a limit order at 27.10.
	if got.Status != "partial" || got.FilledQty != 10 || got.Price != 2710 {
		t.Fatalf("market order: %+v", got)
	}
	if f.balance(f.buyer, "reserved") != 15*2710 || f.balance(f.buyer, "cash") != startCash-27100-15*2710 {
		t.Fatalf("cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	if p := f.int(`SELECT price FROM orders WHERE id = $1`, got.OrderID); p != 2710 {
		t.Fatalf("resting price = %d", p)
	}
	f.send(Command{Type: "cancel", UserID: f.buyer, OrderID: got.OrderID})
	if f.balance(f.buyer, "cash") != startCash-27100 || f.balance(f.buyer, "reserved") != 0 {
		t.Fatalf("after cancel: cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	f.checkLedger()
}

// The example from the requirements, with the 15.5 bps commission on.
func TestLimitBuyAtBetterPricesWithCommission(t *testing.T) {
	f := setup(t)
	f.exec(`INSERT INTO companies (symbol, name, shares_outstanding, ref_price) VALUES ('1150', 'Alinma', 1000000, 2800)`)
	f.exec(`INSERT INTO positions (user_id, company_id, qty, cost_total) VALUES ($1, 2, 2000, 5600000)`, f.seller)
	f.e = f.newEngine()
	f.e.feeTenths = 155
	for _, o := range [][2]int64{{3000, 200}, {3010, 300}, {3060, 400}, {3062, 500}} {
		f.send(Command{UserID: f.seller, Symbol: "1150", Side: "sell", Kind: "limit", Price: o[0], Qty: o[1]})
	}
	ev := f.send(Command{UserID: f.buyer, Symbol: "1150", Side: "buy", Kind: "limit", Price: 3060, Qty: 1000})
	got := orderEvent(t, ev, f.buyer)
	if got.Status != "partial" || got.FilledQty != 900 {
		t.Fatalf("order: %+v", got)
	}
	// 200 x 30.00 + 300 x 30.10 + 400 x 30.60 = 27,270.00; commission 9.30 + 13.99 + 18.97 = 42.26.
	const value, fees = 2727000, 930 + 1399 + 1897
	// Still held for the 100 left at 30.60: 3,060.00 plus 4.74 commission.
	const held = 306000 + 474
	if f.balance(f.buyer, "reserved") != held || f.balance(f.buyer, "cash") != startCash-value-fees-held {
		t.Fatalf("buyer cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	if f.balance(f.seller, "cash") != value-fees {
		t.Fatalf("seller cash = %d", f.balance(f.seller, "cash"))
	}
	if got := f.int(`SELECT balance FROM ledger_accounts WHERE kind = 'sys_commission'`); got != 2*fees {
		t.Fatalf("commission account = %d", got)
	}
	var qty, cost int64
	if err := f.db.QueryRow(`SELECT qty, cost_total FROM positions WHERE user_id = $1 AND company_id = 2`,
		f.buyer).Scan(&qty, &cost); err != nil {
		t.Fatal(err)
	}
	if qty != 900 || cost/qty != 3030 {
		t.Fatalf("position %d shares, average %d", qty, cost/qty)
	}
	snap, _ := f.e.Snapshot("1150")
	if len(snap.Bids) != 1 || snap.Bids[0].Price != 3060 || snap.Bids[0].Qty != 100 || snap.Asks[0].Price != 3062 {
		t.Fatalf("book: %+v", snap)
	}
	f.send(Command{Type: "cancel", UserID: f.buyer, OrderID: got.OrderID})
	if f.balance(f.buyer, "reserved") != 0 || f.balance(f.buyer, "cash") != startCash-value-fees {
		t.Fatalf("after cancel: cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	f.checkLedger()
}

func TestFillOrKillAndFillAvailable(t *testing.T) {
	f := setup(t)
	f.send(Command{UserID: f.seller, Side: "sell", Kind: "limit", Price: 2700, Qty: 100})

	ev := f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2700, Qty: 150, TIF: "fok"})
	if got := orderEvent(t, ev, f.buyer); got.Reason != "fok_not_filled" {
		t.Fatalf("fok: %+v", got)
	}
	if f.balance(f.buyer, "cash") != startCash || f.int(`SELECT count(*) FROM trades`) != 0 {
		t.Fatalf("a failed all-or-nothing order must change nothing")
	}
	ev = f.send(Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2700, Qty: 150, TIF: "ioc"})
	if got := orderEvent(t, ev, f.buyer); got.Status != "cancelled" || got.FilledQty != 100 {
		t.Fatalf("ioc: %+v", got)
	}
	if f.balance(f.buyer, "cash") != startCash-270000 || f.balance(f.buyer, "reserved") != 0 {
		t.Fatalf("ioc cash=%d reserved=%d", f.balance(f.buyer, "cash"), f.balance(f.buyer, "reserved"))
	}
	if snap, _ := f.e.Snapshot("2222"); len(snap.Bids) != 0 || len(snap.Asks) != 0 {
		t.Fatalf("ioc left something in the book: %+v", snap)
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
		{"market order with an empty book", Command{UserID: f.buyer, Side: "buy", Kind: "market", Qty: 10}, "no_liquidity"},
		{"price off the 0.02 grid", Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2701, Qty: 1}, "bad_tick"},
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
	f.exec(`UPDATE settings SET value = 'true' WHERE key = 'market_hours_enforced'`)
	riyadh := f.e.tz
	today := time.Now().In(riyadh)
	at := func(h, m int) func() time.Time {
		return func() time.Time { return time.Date(today.Year(), today.Month(), today.Day(), h, m, 0, 0, riyadh) }
	}
	cmd := Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2600, Qty: 1}

	// A day that is not a trading day is closed whatever the hour.
	f.e.Now = at(12, 0)
	other := (int(today.Weekday()) + 1) % 7
	f.exec(`UPDATE settings SET value = $1 WHERE key = 'market_days'`, fmt.Sprintf("[%d]", other))
	f.e.days = map[int]bool{other: true}
	if got := orderEvent(t, f.send(cmd), f.buyer); got.Reason != "market_closed" {
		t.Fatalf("non-trading day: %+v", got)
	}
	f.exec(`UPDATE settings SET value = '[0,1,2,3,4,5,6]' WHERE key = 'market_days'`)
	f.e.days = map[int]bool{0: true, 1: true, 2: true, 3: true, 4: true, 5: true, 6: true}

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

func TestAdminChangesReachTheEngine(t *testing.T) {
	f := setup(t)
	clock := time.Now()
	f.e.Now = func() time.Time { return clock }
	order := Command{UserID: f.buyer, Side: "buy", Kind: "limit", Price: 2600, Qty: 1}

	f.exec(`UPDATE companies SET status = 'halted' WHERE symbol = '2222'`)
	// Within the refresh window the engine still has the old status.
	if got := orderEvent(t, f.send(order), f.buyer); got.Status != "open" {
		t.Fatalf("before refresh: %+v", got)
	}
	clock = clock.Add(refreshEvery + time.Second)
	if got := orderEvent(t, f.send(order), f.buyer); got.Reason != "halted" {
		t.Fatalf("halt not applied: %+v", got)
	}

	f.exec(`UPDATE companies SET status = 'trading' WHERE symbol = '2222'`)
	clock = clock.Add(refreshEvery + time.Second)
	if got := orderEvent(t, f.send(order), f.buyer); got.Status != "open" {
		t.Fatalf("resume not applied: %+v", got)
	}
}

func TestCompanyListedAfterStartupBecomesTradable(t *testing.T) {
	f := setup(t)
	clock := time.Now()
	f.e.Now = func() time.Time { return clock }

	// Announced as pending, the way an IPO creates it, with tick 5 and price 40.00.
	f.exec(`INSERT INTO companies (symbol, name, shares_outstanding, tick_size, ref_price, status)
	        VALUES ('9001', 'New Co', 1000000, 5, 4000, 'pending')`)
	order := Command{UserID: f.buyer, Symbol: "9001", Side: "buy", Kind: "limit", Price: 4000, Qty: 10}
	if got := orderEvent(t, f.send(order), f.buyer); got.Reason != "unknown_symbol" {
		t.Fatalf("before refresh: %+v", got)
	}
	clock = clock.Add(refreshEvery + time.Second)
	if err := f.e.Tick(context.Background()); err != nil {
		t.Fatal(err)
	}
	if got := orderEvent(t, f.send(order), f.buyer); got.Reason != "halted" {
		t.Fatalf("pending company must not trade: %+v", got)
	}

	f.exec(`UPDATE companies SET status = 'trading' WHERE symbol = '9001'`)
	clock = clock.Add(refreshEvery + time.Second)
	if got := orderEvent(t, f.send(order), f.buyer); got.Status != "open" {
		t.Fatalf("listed company: %+v", got)
	}
	// Limits come from the listing price on its tick grid: 36.00 .. 44.00.
	above := Command{UserID: f.buyer, Symbol: "9001", Side: "buy", Kind: "limit", Price: 4405, Qty: 1}
	if got := orderEvent(t, f.send(above), f.buyer); got.Reason != "outside_daily_limits" {
		t.Fatalf("limits not set for new company: %+v", got)
	}
	f.checkLedger()
}

func TestNewListingTradesWithWiderLimitsForThreeDays(t *testing.T) {
	f := setup(t)
	clock := time.Now()
	f.e.Now = func() time.Time { return clock }
	f.exec(`INSERT INTO companies (symbol, name, shares_outstanding, ref_price, status)
	        VALUES ('9002', 'IPO Co', 1000000, 4000, 'pending')`)
	clock = clock.Add(refreshEvery + time.Second)
	if err := f.e.Tick(context.Background()); err != nil {
		t.Fatal(err)
	}
	// Listing day: the API marks it listed and trading.
	f.exec(`UPDATE companies SET status = 'trading', listed_at = current_date WHERE symbol = '9002'`)
	clock = clock.Add(refreshEvery + time.Second)
	buy := func(price int64) Event {
		return orderEvent(t, f.send(Command{UserID: f.buyer, Symbol: "9002", Side: "buy", Kind: "limit", Price: price, Qty: 1}), f.buyer)
	}
	// 40.00 +/- 30% = 28.00 .. 52.00.
	if got := buy(5200); got.Status != "open" {
		t.Fatalf("52.00 on listing day: %+v", got)
	}
	if got := buy(5205); got.Reason != "outside_daily_limits" {
		t.Fatalf("52.05 on listing day: %+v", got)
	}
	// Day 4 after listing: back to 10% (36.00 .. 44.00 with no trades in between).
	for day := 0; day < 3; day++ { // the engine opens every day as it comes
		clock = clock.Add(24 * time.Hour)
		if err := f.e.Tick(context.Background()); err != nil {
			t.Fatal(err)
		}
	}
	if got := buy(4400); got.Status != "open" {
		t.Fatalf("44.00 on day four: %+v", got)
	}
	if got := buy(4600); got.Reason != "outside_daily_limits" {
		t.Fatalf("46.00 on day four: %+v", got)
	}
}
