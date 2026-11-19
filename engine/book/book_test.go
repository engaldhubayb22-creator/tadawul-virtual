package book

import (
	"errors"
	"math/rand"
	"testing"
)

func limit(id, user uint64, side Side, price, qty int64) Order {
	return Order{ID: id, UserID: user, Side: side, Kind: Limit, Price: price, Qty: qty}
}

func market(id, user uint64, side Side, qty int64) Order {
	return Order{ID: id, UserID: user, Side: side, Kind: Market, Qty: qty}
}

func mustSubmit(t *testing.T, b *Book, o Order) Result {
	t.Helper()
	res, err := b.Submit(o)
	if err != nil {
		t.Fatalf("submit %d: %v", o.ID, err)
	}
	return res
}

func TestRestsWhenNoCross(t *testing.T) {
	b := New("2222", 1)
	res := mustSubmit(t, b, limit(1, 10, Buy, 2700, 100))
	if len(res.Trades) != 0 || res.Rested != 100 {
		t.Fatalf("want rested 100 and no trades, got %+v", res)
	}
	res = mustSubmit(t, b, limit(2, 11, Sell, 2710, 50))
	if len(res.Trades) != 0 || res.Rested != 50 {
		t.Fatalf("want rested 50 and no trades, got %+v", res)
	}
	if bid, _ := b.BestBid(); bid != 2700 {
		t.Fatalf("best bid = %d", bid)
	}
	if ask, _ := b.BestAsk(); ask != 2710 {
		t.Fatalf("best ask = %d", ask)
	}
}

func TestPriceThenTimePriority(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Sell, 2710, 100)) // worse price
	mustSubmit(t, b, limit(2, 11, Sell, 2700, 100)) // best price, first
	mustSubmit(t, b, limit(3, 12, Sell, 2700, 100)) // best price, second

	res := mustSubmit(t, b, limit(4, 20, Buy, 2710, 250))
	if len(res.Trades) != 3 {
		t.Fatalf("want 3 trades, got %d", len(res.Trades))
	}
	wantSeller := []uint64{2, 3, 1}
	wantPrice := []int64{2700, 2700, 2710}
	wantQty := []int64{100, 100, 50}
	for i, tr := range res.Trades {
		if tr.SellOrderID != wantSeller[i] || tr.Price != wantPrice[i] || tr.Qty != wantQty[i] {
			t.Fatalf("trade %d = %+v", i, tr)
		}
		if tr.BuyOrderID != 4 || tr.TakerSide != Buy {
			t.Fatalf("trade %d has wrong buyer/taker: %+v", i, tr)
		}
	}
	if res.Rested != 0 || res.Cancelled != 0 {
		t.Fatalf("incoming should be fully filled: %+v", res)
	}
	_, asks := b.Depth(5)
	if len(asks) != 1 || asks[0].Price != 2710 || asks[0].Qty != 50 {
		t.Fatalf("asks = %+v", asks)
	}
	if b.LastPrice() != 2710 {
		t.Fatalf("last price = %d", b.LastPrice())
	}
}

func TestTradeAtRestingPrice(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Buy, 2750, 100))
	res := mustSubmit(t, b, limit(2, 11, Sell, 2700, 100))
	if len(res.Trades) != 1 || res.Trades[0].Price != 2750 {
		t.Fatalf("want one trade at 2750, got %+v", res.Trades)
	}
}

func TestPartialFillRestsRemainder(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Sell, 2700, 40))
	res := mustSubmit(t, b, limit(2, 11, Buy, 2700, 100))
	if len(res.Trades) != 1 || res.Trades[0].Qty != 40 || res.Rested != 60 {
		t.Fatalf("got %+v", res)
	}
	bids, asks := b.Depth(5)
	if len(asks) != 0 || len(bids) != 1 || bids[0].Qty != 60 {
		t.Fatalf("bids=%+v asks=%+v", bids, asks)
	}
}

