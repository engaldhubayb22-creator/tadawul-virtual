// Command bot is the first, simple market maker. It runs two accounts:
//
//	mm-bot     keeps five bid and five ask levels around a drifting mid price
//	noise-bot  sends small random market orders that trade against those quotes
//
// Both send ordinary commands through the same queue as real users. This
// version requotes every symbol on every cycle, which is fine for a handful
// of symbols; the per-symbol scenarios from the admin panel come later.
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

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/service"
)

type sym struct {
	id              int64
	symbol          string
	tick, low, high int64
	mid             int64
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

	mids := map[int64]int64{}
	cancelled := map[int64]bool{} // cancels already sent, so a slow engine is not flooded
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
		syms, err := loadSymbols(ctx, db)
		if err != nil {
			log.Printf("load symbols: %v", err)
			continue
		}
		for _, s := range syms {
			mid, ok := mids[s.id]
			if !ok {
				mid = s.mid
			}
			step := s.tick
			if p := mid / 1000 / s.tick * s.tick; p > step {
				step = p // about 0.1% of the price, on the tick grid
			}
			// Random walk, kept away from the daily limits so quotes stay valid.
			mid += int64(rng.Intn(5)-2) * step
			if lo := s.low + 6*step; mid < lo {
				mid = lo
			}
			if hi := s.high - 6*step; mid > hi {
				mid = hi
			}
			mid = mid / s.tick * s.tick
			if old, seen := mids[s.id]; seen && old == mid {
				continue // price did not move: keep the current quotes
			}
			mids[s.id] = mid

			// Pull the old quotes, then post fresh ones around the new mid.
			rows, err := db.QueryContext(ctx, `
				SELECT id FROM orders
				WHERE user_id = $1 AND company_id = $2 AND status IN ('open', 'partial')`, mm, s.id)
			if err != nil {
				log.Printf("open orders: %v", err)
				continue
			}
			for rows.Next() {
				var id int64
				if rows.Scan(&id) == nil && !cancelled[id] {
					cancelled[id] = true
					send(service.Command{Type: "cancel", UserID: mm, OrderID: id})
				}
			}
			rows.Close()
			for k := int64(1); k <= 5; k++ {
				send(service.Command{Type: "new", UserID: mm, Symbol: s.symbol, Side: "buy", Kind: "limit",
					Price: mid - k*step, Qty: int64(100 + rng.Intn(1900))})
				send(service.Command{Type: "new", UserID: mm, Symbol: s.symbol, Side: "sell", Kind: "limit",
					Price: mid + k*step, Qty: int64(100 + rng.Intn(1900))})
			}
			if rng.Intn(10) < 7 {
				side := "buy"
				if rng.Intn(2) == 0 {
					side = "sell"
				}
				send(service.Command{Type: "new", UserID: noise, Symbol: s.symbol, Side: side, Kind: "market",
					Qty: int64(10 + rng.Intn(490))})
			}
		}
	}
}

// loadSymbols reads the tradable companies with today's limits. The starting
// mid is the last trade price, or the previous close before any trade.
func loadSymbols(ctx context.Context, db *sql.DB) ([]sym, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT c.id, c.symbol, c.tick_size, d.low_limit, d.high_limit,
		       COALESCE((SELECT t.price FROM trades t WHERE t.company_id = c.id ORDER BY t.id DESC LIMIT 1),
		                d.prev_close)
		FROM companies c
		JOIN daily_stats d ON d.company_id = c.id
		 AND d.trade_date = (SELECT max(trade_date) FROM daily_stats)
		WHERE c.status = 'trading'
		ORDER BY c.symbol`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []sym
	for rows.Next() {
		var s sym
		if err := rows.Scan(&s.id, &s.symbol, &s.tick, &s.low, &s.high, &s.mid); err != nil {
			return nil, err
		}
		out = append(out, s)
	}
	return out, rows.Err()
}
