package feed

import (
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/book"
	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/service"
)

type msg struct {
	Ch   string          `json:"ch"`
	Op   string          `json:"op"`
	Ok   bool            `json:"ok"`
	User int64           `json:"user"`
	Data json.RawMessage `json:"data"`
}

func dial(t *testing.T, srv *httptest.Server) *websocket.Conn {
	t.Helper()
	c, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(srv.URL, "http"), nil)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	return c
}

func read(t *testing.T, c *websocket.Conn) msg {
	t.Helper()
	c.SetReadDeadline(time.Now().Add(2 * time.Second))
	var m msg
	if err := c.ReadJSON(&m); err != nil {
		t.Fatalf("read: %v", err)
	}
	return m
}

// expectNothing fails if any message arrives within a short window. It must
// be the last read on a connection: a timed-out read leaves it unusable.
func expectNothing(t *testing.T, c *websocket.Conn) {
	t.Helper()
	c.SetReadDeadline(time.Now().Add(150 * time.Millisecond))
	var m msg
	if err := c.ReadJSON(&m); err == nil {
		t.Fatalf("unexpected message: %+v %s", m, m.Data)
	}
}

// waitSubs blocks until the hub has registered n subscriptions in total.
func waitSubs(t *testing.T, h *Hub, n int) {
	t.Helper()
	for i := 0; i < 200; i++ {
		h.mu.Lock()
		total := 0
		for c := range h.clients {
			total += len(c.subs)
		}
		h.mu.Unlock()
		if total >= n {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatalf("hub never reached %d subscriptions", n)
}

func setup(t *testing.T) (*Hub, *httptest.Server) {
	h := NewHub()
	h.Auth = func(token string) (int64, bool) {
		if token == "good" {
			return 7, true
		}
		return 0, false
	}
	srv := httptest.NewServer(h)
	t.Cleanup(srv.Close)
	return h, srv
}

func TestBookSnapshotOnSubscribeThenLiveUpdates(t *testing.T) {
	h, srv := setup(t)
	h.Dispatch(service.Event{Type: "book", Symbol: "2222", Last: 2700,
		Bids: []book.LevelView{{Price: 2690, Qty: 100, Orders: 1}}})

	c := dial(t, srv)
	c.WriteJSON(map[string]any{"op": "sub", "ch": []string{"book:2222"}})

	m := read(t, c)
	var b bookMsg
	json.Unmarshal(m.Data, &b)
	if m.Ch != "book:2222" || b.Last != 2700 || len(b.Bids) != 1 || b.Bids[0].Price != 2690 {
		t.Fatalf("snapshot = %+v %s", m, m.Data)
	}
	// An empty side is an empty list, never null, so clients can loop over it.
	if !strings.Contains(string(m.Data), `"asks":[]`) {
		t.Fatalf("asks should encode as []: %s", m.Data)
	}

	h.Dispatch(service.Event{Type: "book", Symbol: "2222", Last: 2710})
	h.Dispatch(service.Event{Type: "book", Symbol: "1120", Last: 9500}) // not subscribed
	m = read(t, c)
	json.Unmarshal(m.Data, &b)
	if b.Symbol != "2222" || b.Last != 2710 {
		t.Fatalf("update = %s", m.Data)
	}
	expectNothing(t, c)
}

func TestTradesAndBatchedTicker(t *testing.T) {
	h, srv := setup(t)
	c := dial(t, srv)
	c.WriteJSON(map[string]any{"op": "sub", "ch": []string{"ticker", "trades:2222"}})
	waitSubs(t, h, 2)

	h.Dispatch(service.Event{Type: "trade", Symbol: "2222", Price: 2700, Qty: 10, TakerSide: "buy"})
	h.Dispatch(service.Event{Type: "trade", Symbol: "2222", Price: 2710, Qty: 5, TakerSide: "sell"})
	h.Dispatch(service.Event{Type: "trade", Symbol: "1120", Price: 9500, Qty: 1, TakerSide: "buy"})

	for _, want := range []int64{2700, 2710} {
		m := read(t, c)
		var tr tradeMsg
		json.Unmarshal(m.Data, &tr)
		if m.Ch != "trades:2222" || tr.Price != want || tr.At == 0 {
			t.Fatalf("trade = %+v %s", m, m.Data)
		}
	}
	// The ticker is held back until the flush: both symbols are pending.
	h.mu.Lock()
	pending := len(h.ticks)
	h.mu.Unlock()
	if pending != 2 {
		t.Fatalf("pending ticks = %d", pending)
	}

	h.FlushTicker()
	m := read(t, c)
	var ticks []tickMsg
	json.Unmarshal(m.Data, &ticks)
	if m.Ch != "ticker" || len(ticks) != 2 {
		t.Fatalf("ticker = %s", m.Data)
	}
	for _, tk := range ticks {
		if tk.Symbol == "2222" && (tk.Last != 2710 || tk.Qty != 15 || tk.Side != "sell") {
			t.Fatalf("2222 tick = %+v", tk)
		}
	}
	h.FlushTicker() // nothing new: no message
	expectNothing(t, c)
}

func TestOrderUpdatesArePrivate(t *testing.T) {
	h, srv := setup(t)
	owner, stranger, guest := dial(t, srv), dial(t, srv), dial(t, srv)

	owner.WriteJSON(map[string]any{"op": "auth", "token": "good"})
	if m := read(t, owner); m.Op != "auth" || !m.Ok || m.User != 7 {
		t.Fatalf("auth reply = %+v", m)
	}
	stranger.WriteJSON(map[string]any{"op": "auth", "token": "bad"})
	if m := read(t, stranger); m.Op != "auth" || m.Ok {
		t.Fatalf("bad token accepted: %+v", m)
	}
	// Nobody can subscribe to a user channel by name.
	stranger.WriteJSON(map[string]any{"op": "sub", "ch": []string{"user:7", "ticker"}})
	guest.WriteJSON(map[string]any{"op": "sub", "ch": []string{"user:7", "ticker"}})
	waitSubs(t, h, 3) // owner's user:7 + two "ticker" subscriptions

	h.Dispatch(service.Event{Type: "order", UserID: 7, OrderID: 42, Status: "filled", FilledQty: 10, Key: "k"})

	m := read(t, owner)
	var o orderMsg
	json.Unmarshal(m.Data, &o)
	if m.Ch != "user:7" || o.OrderID != 42 || o.Status != "filled" || o.Key != "k" {
		t.Fatalf("owner got %+v %s", m, m.Data)
	}
	expectNothing(t, stranger)
	expectNothing(t, guest)
}

func TestSlowClientIsDropped(t *testing.T) {
	h, srv := setup(t)
	c := dial(t, srv)
	c.WriteJSON(map[string]any{"op": "sub", "ch": []string{"book:2222"}})
	waitSubs(t, h, 1)

	// The client never reads. Large messages fill the socket and then the queue.
	big := make([]book.LevelView, 2000)
	for i := 0; i < 5000 && h.Clients() > 0; i++ {
		h.Dispatch(service.Event{Type: "book", Symbol: "2222", Last: int64(i), Bids: big})
	}
	if n := h.Clients(); n != 0 {
		t.Fatalf("slow client still connected (%d clients)", n)
	}
	// The hub keeps working for everyone else.
	c2 := dial(t, srv)
	c2.WriteJSON(map[string]any{"op": "sub", "ch": []string{"book:1120"}})
	waitSubs(t, h, 1)
	h.Dispatch(service.Event{Type: "book", Symbol: "1120", Last: 9500})
	if m := read(t, c2); m.Ch != "book:1120" {
		t.Fatalf("got %+v", m)
	}
}

func TestUnsubscribeAndBadChannels(t *testing.T) {
	h, srv := setup(t)
	c := dial(t, srv)
	c.WriteJSON(map[string]any{"op": "sub", "ch": []string{"trades:2222", "secret", "book:", "user:1"}})
	waitSubs(t, h, 1)
	h.mu.Lock()
	for cl := range h.clients {
		if len(cl.subs) != 1 || !cl.subs["trades:2222"] {
			t.Errorf("subs = %v", cl.subs)
		}
	}
	h.mu.Unlock()

	c.WriteJSON(map[string]any{"op": "unsub", "ch": []string{"trades:2222"}})
	c.WriteJSON(map[string]any{"op": "auth", "token": "bad"}) // a reply marks that unsub was processed
	read(t, c)
	h.Dispatch(service.Event{Type: "trade", Symbol: "2222", Price: 2700, Qty: 1, TakerSide: "buy"})
	expectNothing(t, c)
}