// The trader's limit is the worst price accepted; every fill happens at the
// resting order's own price, cheapest first, and stops at the limit.
func TestLimitBuyTakesBetterPricesFirstAndStopsAtItsLimit(t *testing.T) {
	b := New("1150", 2)
	b.TickFn = BandTick
	mustSubmit(t, b, limit(1, 10, Sell, 3000, 200))
	mustSubmit(t, b, limit(2, 10, Sell, 3010, 300))
	mustSubmit(t, b, limit(3, 10, Sell, 3060, 400))
	mustSubmit(t, b, limit(4, 10, Sell, 3062, 500))

	res := mustSubmit(t, b, limit(5, 20, Buy, 3060, 1000))
	want := []Trade{{Price: 3000, Qty: 200}, {Price: 3010, Qty: 300}, {Price: 3060, Qty: 400}}
	if len(res.Trades) != 3 {
		t.Fatalf("trades = %+v", res.Trades)
	}
	var qty, value int64
	for i, tr := range res.Trades {
		if tr.Price != want[i].Price || tr.Qty != want[i].Qty {
			t.Fatalf("trade %d = %+v", i, tr)
		}
		qty += tr.Qty
		value += tr.Price * tr.Qty
	}
	if qty != 900 || value/qty != 3030 || value%qty != 0 {
		t.Fatalf("filled %d at average %d.%d", qty, value/qty, value%qty)
	}
	if res.Rested != 100 || res.RestPrice != 3060 {
		t.Fatalf("remainder: %+v", res)
	}
	bids, asks := b.Depth(5)
	if len(bids) != 1 || bids[0].Price != 3060 || bids[0].Qty != 100 || asks[0].Price != 3062 || asks[0].Qty != 500 {
		t.Fatalf("book: bids %+v asks %+v", bids, asks)
	}
}

func TestLimitSellTakesHigherBidsFirstAndEarlierOrdersFirst(t *testing.T) {
	b := New("1150", 2)
	b.TickFn = BandTick
	mustSubmit(t, b, limit(1, 10, Buy, 2940, 100))
	mustSubmit(t, b, limit(2, 11, Buy, 3000, 100)) // best price
	mustSubmit(t, b, limit(3, 12, Buy, 2990, 100)) // same price as 4, entered first
	mustSubmit(t, b, limit(4, 13, Buy, 2990, 100))
	mustSubmit(t, b, limit(5, 14, Buy, 2938, 100)) // below the seller's limit

	res := mustSubmit(t, b, limit(6, 20, Sell, 2940, 350))
	order := []uint64{2, 3, 4, 1}
	prices := []int64{3000, 2990, 2990, 2940}
	qtys := []int64{100, 100, 100, 50}
	if len(res.Trades) != 4 {
		t.Fatalf("trades = %+v", res.Trades)
	}
	for i, tr := range res.Trades {
		if tr.BuyOrderID != order[i] || tr.Price != prices[i] || tr.Qty != qtys[i] {
			t.Fatalf("trade %d = %+v", i, tr)
		}
	}
	if res.Rested != 0 || b.OpenOrders() != 2 { // half of order 1, and order 5
		t.Fatalf("rested %d, open %d", res.Rested, b.OpenOrders())
	}
}

// Best ask 30.00, step 0.02: a market buy may pay up to 30.10. What is left
// becomes a limit order at the last price it traded at.
func TestMarketOrderWalksFiveStepsThenRestsAtLastPrice(t *testing.T) {
	b := New("1150", 2)
	b.TickFn = BandTick
	mustSubmit(t, b, limit(1, 10, Sell, 3000, 100))
	mustSubmit(t, b, limit(2, 10, Sell, 3010, 100))
	mustSubmit(t, b, limit(3, 10, Sell, 3012, 100))
	if cap, _ := b.MarketCap(Buy); cap != 3010 {
		t.Fatalf("cap = %d", cap)
	}
	res := mustSubmit(t, b, market(4, 20, Buy, 500))
	if len(res.Trades) != 2 || res.Trades[1].Price != 3010 {
		t.Fatalf("trades = %+v", res.Trades)
	}
	if res.Rested != 300 || res.RestPrice != 3010 || res.Cancelled != 0 {
		t.Fatalf("remainder: %+v", res)
	}
	if bid, _ := b.BestBid(); bid != 3010 {
		t.Fatalf("best bid = %d", bid)
	}
	// The rested remainder is a normal limit order: a later seller hits it.
	sell := mustSubmit(t, b, limit(5, 11, Sell, 3010, 50))
	if len(sell.Trades) != 1 || sell.Trades[0].BuyOrderID != 4 || sell.Trades[0].Price != 3010 {
		t.Fatalf("sell = %+v", sell.Trades)
	}

	// Sell side mirrors it: best bid 30.10 (250 left), floor 30.00.
	if cap, _ := b.MarketCap(Sell); cap != 3000 {
		t.Fatalf("sell cap = %d", cap)
	}
}

