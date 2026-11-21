package service

import (
	"context"
	"fmt"
	"os"
	"testing"
	"time"
)

// TestThroughput measures commands per second for the market maker's typical
// load: resting quotes placed and cancelled, plus trades. Run it with BENCH=1.
func TestThroughput(t *testing.T) {
	if os.Getenv("BENCH") == "" {
		t.Skip("set BENCH=1 to run")
	}
	f := setup(t)
	f.exec(`UPDATE settings SET value = '15.5' WHERE key = 'commission_bps'`)
	f.exec(`UPDATE positions SET qty = 100000000, cost_total = 250000000000 WHERE user_id = $1`, f.seller)
	f.grant(f.buyer, 1_000_000_000_000)
	f.grant(f.seller, 1_000_000_000_000)
	f.e = f.newEngine()
	ctx := context.Background()

	run := func(name string, cmds []Command) []Event {
		start := time.Now()
		var all []Event
		for i := 0; i < len(cmds); i += 2000 {
			end := min(i+2000, len(cmds))
			evs, err := f.e.ProcessBatch(ctx, cmds[i:end])
			if err != nil {
				t.Fatalf("%s: %v", name, err)
			}
			all = append(all, evs...)
		}
		d := time.Since(start)
		t.Logf("%-22s %5d commands in %6.0f ms = %6.0f per second", name, len(cmds), float64(d.Milliseconds()), float64(len(cmds))/d.Seconds())
		return all
	}

	const n = 3000
	var quotes []Command
	for i := 0; i < n; i++ {
		side, price := "buy", int64(2600+2*(i%25))
		if i%2 == 1 {
			side, price = "sell", int64(2750+2*(i%25))
		}
		user := f.buyer
		if side == "sell" {
			user = f.seller
		}
		quotes = append(quotes, Command{Type: "new", UserID: user, Symbol: "2222", Side: side, Kind: "limit",
			Price: price, Qty: 100, Key: fmt.Sprintf("q%d", i)})
	}
	var cancels []Command
	for _, ev := range run("resting quotes", quotes) {
		if ev.Type == "order" && ev.Status == "open" {
			cancels = append(cancels, Command{Type: "cancel", UserID: ev.UserID, OrderID: ev.OrderID})
		}
	}
	if len(cancels) != n {
		t.Fatalf("expected %d open orders, got %d", n, len(cancels))
	}
	run("cancels", cancels)

	var trades []Command
	for i := 0; i < n/2; i++ {
		trades = append(trades,
			Command{Type: "new", UserID: f.seller, Symbol: "2222", Side: "sell", Kind: "limit", Price: 2700, Qty: 10, Key: fmt.Sprintf("s%d", i)},
			Command{Type: "new", UserID: f.buyer, Symbol: "2222", Side: "buy", Kind: "limit", Price: 2700, Qty: 10, Key: fmt.Sprintf("b%d", i)})
	}
	run("orders that trade", trades)
	if got := f.int(`SELECT count(*) FROM trades`); got != n/2 {
		t.Fatalf("expected %d trades, got %d", n/2, got)
	}
	if sum := f.int(`SELECT sum(balance) FROM ledger_accounts`); sum != 0 {
		t.Fatalf("ledger does not balance: %d", sum)
	}
}
