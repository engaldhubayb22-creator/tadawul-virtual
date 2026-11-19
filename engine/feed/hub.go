// Package feed is the live price feed: it takes the engine's events and
// pushes them to browsers and mobile apps over WebSocket.
//
// Protocol (JSON text frames):
//
//	client: {"op":"auth","token":"<api token>"}        optional, enables private data
//	client: {"op":"sub","ch":["ticker","book:2222","trades:2222"]}
//	client: {"op":"unsub","ch":["book:2222"]}
//	server: {"ch":"book:2222","data":{...}}
//
// Channels:
//
//	ticker         last price of every symbol that traded, batched every 250 ms
//	book:<symbol>  full top-of-book depth, sent whole on every change and on subscribe
//	trades:<symbol> one message per trade
//	user:<id>      the signed-in user's own order updates (joined automatically on auth)
//
// A depth message is always the complete picture, so a client that misses one
// is correct again on the next; nothing has to be replayed.
package feed

import (
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/book"
	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/service"
)

const (
	sendBuffer  = 256
	maxSubs     = 300
	pingEvery   = 25 * time.Second
	readTimeout = 60 * time.Second
	writeWait   = 5 * time.Second
)

type bookMsg struct {
	Symbol string           `json:"symbol"`
	Last   int64            `json:"last"`
	Bids   []book.LevelView `json:"bids"`
	Asks   []book.LevelView `json:"asks"`
}

type tradeMsg struct {
	Symbol    string `json:"symbol"`
	Price     int64  `json:"price"`
	Qty       int64  `json:"qty"`
	TakerSide string `json:"taker_side"`
	At        int64  `json:"at"` // unix milliseconds
}

type tickMsg struct {
	Symbol string `json:"symbol"`
	Last   int64  `json:"last"`
	Qty    int64  `json:"qty"` // shares traded since the previous ticker message
	Side   string `json:"side"`
}

type orderMsg struct {
	OrderID   int64  `json:"order_id"`
	Symbol    string `json:"symbol,omitempty"`
	Key       string `json:"key,omitempty"`
	Status    string `json:"status"`
	Reason    string `json:"reason,omitempty"`
	FilledQty int64  `json:"filled_qty"`
}

type envelope struct {
	Ch   string `json:"ch"`
	Data any    `json:"data"`
}

type client struct {
	conn *websocket.Conn
	send chan []byte
	subs map[string]bool // guarded by Hub.mu
	user int64           // guarded by Hub.mu
}

// Hub holds the connected clients and the latest state per symbol.
type Hub struct {
	// Auth resolves an API token to a user id. Nil disables private channels.
	Auth func(token string) (int64, bool)
	Now  func() time.Time

	mu      sync.Mutex
	clients map[*client]struct{}
	books   map[string][]byte   // last encoded depth message per symbol
	ticks   map[string]*tickMsg // trades since the last ticker flush
	upgrade websocket.Upgrader
}

func NewHub() *Hub {
	return &Hub{
		Now:     time.Now,
		clients: map[*client]struct{}{},
		books:   map[string][]byte{},
		ticks:   map[string]*tickMsg{},
		upgrade: websocket.Upgrader{
			ReadBufferSize: 1024, WriteBufferSize: 4096,
			// Auth is a token in a message, not a cookie, so another site
			// cannot ride a visitor's session by opening this socket.
			CheckOrigin: func(*http.Request) bool { return true },
		},
	}
}

func encode(ch string, data any) []byte {
	b, _ := json.Marshal(envelope{Ch: ch, Data: data})
	return b
}

// Dispatch routes one engine event to the clients subscribed to it.
func (h *Hub) Dispatch(ev service.Event) {
	h.mu.Lock()
	defer h.mu.Unlock()
	switch ev.Type {
	case "book":
		bids, asks := ev.Bids, ev.Asks
		if bids == nil {
			bids = []book.LevelView{}
		}
		if asks == nil {
			asks = []book.LevelView{}
		}
		msg := encode("book:"+ev.Symbol, bookMsg{Symbol: ev.Symbol, Last: ev.Last, Bids: bids, Asks: asks})
		h.books[ev.Symbol] = msg
		h.broadcast("book:"+ev.Symbol, msg)
	case "trade":
		h.broadcast("trades:"+ev.Symbol, encode("trades:"+ev.Symbol, tradeMsg{
			Symbol: ev.Symbol, Price: ev.Price, Qty: ev.Qty, TakerSide: ev.TakerSide,
			At: h.Now().UnixMilli()}))
		t := h.ticks[ev.Symbol]
		if t == nil {
			t = &tickMsg{Symbol: ev.Symbol}
			h.ticks[ev.Symbol] = t
		}
		t.Last, t.Side = ev.Price, ev.TakerSide
		t.Qty += ev.Qty
	case "order":
		if ev.UserID <= 0 {
			return
		}
		ch := "user:" + strconv.FormatInt(ev.UserID, 10)
		h.broadcast(ch, encode(ch, orderMsg{OrderID: ev.OrderID, Symbol: ev.Symbol, Key: ev.Key,
			Status: ev.Status, Reason: ev.Reason, FilledQty: ev.FilledQty}))
	}
}