func TestMarketCapStaysInsideDailyLimits(t *testing.T) {
	b := New("1150", 2)
	b.TickFn = BandTick
	b.SetLimits(2520, 3004)
	mustSubmit(t, b, limit(1, 10, Sell, 3000, 100))
	mustSubmit(t, b, limit(2, 10, Sell, 3004, 100))
	if cap, _ := b.MarketCap(Buy); cap != 3004 {
		t.Fatalf("cap = %d, want the high limit", cap)
	}
	res := mustSubmit(t, b, market(3, 20, Buy, 300))
	if len(res.Trades) != 2 || res.Rested != 100 || res.RestPrice != 3004 {
		t.Fatalf("got %+v", res)
	}
}

func TestFillAvailableAndFillAllOrNothing(t *testing.T) {
	newBook := func() *Book {
		b := New("1150", 2)
		b.TickFn = BandTick
		mustSubmit(t, b, limit(1, 10, Sell, 3000, 100))
		mustSubmit(t, b, limit(2, 10, Sell, 3002, 100))
		return b
	}
	// IOC: take the 200 on offer, cancel the other 100, leave nothing behind.
	b := newBook()
	o := limit(3, 20, Buy, 3002, 300)
	o.TIF = IOC
	res := mustSubmit(t, b, o)
	if len(res.Trades) != 2 || res.Cancelled != 100 || res.Rested != 0 || b.OpenOrders() != 0 {
		t.Fatalf("ioc: %+v open %d", res, b.OpenOrders())
	}
	// FOK that cannot be filled: nothing trades, the book is untouched.
	b = newBook()
	o = limit(4, 20, Buy, 3002, 300)
	o.TIF = FOK
	res = mustSubmit(t, b, o)
	if len(res.Trades) != 0 || res.Cancelled != 300 || b.OpenOrders() != 2 {
		t.Fatalf("fok short: %+v open %d", res, b.OpenOrders())
	}
	// FOK that fits: fills completely.
	o = limit(5, 20, Buy, 3002, 200)
	o.TIF = FOK
	res = mustSubmit(t, b, o)
	if len(res.Trades) != 2 || res.Cancelled != 0 || b.OpenOrders() != 0 {
		t.Fatalf("fok full: %+v", res)
	}
	// FOK market order obeys the five-step cap too.
	b = newBook()
	mustSubmit(t, b, limit(6, 10, Sell, 3020, 500)) // beyond 30.10
	o = market(7, 20, Buy, 300)
	o.TIF = FOK
	if res = mustSubmit(t, b, o); len(res.Trades) != 0 || res.Cancelled != 300 {
		t.Fatalf("fok market: %+v", res)
	}
}

