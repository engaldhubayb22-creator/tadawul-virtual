// Command engine reads order commands from a Redis stream, applies them
// through the matching engine, and publishes the resulting events.
//
//	orders:in      stream of commands   (field "data" = JSON service.Command)
//	market:events  stream of events     (field "data" = JSON service.Event)
package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"log"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	_ "github.com/lib/pq"
	"github.com/redis/go-redis/v9"

	"github.com/engaldhubayb22-creator/tadawul-virtual/engine/service"
)

const (
	inStream  = "orders:in"
	outStream = "market:events"
	group     = "engine"
	consumer  = "engine-1"
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

	eng := service.New(db)
	if err := eng.Load(ctx); err != nil {
		log.Fatalf("load: %v", err)
	}
	log.Printf("engine loaded %d symbols", len(eng.Symbols()))

	err = rdb.XGroupCreateMkStream(ctx, inStream, group, "0").Err()
	if err != nil && !strings.HasPrefix(err.Error(), "BUSYGROUP") {
		log.Fatalf("create consumer group: %v", err)
	}

	// Publish a snapshot of every book so late subscribers have a baseline.
	for _, s := range eng.Symbols() {
		if ev, ok := eng.Snapshot(s); ok {
			publish(ctx, rdb, []service.Event{ev})
		}
	}

	// First re-deliver anything read but not acknowledged before a crash
	// ("0"), then switch to new messages (">").
	cursor := "0"
	for ctx.Err() == nil {
		res, err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{
			Group: group, Consumer: consumer,
			Streams: []string{inStream, cursor}, Count: 200, Block: time.Second,
		}).Result()
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
		n := 0
		for _, st := range res {
			for _, msg := range st.Messages {
				n++
				handle(ctx, rdb, eng, msg)
			}
		}
		if cursor == "0" && n == 0 {
			cursor = ">"
		}
	}
	log.Print("engine stopped")
}

func handle(ctx context.Context, rdb *redis.Client, eng *service.Engine, msg redis.XMessage) {
	raw, _ := msg.Values["data"].(string)
	var cmd service.Command
	if err := json.Unmarshal([]byte(raw), &cmd); err != nil {
		log.Printf("skip malformed command %s: %v", msg.ID, err)
		rdb.XAck(ctx, inStream, group, msg.ID)
		return
	}
	// Commands must be applied in order, so a failing one blocks the queue
	// and is retried until the database is reachable again. Duplicate
	// delivery is safe: the idempotency key makes a repeat a no-op.
	for {
		events, err := eng.Process(ctx, cmd)
		if err == nil {
			publish(ctx, rdb, events)
			rdb.XAck(ctx, inStream, group, msg.ID)
			return
		}
		if ctx.Err() != nil {
			return
		}
		log.Printf("command %s failed, retrying: %v", msg.ID, err)
		time.Sleep(time.Second)
	}
}

func publish(ctx context.Context, rdb *redis.Client, events []service.Event) {
	if len(events) == 0 {
		return
	}
	pipe := rdb.Pipeline()
	for _, ev := range events {
		b, err := json.Marshal(ev)
		if err != nil {
			continue
		}
		pipe.XAdd(ctx, &redis.XAddArgs{Stream: outStream, MaxLen: 200000, Approx: true,
			Values: map[string]any{"data": string(b)}})
	}
	if _, err := pipe.Exec(ctx); err != nil && ctx.Err() == nil {
		log.Printf("publish: %v", err)
	}
}