// FlushTicker sends one batched message with every symbol that traded since
// the last flush. Call it on a timer.
func (h *Hub) FlushTicker() {
	h.mu.Lock()
	defer h.mu.Unlock()
	if len(h.ticks) == 0 {
		return
	}
	batch := make([]*tickMsg, 0, len(h.ticks))
	for _, t := range h.ticks {
		batch = append(batch, t)
	}
	h.ticks = map[string]*tickMsg{}
	h.broadcast("ticker", encode("ticker", batch))
}

// DropPendingTicks forgets trades collected while replaying history.
func (h *Hub) DropPendingTicks() {
	h.mu.Lock()
	h.ticks = map[string]*tickMsg{}
	h.mu.Unlock()
}

// broadcast must be called with h.mu held.
func (h *Hub) broadcast(ch string, msg []byte) {
	for c := range h.clients {
		if c.subs[ch] {
			h.push(c, msg)
		}
	}
}

// push queues a message; a client too slow to keep up is disconnected and
// will resync when it reconnects. Must be called with h.mu held.
func (h *Hub) push(c *client, msg []byte) {
	select {
	case c.send <- msg:
	default:
		h.remove(c)
	}
}

// remove must be called with h.mu held.
func (h *Hub) remove(c *client) {
	if _, ok := h.clients[c]; ok {
		delete(h.clients, c)
		close(c.send)
	}
}

// Clients returns the number of open connections.
func (h *Hub) Clients() int {
	h.mu.Lock()
	defer h.mu.Unlock()
	return len(h.clients)
}

func publicChannel(ch string) bool {
	if ch == "ticker" {
		return true
	}
	for _, p := range []string{"book:", "trades:"} {
		if strings.HasPrefix(ch, p) && len(ch) > len(p) && len(ch) <= len(p)+12 {
			return true
		}
	}
	return false
}

type request struct {
	Op    string   `json:"op"`
	Ch    []string `json:"ch"`
	Token string   `json:"token"`
}

func (h *Hub) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	conn, err := h.upgrade.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	c := &client{conn: conn, send: make(chan []byte, sendBuffer), subs: map[string]bool{}}
	h.mu.Lock()
	h.clients[c] = struct{}{}
	h.mu.Unlock()

	go c.writeLoop()

	conn.SetReadLimit(4096)
	conn.SetReadDeadline(time.Now().Add(readTimeout))
	conn.SetPongHandler(func(string) error {
		return conn.SetReadDeadline(time.Now().Add(readTimeout))
	})
	for {
		var req request
		if err := conn.ReadJSON(&req); err != nil {
			break
		}
		conn.SetReadDeadline(time.Now().Add(readTimeout))
		h.handle(c, req)
	}
	h.mu.Lock()
	h.remove(c)
	h.mu.Unlock()
}

func (h *Hub) handle(c *client, req request) {
	switch req.Op {
	case "auth":
		var user int64
		ok := false
		if h.Auth != nil && req.Token != "" {
			user, ok = h.Auth(req.Token) // database lookup: done outside the lock
		}
		h.mu.Lock()
		defer h.mu.Unlock()
		if _, alive := h.clients[c]; !alive {
			return
		}
		if ok {
			if c.user != 0 {
				delete(c.subs, "user:"+strconv.FormatInt(c.user, 10))
			}
			c.user = user
			c.subs["user:"+strconv.FormatInt(user, 10)] = true
		}
		b, _ := json.Marshal(map[string]any{"op": "auth", "ok": ok, "user": user})
		h.push(c, b)
	case "sub":
		h.mu.Lock()
		defer h.mu.Unlock()
		if _, alive := h.clients[c]; !alive {
			return
		}
		for _, ch := range req.Ch {
			if !publicChannel(ch) || len(c.subs) >= maxSubs {
				continue
			}
			c.subs[ch] = true
			if strings.HasPrefix(ch, "book:") {
				if snap, ok := h.books[strings.TrimPrefix(ch, "book:")]; ok {
					h.push(c, snap)
					if _, alive := h.clients[c]; !alive {
						return
					}
				}
			}
		}
	case "unsub":
		h.mu.Lock()
		defer h.mu.Unlock()
		for _, ch := range req.Ch {
			if publicChannel(ch) {
				delete(c.subs, ch)
			}
		}
	}
}

func (c *client) writeLoop() {
	ping := time.NewTicker(pingEvery)
	defer ping.Stop()
	defer c.conn.Close()
	for {
		select {
		case msg, ok := <-c.send:
			c.conn.SetWriteDeadline(time.Now().Add(writeWait))
			if !ok {
				c.conn.WriteMessage(websocket.CloseMessage, nil)
				return
			}
			if err := c.conn.WriteMessage(websocket.TextMessage, msg); err != nil {
				return
			}
		case <-ping.C:
			c.conn.SetWriteDeadline(time.Now().Add(writeWait))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