func TestBandTicks(t *testing.T) {
	for _, c := range []struct{ price, tick int64 }{
		{100, 1}, {2499, 1}, {2500, 2}, {4998, 2}, {5000, 5}, {9995, 5},
		{10000, 10}, {24990, 10}, {25000, 20}, {49980, 20}, {50000, 50}, {120000, 50},
	} {
		if got := BandTick(c.price); got != c.tick {
			t.Errorf("BandTick(%d) = %d, want %d", c.price, got, c.tick)
		}
	}
	b := New("1150", 1)
	b.TickFn = BandTick
	if _, err := b.Submit(limit(1, 1, Buy, 3031, 10)); !errors.Is(err, ErrBadTick) {
		t.Fatalf("30.31 must be rejected, got %v", err)
	}
	if down, up := NearestTicks(3031); down != 3030 || up != 3032 {
		t.Fatalf("nearest to 30.31 = %d, %d", down, up)
	}
	if down, up := NearestTicks(3030); down != 3030 || up != 3030 {
		t.Fatalf("30.30 is valid, got %d, %d", down, up)
	}
	if down, up := NearestTicks(10003); down != 10000 || up != 10010 {
		t.Fatalf("nearest to 100.03 = %d, %d", down, up)
	}
	for _, p := range []int64{3030, 3032, 2499, 5005, 10010} {
		if _, err := b.Submit(limit(uint64(p), 1, Buy, p, 1)); err != nil {
			t.Errorf("%d should be accepted: %v", p, err)
		}
	}
}

func TestMarketOrderOnEmptyBookIsCancelled(t *testing.T) {
	b := New("2222", 1)
	res := mustSubmit(t, b, market(1, 10, Sell, 100))
	if len(res.Trades) != 0 || res.Cancelled != 100 {
		t.Fatalf("got %+v", res)
	}
}

func TestCancel(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Buy, 2700, 100))
	mustSubmit(t, b, limit(2, 11, Buy, 2700, 30))
	released, err := b.Cancel(1)
	if err != nil || released != 100 {
		t.Fatalf("released=%d err=%v", released, err)
	}
	bids, _ := b.Depth(5)
	if len(bids) != 1 || bids[0].Qty != 30 || bids[0].Orders != 1 {
		t.Fatalf("bids = %+v", bids)
	}
	if _, err := b.Cancel(1); !errors.Is(err, ErrUnknownOrder) {
		t.Fatalf("second cancel err = %v", err)
	}
	// A cancelled order must not trade.
	res := mustSubmit(t, b, limit(3, 12, Sell, 2700, 100))
	if len(res.Trades) != 1 || res.Trades[0].BuyOrderID != 2 {
		t.Fatalf("got %+v", res.Trades)
	}
}

func TestSelfTradeIsBlocked(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Sell, 2700, 100))
	res := mustSubmit(t, b, limit(2, 10, Buy, 2700, 100))
	if len(res.Trades) != 0 || !res.SelfTradeBlocked || res.Cancelled != 100 || res.Rested != 0 {
		t.Fatalf("got %+v", res)
	}
	// The resting order is untouched.
	_, asks := b.Depth(1)
	if len(asks) != 1 || asks[0].Qty != 100 {
		t.Fatalf("asks = %+v", asks)
	}
}

func TestSelfTradeStopsAfterFillingOthers(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 11, Sell, 2700, 40))
	mustSubmit(t, b, limit(2, 10, Sell, 2700, 60))
	res := mustSubmit(t, b, limit(3, 10, Buy, 2700, 100))
	if len(res.Trades) != 1 || res.Trades[0].Qty != 40 || res.Cancelled != 60 {
		t.Fatalf("got %+v", res)
	}
}

func TestValidation(t *testing.T) {
	b := New("2222", 5)
	b.SetLimits(2430, 2970)
	cases := []struct {
		name string
		o    Order
		want error
	}{
		{"zero qty", limit(1, 1, Buy, 2700, 0), ErrBadQty},
		{"zero price", limit(1, 1, Buy, 0, 10), ErrBadPrice},
		{"off tick", limit(1, 1, Buy, 2701, 10), ErrBadTick},
		{"off tick and out of limits", limit(1, 1, Buy, 2971, 10), ErrOutOfLimits},
		{"below low limit", limit(1, 1, Buy, 2425, 10), ErrOutOfLimits},
		{"above high limit", limit(1, 1, Sell, 2975, 10), ErrOutOfLimits},
		{"bad side", Order{ID: 1, UserID: 1, Kind: Limit, Price: 2700, Qty: 10}, ErrBadSide},
		{"bad kind", Order{ID: 1, UserID: 1, Side: Buy, Price: 2700, Qty: 10}, ErrBadKind},
	}
	for _, c := range cases {
		if _, err := b.Submit(c.o); !errors.Is(err, c.want) {
			t.Errorf("%s: err = %v, want %v", c.name, err, c.want)
		}
	}
	if b.OpenOrders() != 0 {
		t.Fatalf("rejected orders must not enter the book")
	}

	mustSubmit(t, b, limit(7, 1, Buy, 2700, 10))
	if _, err := b.Submit(limit(7, 2, Buy, 2700, 10)); !errors.Is(err, ErrDuplicateID) {
		t.Fatalf("duplicate id err = %v", err)
	}

	b.Halted = true
	if _, err := b.Submit(limit(8, 1, Buy, 2700, 10)); !errors.Is(err, ErrHalted) {
		t.Fatalf("halted err = %v", err)
	}
}

