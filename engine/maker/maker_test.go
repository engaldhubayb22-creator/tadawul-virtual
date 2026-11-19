package maker

import (
	"math/rand"
	"testing"
)

func market() Market {
	return Market{Tick: 5, Low: 8550, High: 10450, Mid: 9500, Inventory: 1000, BaseInventory: 1000}
}

func TestStepFollowsSpreadOnTheTickGrid(t *testing.T) {
	c := DefaultConfig() // 10 bps of 95.00 = 9.5 halalas -> 5 on a 5-halala grid
	if s := Step(market(), c); s != 5 {
		t.Fatalf("step = %d", s)
	}
	c.SpreadBps = 50 // 47.5 -> 45
	if s := Step(market(), c); s != 45 {
		t.Fatalf("step = %d", s)
	}
	cheap := Market{Tick: 1, Mid: 300}
	if s := Step(cheap, DefaultConfig()); s != 1 {
		t.Fatalf("step never drops below one tick, got %d", s)
	}
}

func TestScenarioReachesTargetAndHolds(t *testing.T) {
	for _, target := range []int64{9900, 9000} {
		rng := rand.New(rand.NewSource(1))
		m, c := market(), DefaultConfig()
		c.Target = target
		reached := -1
		for i := 0; i < 500; i++ {
			m.Mid = NextMid(m, c, rng)
			if m.Mid == target && reached < 0 {
				reached = i
			}
			if reached >= 0 && m.Mid != target {
				t.Fatalf("target %d: left the target at cycle %d (mid %d)", target, i, m.Mid)
			}
			if m.Mid%m.Tick != 0 {
				t.Fatalf("mid %d off the tick grid", m.Mid)
			}
		}
		if reached < 0 {
			t.Fatalf("target %d never reached, ended at %d", target, m.Mid)
		}
	}
}

func TestScenarioCannotLeaveDailyLimits(t *testing.T) {
	rng := rand.New(rand.NewSource(2))
	m, c := market(), DefaultConfig()
	c.Target = 20000 // far above the high limit
	for i := 0; i < 2000; i++ {
		m.Mid = NextMid(m, c, rng)
		bids, asks := Quotes(m, m.Mid, c, rng)
		for _, q := range append(bids, asks...) {
			if q.Price < m.Low || q.Price > m.High {
				t.Fatalf("quote %d outside limits %d..%d (mid %d)", q.Price, m.Low, m.High, m.Mid)
			}
		}
	}
	if m.Mid <= 9500 {
		t.Fatalf("price did not rise toward the target: %d", m.Mid)
	}
}

func TestRandomWalkLeansAgainstInventory(t *testing.T) {
	drift := func(inventory int64) int64 {
		rng := rand.New(rand.NewSource(3))
		m, c := market(), DefaultConfig()
		m.Low, m.High = 1, 1_000_000 // no limits in the way
		m.Inventory = inventory
		start := m.Mid
		for i := 0; i < 400; i++ {
			m.Mid = NextMid(m, c, rng)
		}
		return m.Mid - start
	}
	long, short := drift(2000), drift(200)
	if long >= 0 {
		t.Errorf("holding too many shares should pull the price down, drift %d", long)
	}
	if short <= 0 {
		t.Errorf("holding too few shares should pull the price up, drift %d", short)
	}
}

func TestQuotesLadder(t *testing.T) {
	rng := rand.New(rand.NewSource(4))
	m, c := market(), DefaultConfig()
	bids, asks := Quotes(m, 9500, c, rng)
	if len(bids) != 5 || len(asks) != 5 {
		t.Fatalf("levels: %d bids, %d asks", len(bids), len(asks))
	}
	for k := 0; k < 5; k++ {
		if bids[k].Price != 9500-int64(k+1)*5 || asks[k].Price != 9500+int64(k+1)*5 {
			t.Fatalf("level %d: bid %d ask %d", k, bids[k].Price, asks[k].Price)
		}
		for _, q := range []Quote{bids[k], asks[k]} {
			if q.Qty < c.QtyMin || q.Qty > c.QtyMax {
				t.Fatalf("qty %d outside %d..%d", q.Qty, c.QtyMin, c.QtyMax)
			}
		}
	}
	c.Enabled = false
	if b, a := Quotes(m, 9500, c, rng); b != nil || a != nil {
		t.Fatalf("a disabled maker must not quote")
	}
}

func TestQuoteSizesShrinkOnTheCrowdedSide(t *testing.T) {
	c := DefaultConfig()
	c.QtyMin, c.QtyMax = 1000, 1000
	m := market()
	m.Inventory = 1600 // long: bids halve
	bids, asks := Quotes(m, 9500, c, rand.New(rand.NewSource(5)))
	if bids[0].Qty != 500 || asks[0].Qty != 1000 {
		t.Fatalf("long: bid %d ask %d", bids[0].Qty, asks[0].Qty)
	}
	m.Inventory = 400 // short: asks halve
	bids, asks = Quotes(m, 9500, c, rand.New(rand.NewSource(5)))
	if bids[0].Qty != 1000 || asks[0].Qty != 500 {
		t.Fatalf("short: bid %d ask %d", bids[0].Qty, asks[0].Qty)
	}
}
