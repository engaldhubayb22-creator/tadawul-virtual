// Command seed loads a starter set of companies and the two market-maker
// bot accounts. It is safe to run more than once.
//
// The listing prices and share counts below are placeholders for a virtual
// market. They are not real market data.
package main

import (
	"database/sql"
	"log"
	"os"

	_ "github.com/lib/pq"
)

type co struct {
	symbol, name, sector string
	price, tick          int64 // halalas
}

var companies = []co{
	{"2222", "أرامكو السعودية", "الطاقة", 2700, 1},
	{"1120", "مصرف الراجحي", "البنوك", 9500, 5},
	{"1180", "البنك الأهلي السعودي", "البنوك", 3600, 5},
	{"1150", "مصرف الإنماء", "البنوك", 2800, 5},
	{"2010", "سابك", "المواد الأساسية", 6500, 5},
	{"1211", "معادن", "المواد الأساسية", 5000, 5},
	{"7010", "اس تي سي", "الاتصالات", 4200, 5},
	{"2280", "المراعي", "إنتاج الأغذية", 5500, 5},
	{"4190", "جرير", "التجزئة", 1300, 1},
	{"4030", "البحري", "النقل", 2500, 1},
}

type bot struct {
	alias  string
	cash   int64 // halalas
	shares int64 // per company
}

var bots = []bot{
	{"mm-bot", 1_000_000_000_000, 10_000_000}, // liquidity provider
	{"noise-bot", 100_000_000_000, 1_000_000}, // random small trades
}

func main() {
	url := os.Getenv("DATABASE_URL")
	if url == "" {
		url = "postgres://tadawul:tadawul_dev@localhost:5432/tadawul?sslmode=disable"
	}
	db, err := sql.Open("postgres", url)
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()

	tx, err := db.Begin()
	if err != nil {
		log.Fatal(err)
	}
	defer tx.Rollback()
	must := func(_ sql.Result, err error) {
		if err != nil {
			log.Fatal(err)
		}
	}

	for _, c := range companies {
		must(tx.Exec(`INSERT INTO sectors (name) VALUES ($1) ON CONFLICT DO NOTHING`, c.sector))
		must(tx.Exec(`
			INSERT INTO companies (symbol, name, sector_id, shares_outstanding, tick_size, ref_price)
			SELECT $1, $2, s.id, 1000000000, $4, $5 FROM sectors s WHERE s.name = $3
			ON CONFLICT (symbol) DO NOTHING`, c.symbol, c.name, c.sector, c.tick, c.price))
	}

	var treasury int64
	if err := tx.QueryRow(`SELECT id FROM ledger_accounts WHERE kind = 'sys_treasury'`).Scan(&treasury); err != nil {
		log.Fatal(err)
	}
	for _, b := range bots {
		must(tx.Exec(`
			INSERT INTO users (name, alias, mobile, password, role)
			VALUES ($1, $1, $1, '!', 'bot') ON CONFLICT (alias) DO NOTHING`, b.alias))
		var uid int64
		if err := tx.QueryRow(`SELECT id FROM users WHERE alias = $1`, b.alias).Scan(&uid); err != nil {
			log.Fatal(err)
		}
		res, err := tx.Exec(`
			INSERT INTO ledger_accounts (user_id, kind) VALUES ($1, 'cash')
			ON CONFLICT (user_id, kind) DO NOTHING`, uid)
		must(res, err)
		if n, _ := res.RowsAffected(); n == 1 { // new account: fund it once
			var cash int64
			if err := tx.QueryRow(`SELECT id FROM ledger_accounts WHERE user_id = $1 AND kind = 'cash'`,
				uid).Scan(&cash); err != nil {
				log.Fatal(err)
			}
			must(tx.Exec(`UPDATE ledger_accounts SET balance = balance - $1 WHERE id = $2`, b.cash, treasury))
			must(tx.Exec(`UPDATE ledger_accounts SET balance = balance + $1 WHERE id = $2`, b.cash, cash))
			must(tx.Exec(`
				INSERT INTO ledger_entries (debit_account_id, credit_account_id, amount, ref_type, ref_id)
				VALUES ($1, $2, $3, 'bot_funding', $4)`, treasury, cash, b.cash, uid))
		}
		must(tx.Exec(`
			INSERT INTO positions (user_id, company_id, qty, cost_total)
			SELECT $1, c.id, $2, $2 * c.ref_price FROM companies c
			ON CONFLICT (user_id, company_id) DO NOTHING`, uid, b.shares))
	}
	if err := tx.Commit(); err != nil {
		log.Fatal(err)
	}
	log.Printf("seeded %d companies and %d bots", len(companies), len(bots))
}