// TestRandomFlowInvariants throws random orders at the book and checks that
// quantity is conserved and the book never ends up crossed.
func TestRandomFlowInvariants(t *testing.T) {
	rng := rand.New(rand.NewSource(42))
	b := New("2222", 1)
	var submitted, traded, cancelled int64
	var live []uint64

	for id := uint64(1); id <= 20000; id++ {
		if len(live) > 0 && rng.Intn(5) == 0 {
			i := rng.Intn(len(live))
			if released, err := b.Cancel(live[i]); err == nil {
				cancelled += released
			}
			live = append(live[:i], live[i+1:]...)
			continue
		}
		o := Order{
			ID:     id,
			UserID: uint64(1 + rng.Intn(50)),
			Side:   Side(1 + rng.Intn(2)),
			Kind:   Limit,
			Price:  int64(2600 + rng.Intn(200)),
			Qty:    int64(1 + rng.Intn(500)),
		}
		if rng.Intn(10) == 0 {
			o.Kind = Market
		}
		res, err := b.Submit(o)
		if err != nil {
			t.Fatalf("order %d rejected: %v", id, err)
		}
		submitted += o.Qty
		var filled int64
		for _, tr := range res.Trades {
			if tr.BuyUserID == tr.SellUserID {
				t.Fatalf("self trade at order %d: %+v", id, tr)
			}
			filled += tr.Qty
		}
		if filled+res.Rested+res.Cancelled != o.Qty {
			t.Fatalf("order %d: filled %d + rested %d + cancelled %d != qty %d",
				id, filled, res.Rested, res.Cancelled, o.Qty)
		}
		traded += 2 * filled // each trade consumes quantity from both sides
		cancelled += res.Cancelled
		if res.Rested > 0 {
			live = append(live, id)
		}
		if bid, ok := b.BestBid(); ok {
			if ask, ok := b.BestAsk(); ok && bid >= ask {
				// A crossed book is only allowed when self-trade prevention
				// left two orders of the same user facing each other.
				if !res.SelfTradeBlocked && !sameUserAtTop(b) {
					t.Fatalf("book crossed after order %d: bid %d >= ask %d", id, bid, ask)
				}
			}
		}
	}

	var resting int64
	bids, asks := b.Depth(1 << 30)
	for _, lv := range append(bids, asks...) {
		resting += lv.Qty
	}
	if submitted != traded+cancelled+resting {
		t.Fatalf("quantity not conserved: submitted %d != traded %d + cancelled %d + resting %d",
			submitted, traded, cancelled, resting)
	}
}

func sameUserAtTop(b *Book) bool {
	if len(b.bids.levels) == 0 || len(b.asks.levels) == 0 {
		return false
	}
	return b.bids.levels[0].orders[0].UserID == b.asks.levels[0].orders[0].UserID
}

func BenchmarkSubmit(bm *testing.B) {
	rng := rand.New(rand.NewSource(1))
	b := New("2222", 1)
	bm.ResetTimer()
	for i := 0; i < bm.N; i++ {
		_, _ = b.Submit(Order{
			ID:     uint64(i + 1),
			UserID: uint64(1 + rng.Intn(1000)),
			Side:   Side(1 + rng.Intn(2)),
			Kind:   Limit,
			Price:  int64(2600 + rng.Intn(200)),
			Qty:    int64(1 + rng.Intn(500)),
		})
	}
}
