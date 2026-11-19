// Command engine reads order commands from a Redis stream, applies them
// through the matching engine, and publishes the resulting events.
//
//	orders:in      stream of commands   (field "data" = JSON service.Command)
//	market:events  stream of events     (field "data" = JSON service.Event)
//	orders:dead    commands that kept failing, with the error
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

	deadStream  = "orders:dead"
	maxAttempts = 5
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
			Streams: []string{inStream, cursor}, Count: 500, Block: time.Second,
		}).Result()
		if err == redis.Nil {
			// Idle: still apply admin changes and roll the day on time.
			if terr := eng.Tick(ctx); terr != nil && ctx.Err() == nil {
				log.Printf("tick: %v", terr)
			}
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
			n += len(st.Messages)
			handleBatch(ctx, rdb, eng, st.Messages)
		}
		if cursor == "0" && n == 0 {
			cursor = ">"
		}
	}
	log.Print("engine stopped")
}

// handleBatch applies everything that was waiting in the queue in a single
// transaction. If the batch fails it falls back to one command at a time, so
// a single bad command cannot hold back the rest.
func handleBatch(ctx context.Context, rdb *redis.Client, eng *service.Engine, msgs []redis.XMessage) {
	cmds := make([]service.Command, 0, len(msgs))
	valid := make([]redis.XMessage, 0, len(msgs))
	ids := make([]string, 0, len(msgs))
	for _, msg := range msgs {
		raw, _ := msg.Values["data"].(string)
		var cmd service.Command
		if err := json.Unmarshal([]byte(raw), &cmd); err != nil {
			log.Printf("skip malformed command %s: %v", msg.ID, err)
			rdb.XAck(ctx, inStream, group, msg.ID)
			continue
		}
		cmds = append(cmds, cmd)
		valid = append(valid, msg)
		ids = append(ids, msg.ID)
	}
	if len(cmds) == 0 {
		return
	}
	events, err := eng.ProcessBatch(ctx, cmds)
	if err == nil {
		publish(ctx, rdb, events)
		rdb.XAck(ctx, inStream, group, ids...)
		return
	}
	if ctx.Err() != nil {
		return
	}
	log.Printf("batch of %d failed, applying one by one: %v", len(cmds), err)
	for i, cmd := range cmds {
		handleOne(ctx, rdb, eng, valid[i], cmd)
	}
}

// handleOne retries a command a few times, then parks it on the dead-letter
// stream so the queue keeps moving. Duplicate delivery is safe: the
// idempotency key makes a repeat a no-op.
func handleOne(ctx context.Context, rdb *redis.Client, eng *service.Engine, msg redis.XMessage, cmd service.Command) {
	var err error
	for attempt := 1; attempt <= maxAttempts; attempt++ {
		var events []service.Event
		events, err = eng.Process(ctx, cmd)
		if err == nil {
			publish(ctx, rdb, events)
			rdb.XAck(ctx, inStream, group, msg.ID)
			return
		}
		if ctx.Err() != nil {
			return
		}
		log.Printf("command %s attempt %d failed: %v", msg.ID, attempt, err)
		time.Sleep(time.Duration(attempt) * 500 * time.Millisecond)
	}
	log.Printf("command %s moved to %s after %d attempts", msg.ID, deadStream, maxAttempts)
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: deadStream, Values: map[string]any{
		"data": msg.Values["data"], "error": err.Error(), "source_id": msg.ID}})
	rdb.XAck(ctx, inStream, group, msg.ID)
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
