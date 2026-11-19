// Command feed serves the live price feed over WebSocket. It reads the
// engine's events from the Redis stream "market:events" and fans them out.
//
//	ws://host:8090/ws
package main

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	_ "github.com/lib/pq"
	"github.com/redis/go-redis/v9"

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/feed"
	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/service"
)

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
	db.SetMaxOpenConns(4)

	opt, err := redis.ParseURL(env("REDIS_URL", "redis://localhost:6379/0"))
	if err != nil {
		log.Fatal(err)
	}
	rdb := redis.NewClient(opt)
	defer rdb.Close()

	hub := feed.NewHub()
	hub.Auth = func(token string) (int64, bool) { return checkToken(ctx, db, token) }

	// Replay what the engine already published so the first subscribers get
	// a depth snapshot for every symbol, then follow new events.
	last := "0"
	for {
		res, err := rdb.XRead(ctx, &redis.XReadArgs{Streams: []string{"market:events", last}, Count: 5000, Block: -1}).Result()
		if err == redis.Nil || len(res) == 0 || len(res[0].Messages) == 0 {
			break
		}
		if err != nil {
			log.Fatalf("replay: %v", err)
		}
		last = dispatch(hub, res[0].Messages, last)
	}
	hub.DropPendingTicks()
	log.Printf("feed replayed history up to %s", last)

	go func() {
		t := time.NewTicker(250 * time.Millisecond)
		defer t.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-t.C:
				hub.FlushTicker()
			}
		}
	}()

	go func() {
		for ctx.Err() == nil {
			res, err := rdb.XRead(ctx, &redis.XReadArgs{
				Streams: []string{"market:events", last}, Count: 1000, Block: time.Second}).Result()
			if err == redis.Nil {
				continue
			}
			if err != nil {
				if ctx.Err() == nil {
					log.Printf("read: %v", err)
					time.Sleep(time.Second)
				}
				continue
			}
			for _, st := range res {
				last = dispatch(hub, st.Messages, last)
			}
		}
	}()

	mux := http.NewServeMux()
	mux.Handle("/ws", hub)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte("ok " + strconv.Itoa(hub.Clients()) + " clients\n"))
	})
	srv := &http.Server{Addr: env("FEED_ADDR", ":8090"), Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	go func() {
		<-ctx.Done()
		shut, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		srv.Shutdown(shut)
	}()
	log.Printf("feed listening on %s", srv.Addr)
	if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatal(err)
	}
}

func dispatch(hub *feed.Hub, msgs []redis.XMessage, last string) string {
	for _, m := range msgs {
		last = m.ID
		raw, _ := m.Values["data"].(string)
		var ev service.Event
		if json.Unmarshal([]byte(raw), &ev) == nil {
			hub.Dispatch(ev)
		}
	}
	return last
}

// checkToken validates a Laravel Sanctum token ("<id>|<secret>"): the table
// stores the SHA-256 of the secret.
func checkToken(ctx context.Context, db *sql.DB, token string) (int64, bool) {
	id, secret, ok := strings.Cut(token, "|")
	tokenID, err := strconv.ParseInt(id, 10, 64)
	if !ok || err != nil || secret == "" {
		return 0, false
	}
	sum := sha256.Sum256([]byte(secret))
	var user int64
	err = db.QueryRowContext(ctx, `
		SELECT t.tokenable_id FROM personal_access_tokens t
		JOIN users u ON u.id = t.tokenable_id
		WHERE t.id = $1 AND t.token = $2 AND u.status = 'active'
		  AND (t.expires_at IS NULL OR t.expires_at > now())`,
		tokenID, hex.EncodeToString(sum[:])).Scan(&user)
	return user, err == nil
}
