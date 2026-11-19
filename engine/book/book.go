// Package book implements the in-memory order book and matching logic for a
// single symbol. It is deterministic and does no I/O: the caller feeds it
// orders one at a time (one goroutine per symbol) and persists the results.
//
// All prices are integers in halalas (1 SAR = 100 halalas). No floats.
package book

import (
	"errors"
	"sort"
)

type Side int8

const (
	Buy  Side = 1
	Sell Side = 2
)

type Kind int8

const (
	Limit  Kind = 1
	Market Kind = 2
)

var (
	ErrBadSide      = errors.New("book: side must be buy or sell")
	ErrBadKind      = errors.New("book: kind must be limit or market")
	ErrBadQty       = errors.New("book: quantity must be positive")
	ErrBadPrice     = errors.New("book: limit price must be positive")
	ErrBadTick      = errors.New("book: price is not a multiple of the tick size")
	ErrOutOfLimits  = errors.New("book: price is outside the daily limits")
	ErrDuplicateID  = errors.New("book: order id already in the book")
	ErrUnknownOrder = errors.New("book: order not found")
	ErrHalted       = errors.New("book: trading is halted for this symbol")
)

// Order is an instruction to buy or sell. Price is ignored for market orders.
type Order struct {
	ID        uint64
	UserID    uint64
	Side      Side
	Kind      Kind
	Price     int64
	Qty       int64
	Remaining int64
}

// Trade is one execution between a resting order and an incoming one.
// The price is always the resting order's price.
type Trade struct {
	BuyOrderID  uint64
	SellOrderID uint64
	BuyUserID   uint64
	SellUserID  uint64
	Price       int64
	Qty         int64
	TakerSide   Side
}

// Result describes what happened to a submitted order.
type Result struct {
	Trades []Trade
	// Rested is the quantity left resting in the book (limit orders only).
	Rested int64
	// Cancelled is the unfilled quantity that was dropped: the remainder of a
	// market order, or of any order stopped by self-trade prevention.
	Cancelled int64
	// SelfTradeBlocked is true when matching stopped because the next resting
	// order belonged to the same user.
	SelfTradeBlocked bool
}

// LevelView is one aggregated price level, for market-depth display.
type LevelView struct {
	Price  int64
	Qty    int64
	Orders int
}

type level struct {
	price  int64
	qty    int64
	orders []*Order // FIFO: orders[0] is the oldest
}

// sideBook keeps levels sorted best-first: bids descending, asks ascending.
type sideBook struct {
	levels []*level
	desc   bool
}

func (s *sideBook) find(price int64) (int, bool) {
	i := sort.Search(len(s.levels), func(i int) bool {
		if s.desc {
			return s.levels[i].price <= price
		}
		return s.levels[i].price >= price
	})
	return i, i < len(s.levels) && s.levels[i].price == price
}

func (s *sideBook) add(o *Order) {
	i, ok := s.find(o.Price)
	if !ok {
		s.levels = append(s.levels, nil)
		copy(s.levels[i+1:], s.levels[i:])
		s.levels[i] = &level{price: o.Price}
	}
	lv := s.levels[i]
	lv.orders = append(lv.orders, o)
	lv.qty += o.Remaining
}

func (s *sideBook) remove(o *Order) bool {
	i, ok := s.find(o.Price)
	if !ok {
		return false
	}
	lv := s.levels[i]
	for j, x := range lv.orders {
		if x.ID == o.ID {
			lv.orders = append(lv.orders[:j], lv.orders[j+1:]...)
			lv.qty -= o.Remaining
			if len(lv.orders) == 0 {
				s.levels = append(s.levels[:i], s.levels[i+1:]...)
			}
			return true
		}
	}
	return false
}

func (s *sideBook) view(n int) []LevelView {
	if n > len(s.levels) {
		n = len(s.levels)
	}
	out := make([]LevelView, n)
	for i := 0; i < n; i++ {
		lv := s.levels[i]
		out[i] = LevelView{Price: lv.price, Qty: lv.qty, Orders: len(lv.orders)}
	}
	return out
}

// Book is the order book for one symbol. It is not safe for concurrent use.
type Book struct {
	Symbol   string
	TickSize int64
	// LowLimit and HighLimit are the daily price limits; 0 means no limit.
	LowLimit  int64
	HighLimit int64
	Halted    bool

	bids      sideBook
	asks      sideBook
	index     map[uint64]*Order
	lastPrice int64
}

func New(symbol string, tickSize int64) *Book {
	if tickSize <= 0 {
		tickSize = 1
	}
	return &Book{
		Symbol:   symbol,
		TickSize: tickSize,
		bids:     sideBook{desc: true},
		asks:     sideBook{},
		index:    make(map[uint64]*Order),
	}
}

// SetLimits sets the daily price limits. Resting orders outside the new
// limits stay in the book but can no longer trade until limits widen.
func (b *Book) SetLimits(low, high int64) {
	b.LowLimit, b.HighLimit = low, high
}

func (b *Book) withinLimits(price int64) bool {
	if b.LowLimit > 0 && price < b.LowLimit {
		return false
	}
	if b.HighLimit > 0 && price > b.HighLimit {
		return false
	}
	return true
}

