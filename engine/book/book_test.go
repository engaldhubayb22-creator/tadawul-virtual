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

func TestMarketOrderSweepsAndNeverRests(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Sell, 2700, 50))
	mustSubmit(t, b, limit(2, 11, Sell, 2720, 50))
	res := mustSubmit(t, b, market(3, 20, Buy, 150))
	if len(res.Trades) != 2 || res.Cancelled != 50 || res.Rested != 0 {
		t.Fatalf("got %+v", res)
	}
	if b.OpenOrders() != 0 {
		t.Fatalf("book should be empty, has %d orders", b.OpenOrders())
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

func TestMarketOrderRespectsLimits(t *testing.T) {
	b := New("2222", 1)
	mustSubmit(t, b, limit(1, 10, Sell, 2700, 50))
	mustSubmit(t, b, limit(2, 11, Sell, 2900, 50))
	b.SetLimits(2500, 2800) // 2900 is now outside the limits
	res := mustSubmit(t, b, market(3, 20, Buy, 100))
	if len(res.Trades) != 1 || res.Trades[0].Price != 2700 || res.Cancelled != 50 {
		t.Fatalf("got %+v", res)
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
