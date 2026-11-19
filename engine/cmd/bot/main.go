// Command bot runs the market maker. It uses two accounts:
//
//	mm-bot     keeps a ladder of bids and asks around a moving mid price
//	noise-bot  sends small random market orders that trade against the ladder
//
// Both send ordinary commands through the same queue as real users. Every
// cycle the bot re-reads its settings from the database, so changes made in
// the admin panel (pause, spread, sizes, a price scenario) apply within one
// cycle. The decisions themselves live in package maker.
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"math/rand"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	_ "github.com/lib/pq"
	"github.com/redis/go-redis/v9"

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/book"
	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/maker"
	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/service"
)

type sym struct {
	id        int64
	symbol    string
	market    maker.Market // Mid here is the last trade price or previous close
	cfg       maker.Config
	inventory int64
}

func env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	db, err := sql.Open("postgres", env("DATABASE_URL",
		"postgres://tadawul:tadawul_dev@localhost:5432/tadawul?sslmode=disable"))
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()
	opt, err := redis.ParseURL(env("REDIS_URL", "redis://localhost:6379/0"))
	if err != nil {
		log.Fatal(err)
	}
	rdb := redis.NewClient(opt)
	defer rdb.Close()
	interval, err := strconv.Atoi(env("BOT_INTERVAL_MS", "1000"))
	if err != nil || interval < 50 {
		log.Fatal("BOT_INTERVAL_MS must be a number >= 50")
	}

	var mm, noise int64
	if err := db.QueryRowContext(ctx, `SELECT id FROM users WHERE alias = 'mm-bot'`).Scan(&mm); err != nil {
		log.Fatalf("mm-bot user (run seed first): %v", err)
	}
	if err := db.QueryRowContext(ctx, `SELECT id FROM users WHERE alias = 'noise-bot'`).Scan(&noise); err != nil {
		log.Fatalf("noise-bot user (run seed first): %v", err)
	}

	rng := rand.New(rand.NewSource(time.Now().UnixNano()))
	seq := 0
	send := func(c service.Command) {
		if c.Type == "new" {
			seq++
			c.Key = fmt.Sprintf("bot-%d-%d-%d", c.UserID, time.Now().UnixNano(), seq)
		}
		b, _ := json.Marshal(c)
		if err := rdb.XAdd(ctx, &redis.XAddArgs{Stream: "orders:in",
			Values: map[string]any{"data": string(b)}}).Err(); err != nil && ctx.Err() == nil {
			log.Printf("send: %v", err)
		}
	}

	mids := map[int64]int64{}     // the maker's own mid per company
	base := map[int64]int64{}     // inventory when the bot first saw the company
	quoting := map[int64]bool{}   // companies with a live ladder
	cancelled := map[int64]bool{} // cancels already sent, so a slow engine is not flooded

	// pull cancels the maker's resting orders for one company.
	pull := func(companyID int64) {
		rows, err := db.QueryContext(ctx, `
			SELECT id FROM orders
			WHERE user_id = $1 AND company_id = $2 AND status IN ('open', 'partial')`, mm, companyID)
		if err != nil {
			log.Printf("open orders: %v", err)
			return
		}
		defer rows.Close()
		for rows.Next() {
			var id int64
			if rows.Scan(&id) == nil && !cancelled[id] {
				cancelled[id] = true
				send(service.Command{Type: "cancel", UserID: mm, OrderID: id})
			}
		}
	}

	skipped := 0
	ticker := time.NewTicker(time.Duration(interval) * time.Millisecond)
	defer ticker.Stop()
	log.Printf("bot running, cycle every %d ms", interval)

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		if len(cancelled) > 100000 {
			cancelled = map[int64]bool{}
		}
		// Back-pressure: if the engine has not caught up with what was already
		// sent, sending more only builds a queue of stale quotes.
		if lag := queueLag(ctx, rdb); lag > maxLag {
			if skipped++; skipped%20 == 1 {
				log.Printf("engine is %d commands behind, waiting", lag)
			}
			continue
		}
		on, syms, err := load(ctx, db, mm)
		if err != nil {
			log.Printf("load: %v", err)
			continue
		}
		for _, s := range syms {
			if _, ok := base[s.id]; !ok {
				base[s.id] = s.inventory
			}
			if !on || !s.cfg.Enabled {
				// Paused globally or for this company: keep pulling on every
				// cycle, because orders sent just before the pause may still
				// be on their way into the book.
				pull(s.id)
				quoting[s.id] = false
				delete(mids, s.id)
				continue
			}
			m := s.market
			m.Inventory, m.BaseInventory = s.inventory, base[s.id]
			old, seen := mids[s.id]
			if seen {
				m.Mid = old
			}
			// The price step follows the price band the stock is trading in.
			m.Tick = book.BandTick(m.Mid)
			mid, _ := book.NearestTicks(maker.NextMid(m, s.cfg, rng))
			mids[s.id] = mid

			if !seen || mid != old || !quoting[s.id] {
				pull(s.id)
				bids, asks := maker.Quotes(m, mid, s.cfg, rng)
				// Near a band edge a level can fall between two valid prices:
				// bids move down to the grid and asks move up, never inward.
				for _, q := range bids {
					price, _ := book.NearestTicks(q.Price)
					if price >= s.market.Low {
						send(service.Command{Type: "new", UserID: mm, Symbol: s.symbol, Side: "buy", Kind: "limit", Price: price, Qty: q.Qty})
					}
				}
				for _, q := range asks {
					_, price := book.NearestTicks(q.Price)
					if price <= s.market.High {
						send(service.Command{Type: "new", UserID: mm, Symbol: s.symbol, Side: "sell", Kind: "limit", Price: price, Qty: q.Qty})
					}
				}
				quoting[s.id] = true
			}
			if rng.Intn(100) < s.cfg.NoisePct {
				side := "buy"
				if rng.Intn(2) == 0 {
					side = "sell"
				}
				send(service.Command{Type: "new", UserID: noise, Symbol: s.symbol, Side: side, Kind: "market",
					Qty: 10 + rng.Int63n(s.cfg.QtyMin+1)})
			}
		}
	}
}