func (b *Book) validate(o *Order) error {
	if b.Halted {
		return ErrHalted
	}
	if o.Side != Buy && o.Side != Sell {
		return ErrBadSide
	}
	if o.Kind != Limit && o.Kind != Market {
		return ErrBadKind
	}
	if o.Qty <= 0 {
		return ErrBadQty
	}
	if _, dup := b.index[o.ID]; dup {
		return ErrDuplicateID
	}
	if o.Kind == Limit {
		if o.Price <= 0 {
			return ErrBadPrice
		}
		if o.Price%b.TickSize != 0 {
			return ErrBadTick
		}
		if !b.withinLimits(o.Price) {
			return ErrOutOfLimits
		}
	}
	return nil
}

// Validate reports whether Submit would accept the order, without changing
// the book.
func (b *Book) Validate(o Order) error { return b.validate(&o) }

// Rest places an order directly in the book without matching. It is used to
// rebuild the book from stored open orders after a restart; o.Remaining must
// be the unfilled quantity. Orders must be restored oldest first.
func (b *Book) Rest(in Order) error {
	if in.Side != Buy && in.Side != Sell {
		return ErrBadSide
	}
	if in.Price <= 0 {
		return ErrBadPrice
	}
	if in.Remaining <= 0 {
		return ErrBadQty
	}
	if _, dup := b.index[in.ID]; dup {
		return ErrDuplicateID
	}
	o := &in
	o.Kind = Limit
	side := &b.bids
	if o.Side == Sell {
		side = &b.asks
	}
	side.add(o)
	b.index[o.ID] = o
	return nil
}

// SetLastPrice seeds the last traded price, e.g. after a restart.
func (b *Book) SetLastPrice(p int64) { b.lastPrice = p }

// Submit validates an order, matches it against the opposite side by
// price-time priority, and rests any limit remainder. A returned error means
// the order was rejected and the book is unchanged.
func (b *Book) Submit(in Order) (Result, error) {
	var res Result
	if err := b.validate(&in); err != nil {
		return res, err
	}
	o := &in
	o.Remaining = o.Qty

	opp := &b.asks
	if o.Side == Sell {
		opp = &b.bids
	}

matching:
	for o.Remaining > 0 && len(opp.levels) > 0 {
		lv := opp.levels[0]
		if o.Kind == Limit {
			if o.Side == Buy && lv.price > o.Price {
				break
			}
			if o.Side == Sell && lv.price < o.Price {
				break
			}
		}
		if !b.withinLimits(lv.price) {
			break
		}
		for o.Remaining > 0 && len(lv.orders) > 0 {
			r := lv.orders[0]
			if r.UserID == o.UserID {
				res.SelfTradeBlocked = true
				break matching
			}
			q := o.Remaining
			if r.Remaining < q {
				q = r.Remaining
			}
			r.Remaining -= q
			o.Remaining -= q
			lv.qty -= q
			b.lastPrice = lv.price

			t := Trade{Price: lv.price, Qty: q, TakerSide: o.Side}
			if o.Side == Buy {
				t.BuyOrderID, t.BuyUserID = o.ID, o.UserID
				t.SellOrderID, t.SellUserID = r.ID, r.UserID
			} else {
				t.SellOrderID, t.SellUserID = o.ID, o.UserID
				t.BuyOrderID, t.BuyUserID = r.ID, r.UserID
			}
			res.Trades = append(res.Trades, t)

			if r.Remaining == 0 {
				lv.orders[0] = nil
				lv.orders = lv.orders[1:]
				delete(b.index, r.ID)
			}
		}
		if len(lv.orders) == 0 {
			opp.levels[0] = nil
			opp.levels = opp.levels[1:]
		}
	}

	if o.Remaining > 0 {
		if o.Kind == Limit && !res.SelfTradeBlocked {
			own := &b.bids
			if o.Side == Sell {
				own = &b.asks
			}
			own.add(o)
			b.index[o.ID] = o
			res.Rested = o.Remaining
		} else {
			res.Cancelled = o.Remaining
		}
	}
	return res, nil
}

// Cancel removes a resting order and returns the quantity that was released.
func (b *Book) Cancel(orderID uint64) (int64, error) {
	o, ok := b.index[orderID]
	if !ok {
		return 0, ErrUnknownOrder
	}
	side := &b.bids
	if o.Side == Sell {
		side = &b.asks
	}
	if !side.remove(o) {
		return 0, ErrUnknownOrder
	}
	delete(b.index, orderID)
	return o.Remaining, nil
}

// Depth returns the best n price levels on each side, best first.
func (b *Book) Depth(n int) (bids, asks []LevelView) {
	return b.bids.view(n), b.asks.view(n)
}

func (b *Book) BestBid() (int64, bool) {
	if len(b.bids.levels) == 0 {
		return 0, false
	}
	return b.bids.levels[0].price, true
}

func (b *Book) BestAsk() (int64, bool) {
	if len(b.asks.levels) == 0 {
		return 0, false
	}
	return b.asks.levels[0].price, true
}

// LastPrice is the price of the most recent trade, or 0 if none yet.
func (b *Book) LastPrice() int64 { return b.lastPrice }

// OpenOrders is the number of orders currently resting in the book.
func (b *Book) OpenOrders() int { return len(b.index) }
