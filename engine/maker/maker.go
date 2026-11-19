// Package maker holds the market maker's decisions: where the mid price goes
// next and which quotes to post around it. It does no I/O, so the behaviour
// can be tested exactly; cmd/bot feeds it market state and sends the orders.
package maker

import "math/rand"

// Config is one company's row in bot_configs.
type Config struct {
	Enabled   bool
	SpreadBps int   // gap between quote levels, in basis points of the price
	Levels    int   // quote levels on each side
	QtyMin    int64 // size range of each level
	QtyMax    int64
	NoisePct  int   // chance per cycle of a random market order
	Target    int64 // active scenario target price, 0 if none
}

func DefaultConfig() Config {
	return Config{Enabled: true, SpreadBps: 10, Levels: 5, QtyMin: 100, QtyMax: 2000, NoisePct: 70}
}

// Market is what the maker sees for one symbol.
type Market struct {
	Tick, Low, High int64 // tick size and today's price limits
	Mid             int64 // the maker's current mid price
	Inventory       int64 // shares the maker holds now
	BaseInventory   int64 // shares it started with
}

type Quote struct {
	Price int64
	Qty   int64
}

// Step is the distance between quote levels: SpreadBps of the price, on the
// tick grid, and never less than one tick.
func Step(m Market, c Config) int64 {
	s := m.Mid * int64(c.SpreadBps) / 10000 / m.Tick * m.Tick
	if s < m.Tick {
		s = m.Tick
	}
	return s
}

// NextMid moves the mid price one cycle forward.
//
// With a scenario target it walks toward the target, mostly forward with an
// occasional step back so the path does not look mechanical, and stops on it.
// Without one it is a random walk that leans against the maker's inventory:
// holding too many shares pulls quotes down to sell them, too few pulls them
// up, which keeps the maker from drifting into owning everything or nothing.
func NextMid(m Market, c Config, rng *rand.Rand) int64 {
	step := Step(m, c)
	mid := m.Mid
	switch {
	case c.Target > 0 && mid != c.Target:
		dir := int64(1)
		if c.Target < mid {
			dir = -1
		}
		move := dir * step * int64(1+rng.Intn(2))
		if rng.Intn(5) == 0 {
			move = -dir * step
		}
		next := mid + move
		if (dir > 0 && next > c.Target) || (dir < 0 && next < c.Target) {
			next = c.Target
		}
		mid = next
	case c.Target > 0:
		// On target: hold.
	default:
		move := int64(rng.Intn(5) - 2)
		if m.BaseInventory > 0 {
			switch {
			case m.Inventory*10 > m.BaseInventory*12:
				move--
			case m.Inventory*10 < m.BaseInventory*8:
				move++
			}
		}
		mid += move * step
	}

	// Keep every quote level inside the daily limits.
	room := int64(c.Levels+1) * step
	if lo := m.Low + room; mid < lo {
		mid = lo
	}
	if hi := m.High - room; mid > hi {
		mid = hi
	}
	if m.Low+room > m.High-room { // limits tighter than the ladder: sit in the middle
		mid = (m.Low + m.High) / 2
	}
	return mid / m.Tick * m.Tick
}

// Quotes builds the bid and ask ladder around mid, nearest level first.
// Sizes shrink on the side that would push inventory further from base.
func Quotes(m Market, mid int64, c Config, rng *rand.Rand) (bids, asks []Quote) {
	if !c.Enabled {
		return nil, nil
	}
	step := Step(Market{Tick: m.Tick, Mid: mid}, c)
	bidScale, askScale := int64(2), int64(2) // halves: 2 = full size, 1 = half
	if m.BaseInventory > 0 {
		if m.Inventory*2 > m.BaseInventory*3 {
			bidScale = 1 // already long: buy less
		}
		if m.Inventory*2 < m.BaseInventory {
			askScale = 1 // running short: sell less
		}
	}
	size := func(scale int64) int64 {
		q := c.QtyMin
		if c.QtyMax > c.QtyMin {
			q += rng.Int63n(c.QtyMax - c.QtyMin + 1)
		}
		if q = q * scale / 2; q < 1 {
			q = 1
		}
		return q
	}
	for k := int64(1); k <= int64(c.Levels); k++ {
		if p := mid - k*step; p >= m.Low && p > 0 {
			bids = append(bids, Quote{Price: p, Qty: size(bidScale)})
		}
		if p := mid + k*step; m.High == 0 || p <= m.High {
			asks = append(asks, Quote{Price: p, Qty: size(askScale)})
		}
	}
	return bids, asks
}