// maxLag is how many unprocessed commands the bot tolerates before it waits.
const maxLag = 300

// queueLag returns how many commands the engine has not read yet.
func queueLag(ctx context.Context, rdb *redis.Client) int64 {
	groups, err := rdb.XInfoGroups(ctx, "orders:in").Result()
	if err != nil {
		return 0
	}
	for _, g := range groups {
		if g.Name == "engine" {
			return g.Lag + g.Pending
		}
	}
	return 0
}

// load reads the global switch and, for every tradable company, today's
// limits, the reference price, the maker's inventory and its settings.
func load(ctx context.Context, db *sql.DB, mm int64) (bool, []sym, error) {
	on := true
	var raw []byte
	err := db.QueryRowContext(ctx, `SELECT value FROM settings WHERE key = 'bots_enabled'`).Scan(&raw)
	if err == nil {
		json.Unmarshal(raw, &on)
	} else if err != sql.ErrNoRows {
		return false, nil, err
	}

	d := maker.DefaultConfig()
	rows, err := db.QueryContext(ctx, `
		SELECT c.id, c.symbol, c.tick_size, d.low_limit, d.high_limit,
		       COALESCE((SELECT t.price FROM trades t WHERE t.company_id = c.id ORDER BY t.id DESC LIMIT 1),
		                d.prev_close),
		       COALESCE(p.qty, 0),
		       COALESCE(b.enabled, $2), COALESCE(b.spread_bps, $3), COALESCE(b.levels, $4),
		       COALESCE(b.qty_min, $5), COALESCE(b.qty_max, $6), COALESCE(b.noise_pct, $7),
		       CASE WHEN b.target_until > now() THEN COALESCE(b.target_price, 0) ELSE 0 END
		FROM companies c
		JOIN daily_stats d ON d.company_id = c.id
		 AND d.trade_date = (SELECT max(trade_date) FROM daily_stats)
		LEFT JOIN positions p ON p.company_id = c.id AND p.user_id = $1
		LEFT JOIN bot_configs b ON b.company_id = c.id
		WHERE c.status = 'trading'
		ORDER BY c.symbol`,
		mm, d.Enabled, d.SpreadBps, d.Levels, d.QtyMin, d.QtyMax, d.NoisePct)
	if err != nil {
		return false, nil, err
	}
	defer rows.Close()
	var out []sym
	for rows.Next() {
		var s sym
		if err := rows.Scan(&s.id, &s.symbol, &s.market.Tick, &s.market.Low, &s.market.High, &s.market.Mid,
			&s.inventory, &s.cfg.Enabled, &s.cfg.SpreadBps, &s.cfg.Levels, &s.cfg.QtyMin, &s.cfg.QtyMax,
			&s.cfg.NoisePct, &s.cfg.Target); err != nil {
			return false, nil, err
		}
		out = append(out, s)
	}
	return on, out, rows.Err()
}
