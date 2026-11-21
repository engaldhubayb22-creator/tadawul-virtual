import { readFileSync } from 'node:fs'
const API = 'http://127.0.0.1:8000/api/v1', WS = 'ws://127.0.0.1:8090/ws'
const SECONDS = +process.env.SECONDS || 60
const users = readFileSync('/tmp/load/tokens.txt', 'utf8').split('\n').filter((l) => l.startsWith('TOK ')).map((l) => { const [, id, token] = l.split(' '); return { id: +id, token } })
const tick = (p) => (p < 2500 ? 1 : p < 5000 ? 2 : p < 10000 ? 5 : p < 25000 ? 10 : p < 50000 ? 20 : 50)
const market = await (await fetch(API + '/market')).json()
const last = {}; for (const c of market.companies) if (c.status === 'trading' && c.last) last[c.symbol] = c.last
const symbols = Object.keys(last)
const stat = { sent: 0, http: [], e2e: [], httpFail: {}, status: {}, reason: {}, ticker: 0, wsOpen: 0, wsClosed: 0, noEvent: 0, portfolio: [], portfolioFail: 0 }
const pending = new Map()
const rnd = (a, b) => a + Math.random() * (b - a), pick = (a) => a[Math.floor(Math.random() * a.length)]
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const end = Date.now() + SECONDS * 1000

async function trader(u, n) {
  const owned = {}, filledOf = {}
  const ws = new WebSocket(WS)
  ws.onopen = () => { stat.wsOpen++; ws.send(JSON.stringify({ op: 'auth', token: u.token })); ws.send(JSON.stringify({ op: 'sub', ch: ['ticker', 'book:' + pick(symbols), 'trades:' + pick(symbols)] })) }
  ws.onclose = () => { if (Date.now() < end) stat.wsClosed++ }
  ws.onmessage = (e) => {
    const m = JSON.parse(e.data)
    if (m.ch === 'ticker') { stat.ticker++; for (const t of m.data) last[t.symbol] = t.last; return }
    if (!m.ch?.startsWith('user:')) return
    const o = m.data
    if (o.key && pending.has(o.key)) {
      const p = pending.get(o.key); pending.delete(o.key)
      stat.e2e.push(Date.now() - p.at); stat.status[o.status] = (stat.status[o.status] || 0) + 1
      if (o.reason) stat.reason[o.reason] = (stat.reason[o.reason] || 0) + 1
      if (o.order_id) filledOf[o.order_id] = { side: p.side, symbol: p.symbol, filled: 0 }
    }
    const f = filledOf[o.order_id]
    if (f && o.filled_qty > f.filled) { const d = o.filled_qty - f.filled; f.filled = o.filled_qty; owned[f.symbol] = (owned[f.symbol] || 0) + (f.side === 'buy' ? d : -d) }
  }
  await sleep(rnd(0, 3000))
  const H = { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + u.token }
  let i = 0, nextPortfolio = Date.now() + rnd(3000, 10000)
  while (Date.now() < end) {
    const have = Object.entries(owned).filter(([, q]) => q > 0)
    let body
    const r = Math.random()
    if (have.length && r < 0.3) { const [symbol, q] = pick(have); body = { symbol, side: 'sell', kind: 'market', qty: Math.max(1, Math.floor(q / 2)) } }
    else {
      const symbol = pick(symbols), p = last[symbol], qty = Math.max(1, Math.floor(rnd(200000, 1500000) / p))
      if (r < 0.65) body = { symbol, side: 'buy', kind: 'market', qty }
      else { const t = tick(p); body = { symbol, side: 'buy', kind: 'limit', price: Math.floor(p / t) * t - t * Math.floor(rnd(0, 3)), qty } }
    }
    body.key = `L${u.id}-${++i}`
    const at = Date.now(); pending.set(body.key, { at, side: body.side, symbol: body.symbol }); stat.sent++
    try {
      const res = await fetch(API + '/orders', { method: 'POST', headers: H, body: JSON.stringify(body) })
      stat.http.push(Date.now() - at)
      if (res.status !== 202) { stat.httpFail[res.status] = (stat.httpFail[res.status] || 0) + 1; pending.delete(body.key) }
      await res.arrayBuffer()
    } catch (e) { stat.httpFail.network = (stat.httpFail.network || 0) + 1; pending.delete(body.key) }
    if (Date.now() > nextPortfolio) {
      nextPortfolio = Date.now() + 10000; const t0 = Date.now()
      try { const res = await fetch(API + '/portfolio', { headers: H }); await res.arrayBuffer(); res.ok ? stat.portfolio.push(Date.now() - t0) : stat.portfolioFail++ } catch { stat.portfolioFail++ }
    }
    await sleep(rnd(1000, 3000))
  }
  await sleep(3000); ws.close()
}
await Promise.all(users.map(trader))
stat.noEvent = pending.size
const q = (a, p) => { if (!a.length) return null; const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(s.length * p))] }
const sum = (a) => ({ n: a.length, p50: q(a, 0.5), p95: q(a, 0.95), p99: q(a, 0.99), max: q(a, 1) })
console.log(JSON.stringify({ traders: users.length, seconds: SECONDS, ordersSent: stat.sent, perSecond: +(stat.sent / SECONDS).toFixed(1), httpMs: sum(stat.http), orderToResultMs: sum(stat.e2e), portfolioMs: sum(stat.portfolio), portfolioFail: stat.portfolioFail, httpFail: stat.httpFail, status: stat.status, reasons: stat.reason, noResultEvent: stat.noEvent, wsOpened: stat.wsOpen, wsDropped: stat.wsClosed, tickerMsgs: stat.ticker }, null, 1))
