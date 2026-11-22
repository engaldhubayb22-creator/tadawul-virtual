// Demo mode: the whole market simulated inside the browser, with no server.
// It answers the same REST paths and emits the same live messages as the real
// API and feed, so the screens are exactly the ones users will get. Nothing
// here is saved: reloading the page starts over.
//
// Build with VITE_DEMO=1. The real engine, ledger and bots are not involved.

const SEED = [
  ['2222', 'أرامكو السعودية', 'الطاقة', 2700, 1], ['1120', 'مصرف الراجحي', 'البنوك', 9500, 5],
  ['1180', 'البنك الأهلي السعودي', 'البنوك', 3600, 5], ['1150', 'مصرف الإنماء', 'البنوك', 2800, 5],
  ['2010', 'سابك', 'المواد الأساسية', 6500, 5], ['1211', 'معادن', 'المواد الأساسية', 5000, 5],
  ['7010', 'اس تي سي', 'الاتصالات', 4200, 5], ['2280', 'المراعي', 'إنتاج الأغذية', 5500, 5],
  ['4190', 'جرير', 'التجزئة', 1300, 1], ['4030', 'البحري', 'النقل', 2500, 1],
]
import { tickFor } from './ticks'

const START_CASH = 10000000
const rnd = (a, b) => a + Math.floor(Math.random() * (b - a + 1))
const grid = (p, tick) => Math.max(tick, Math.round(p / tick) * tick)

const co = {} // by symbol
for (const [symbol, name, sector, price] of SEED) {
  const tick = tickFor(price * 1.1) // one step for the whole day's range keeps the demo book on the grid
  const step = Math.max(tick, grid(price / 1000, tick))
  const c = { symbol, name, sector, tick, step, prev: price, last: price, mid: price, volume: 0,
    low: grid(price * 0.9, tick), high: grid(price * 1.1, tick), trades: [], candles: [], drift: 0 }
  // Two hours of history so the chart is not empty.
  let p = grid(price * (0.97 + Math.random() * 0.03), tick)
  const now = Math.floor(Date.now() / 60000) * 60000
  for (let i = 120; i >= 1; i--) {
    const o = p; let h = o, l = o
    for (let k = 0; k < 6; k++) { p = clamp(c, p + rnd(-2, 2) * step); h = Math.max(h, p); l = Math.min(l, p) }
    c.candles.push({ t: now - i * 60000, open: o, high: h, low: l, close: p, volume: rnd(500, 9000) })
  }
  c.last = c.mid = p
  c.volume = c.candles.reduce((s, x) => s + x.volume, 0)
  co[symbol] = c
}
function clamp(c, p) { return Math.min(c.high - 6 * c.step, Math.max(c.low + 6 * c.step, grid(p, c.tick))) }

function book(c) {
  const side = (dir) => Array.from({ length: 5 }, (_, k) => ({ price: c.mid + dir * (k + 1) * c.step, qty: rnd(100, 2000), orders: rnd(1, 4) }))
  return { symbol: c.symbol, last: c.last, bids: side(-1), asks: side(1) }
}

// ---------------------------------------------------------------- the user

const me = { user: null, cash: 0, reserved: 0, positions: {}, orders: [], trades: [], financing: null, ipo: {} }
let nextId = 1000
const need = () => { if (!me.user) throw new Error('سجّل الدخول أولًا') }
const stocks = () => Object.entries(me.positions).reduce((s, [sym, p]) => s + p.qty * co[sym].last, 0)
const debt = () => (me.financing ? me.financing.principal + me.financing.fee : 0)
const profile = () => ({ id: 1, name: me.user.name, alias: me.user.alias, role: 'trader', balances: { cash: me.cash, reserved: me.reserved } })

let on = {} // live handlers supplied by the store
const later = (fn) => setTimeout(fn, 150)
const orderEvent = (o, status, reason) => later(() => on.order?.({ order_id: o.id, symbol: o.symbol, key: o.key, status, reason, filled_qty: o.filled_qty }))

function fill(o, price) {
  const c = co[o.symbol]
  const pos = (me.positions[o.symbol] ??= { qty: 0, reserved: 0, cost: 0 })
  if (o.side === 'buy') {
    if (o.status === 'open') { me.reserved -= o.price * o.qty; me.cash += (o.price - price) * o.qty } else me.cash -= price * o.qty
    pos.qty += o.qty; pos.cost += price * o.qty
  } else {
    if (o.status === 'open') pos.reserved -= o.qty
    pos.cost -= Math.floor((pos.cost * o.qty) / pos.qty); pos.qty -= o.qty
    me.cash += price * o.qty
    if (pos.qty === 0) delete me.positions[o.symbol]
  }
  me.trades.unshift({ id: ++nextId, order_id: o.id, symbol: o.symbol, name: c.name, side: o.side, price, qty: o.qty,
    value: price * o.qty, commission: 0, net: price * o.qty, executed_at: new Date().toISOString() })
  o.filled_qty = o.qty; o.status = 'filled'
  me.orders = me.orders.filter((x) => x !== o)
  trade(c, price, o.qty, o.side)
  orderEvent(o, 'filled')
}

function placeOrder(b) {
  need()
  const c = co[b.symbol]
  if (!c) throw new Error('الشركة غير موجودة')
  const o = { id: ++nextId, symbol: b.symbol, side: b.side, kind: b.kind, price: b.price ?? null, qty: b.qty, filled_qty: 0, status: 'new', key: b.key }
  const reject = (why) => { o.status = 'rejected'; orderEvent(o, 'rejected', why); return { status: 'queued', key: o.key } }
  const ask = c.mid + c.step, bid = c.mid - c.step
  const free = (me.positions[o.symbol]?.qty ?? 0) - (me.positions[o.symbol]?.reserved ?? 0)
  if (o.kind === 'limit') {
    if (o.price % tickFor(o.price)) return reject('bad_tick')
    if (o.price < c.low || o.price > c.high) return reject('outside_daily_limits')
  }
  if (o.side === 'sell' && free < o.qty) return reject('insufficient_shares')
  const crossing = o.kind === 'market' || (o.side === 'buy' ? o.price >= ask : o.price <= bid)
  if (crossing) {
    const price = o.side === 'buy' ? ask : bid
    if (o.side === 'buy' && me.cash < price * o.qty) return reject('insufficient_funds')
    fill(o, price)
  } else {
    if (o.side === 'buy') {
      if (me.cash < o.price * o.qty) return reject('insufficient_funds')
      me.cash -= o.price * o.qty; me.reserved += o.price * o.qty
    } else me.positions[o.symbol].reserved += o.qty
    o.status = 'open'; me.orders.unshift(o); orderEvent(o, 'open')
  }
  return { status: 'queued', key: o.key }
}

function cancelOrder(id) {
  const o = me.orders.find((x) => x.id === id)
  if (!o) throw new Error('الأمر غير موجود')
  if (o.side === 'buy') { me.reserved -= o.price * o.qty; me.cash += o.price * o.qty } else me.positions[o.symbol].reserved -= o.qty
  me.orders = me.orders.filter((x) => x !== o); o.status = 'cancelled'; orderEvent(o, 'cancelled')
  return { status: 'queued' }
}

// ---------------------------------------------------------------- the market

let selected = () => ''
function trade(c, price, qty, side) {
  c.last = price; c.volume += qty
  const t = { id: ++nextId, price, qty, taker_side: side, at: Date.now() }
  c.trades.unshift(t); if (c.trades.length > 60) c.trades.length = 60
  const bucket = Math.floor(t.at / 60000) * 60000
  let k = c.candles.at(-1)
  if (!k || k.t !== bucket) { k = { t: bucket, open: price, high: price, low: price, close: price, volume: 0 }; c.candles.push(k) }
  k.high = Math.max(k.high, price); k.low = Math.min(k.low, price); k.close = price; k.volume += qty
  if (c.symbol === selected()) on.trade?.({ symbol: c.symbol, price, qty, taker_side: side, at: t.at })
  return { symbol: c.symbol, last: price, qty, side }
}

function tick() {
  const ticks = []
  for (const c of Object.values(co)) {
    if (Math.random() > 0.45) continue
    if (Math.random() < 0.03) c.drift = rnd(-1, 1) // a trend that lasts a while
    c.mid = clamp(c, c.mid + (rnd(-2, 2) + (Math.random() < 0.5 ? c.drift : 0)) * c.step)
    const side = Math.random() < 0.5 ? 'buy' : 'sell'
    ticks.push(trade(c, c.mid + (side === 'buy' ? c.step : -c.step), rnd(10, 600), side))
    if (c.symbol === selected()) on.book?.(book(c))
    // Resting orders of the user fill when the market reaches their price.
    for (const o of [...me.orders]) {
      if (o.symbol === c.symbol && (o.side === 'buy' ? c.last <= o.price : c.last >= o.price)) fill(o, o.price)
    }
  }
  if (ticks.length) on.ticker?.(ticks)
}

// ---------------------------------------------------------------- community

const chat = { general: [] }
const CHATTER = [
  ['متداول_1', 'أرامكو شكلها رايحة فوق اليوم'], ['صقر_السوق', 'الراجحي عنده مقاومة قريبة'], ['أبو_فهد', 'دخلت في معادن بكمية'],
  ['متداول_7', 'السيولة اليوم ممتازة'], ['نورة_ت', 'أحد عنده تحليل لسابك؟'], ['هامور_نجد', 'القطاع البنكي يقود السوق'],
  ['متداول_3', 'جنيت أرباحي في البحري'], ['أبو_سلطان', 'اس تي سي ثقيل اليوم'],
]
function say(room, alias, text) {
  const m = { id: ++nextId, alias, text }
  ;(chat[room] ??= []).push(m); if (chat[room].length > 60) chat[room].shift()
  on.chat?.(room, { id: m.id, alias, text, at: Date.now() })
  return m
}
const forum = [
  { id: 1, title: 'تحليل فني لأرامكو', body: 'دعم عند 26.80 ومقاومة عند 28.20. الاتجاه العام صاعد ما دام فوق الدعم.', alias: 'صقر_السوق', symbol: '2222', posts: [{ id: 1, alias: 'نورة_ت', body: 'تحليل واضح، شكرًا.' }] },
  { id: 2, title: 'نظرة على السوق هذا الأسبوع', body: 'القطاع البنكي يقود الارتفاع، والسيولة أعلى من المتوسط.', alias: 'هامور_نجد', symbol: null, posts: [] },
]
const news = [
  { id: 2, symbol: '1120', name: 'مصرف الراجحي', title: 'نمو الأرباح الفصلية 12%', body: '' },
  { id: 1, symbol: '2222', name: 'أرامكو السعودية', title: 'اكتشاف حقل غاز جديد', body: '' },
]
const ipo = { id: 1, symbol: '9001', name: 'شركة التقنية الحديثة', price: 4000, shares_offered: 100000, min_qty: 10, max_qty: 5000, status: 'open', accepting: true, requested: 184000, subscribers: 61 }
const WHALES = [['هامور_نجد', 48200000], ['صقر_السوق', 23750000], ['أبو_فهد', 15400000], ['نورة_ت', 12100000], ['متداول_7', 10650000], ['أبو_سلطان', 9800000], ['متداول_3', 9100000]]
const TERMS = { fee_bps: 100, days: 30, margin_call_bps: 3000, liquidation_bps: 2000 }

function financing() {
  const assets = me.cash + me.reserved + stocks(), d = debt()
  const limit = me.financing ? 0 : Math.floor((Math.max(assets, 0) * 10000) / 10100)
  return { enabled: true, terms: TERMS, limit, assets, debt: d, equity: assets - d,
    margin_bps: d > 0 && assets > 0 ? Math.floor(((assets - d) * 10000) / assets) : null,
    financing: me.financing && { id: 1, ...me.financing, status: 'active', margin_called: false } }
}

// ---------------------------------------------------------------- REST

const companyRow = (c) => ({ symbol: c.symbol, name: c.name, sector: c.sector, status: 'trading', tick_size: c.tick, last: c.last,
  prev_close: c.prev, change: c.last - c.prev, change_bps: Math.trunc(((c.last - c.prev) * 10000) / c.prev),
  low_limit: c.low, high_limit: c.high, volume: c.volume })

export async function demoApi(method, path, body) {
  const [route, query = ''] = path.split('?')
  const p = route.split('/').filter(Boolean)
  const c = p[0] === 'market' && p[1] ? co[p[1]] : null
  if (p[0] === 'market' && p[1] && !c) throw new Error('الشركة غير موجودة')
  const key = method + ' ' + (p[0] === 'market' && p[1] ? 'market/*/' + p[2] : p[0] === 'orders' && p[1] ? 'orders/*' : p.join('/').replace(/\/\d+/g, '/*'))
  switch (key) {
    case 'GET market': return { market: { open: '00:00', close: '23:59', timezone: 'Asia/Riyadh' }, companies: Object.values(co).map(companyRow) }
    case 'GET market/*/depth': return book(c)
    case 'GET market/*/trades': return { symbol: c.symbol, trades: c.trades.map((t) => ({ ...t, executed_at: new Date(t.at).toISOString() })) }
    case 'GET market/*/candles': return { symbol: c.symbol, candles: c.candles.map((k) => ({ bucket: new Date(k.t).toISOString(), ...k })) }
    case 'GET market/*/holders': {
      const mine = me.positions[c.symbol]?.qty ?? 0, total = 11000000
      const rows = [['صندوق السيولة', true, 10000000 - mine], ['محفظة التداول النشط', true, 1000000]]
      if (mine > 0) rows.push([me.user.alias, false, mine])
      return { symbol: c.symbol, total_held: total, holders: rows.map(([holder, is_fund, qty]) => ({ holder, is_fund, qty, share_bps: Math.floor((qty * 10000) / total) })) }
    }
    case 'POST register': case 'POST login':
      if (!body.mobile || !/^05\d{8}$/.test(body.mobile)) throw new Error('رقم الجوال يجب أن يكون بصيغة 05xxxxxxxx')
      if (!me.user) { me.user = { name: body.name || 'متداول', alias: body.alias || 'متداول_' + body.mobile.slice(-4) }; me.cash = START_CASH; me.since = new Date().toISOString() }
      return { token: 'demo', user: profile(), starting_balance: START_CASH }
    case 'POST logout': return {}
    case 'GET me': need(); return { user: profile() }
    case 'GET portfolio': {
      need()
      const positions = Object.entries(me.positions).map(([sym, x]) => ({ symbol: sym, name: co[sym].name, qty: x.qty, reserved_qty: x.reserved,
        avg_cost: Math.floor(x.cost / x.qty), last: co[sym].last, market_value: x.qty * co[sym].last, unrealized_pnl: x.qty * co[sym].last - x.cost }))
      const mv = stocks()
      return { balances: { cash: me.cash, reserved: me.reserved }, positions, market_value: mv, debt: debt(), net_worth: me.cash + me.reserved + mv - debt() }
    }
    case 'GET trades': need(); return { trades: me.trades }
    case 'GET pledges': need(); return { enabled: false, debt: 0, pledged_value: 0, loans: [], closed: [], eligible: [],
      terms: { ltv_bps: 5000, fee_bps: 200, days: 90, call_bps: 7000, foreclose_bps: 8500 } }
    case 'GET market-trades': {
      const after = Number(new URLSearchParams(query).get('after') ?? 0)
      const rows = Object.values(co).flatMap((x) => x.trades.map((t) => ({ id: t.id, symbol: x.symbol, name: x.name, price: t.price,
        qty: t.qty, taker_side: t.taker_side, executed_at: new Date(t.at).toISOString() })))
      return { trades: rows.filter((t) => t.id > after).sort((a, b) => b.id - a.id).slice(0, 100) }
    }
    case 'GET bank': {
      need()
      let balance = me.cash + me.reserved
      const statement = me.trades.map((t) => ({ id: t.id, type: 'trade', amount: t.side === 'buy' ? -t.value : t.value, symbol: t.symbol, name: t.name, at: t.executed_at }))
      statement.push({ id: 0, type: 'starting_balance', amount: START_CASH, symbol: null, name: null, at: me.since })
      for (const s of statement) { s.balance_after = balance; balance -= s.amount }
      return { balances: { cash: me.cash, reserved: me.reserved }, statement }
    }
    case 'GET orders': need(); return { orders: query.includes('status=open') ? me.orders : [] }
    case 'POST orders': return placeOrder(body)
    case 'DELETE orders/*': need(); return cancelOrder(Number(p[1]))
    case 'GET news': return { news }
    case 'GET leaderboard': {
      const rows = WHALES.map(([alias, net], i) => ({ user_id: 100 + i, alias, net_worth: net, cash: Math.floor(net * 0.3), stocks: Math.floor(net * 0.7), holdings: 3 + (i % 4) }))
      if (me.user) rows.push({ user_id: 1, alias: me.user.alias, net_worth: me.cash + me.reserved + stocks() - debt(), cash: me.cash + me.reserved, stocks: stocks(), holdings: Object.keys(me.positions).length })
      return { traders: rows.sort((a, b) => b.net_worth - a.net_worth).map((r, i) => ({ rank: i + 1, ...r })) }
    }
    case 'GET ipos': return { ipos: [{ ...ipo, requested: ipo.requested + (me.ipo.qty ?? 0), coverage_bps: Math.floor(((ipo.requested + (me.ipo.qty ?? 0)) * 10000) / ipo.shares_offered), my_requested: me.ipo.qty ?? null, my_allocated: null }] }
    case 'POST ipos/*/subscribe': {
      need()
      if (me.ipo.qty) throw new Error('سبق أن اكتتبت في هذا الطرح')
      if (!(body.qty >= ipo.min_qty && body.qty <= ipo.max_qty)) throw new Error(`الكمية يجب أن تكون بين ${ipo.min_qty} و ${ipo.max_qty}`)
      if (me.cash < body.qty * ipo.price) throw new Error('الرصيد غير كافٍ')
      me.cash -= body.qty * ipo.price; me.reserved += body.qty * ipo.price; me.ipo.qty = body.qty
      return { status: 'subscribed' }
    }
    case 'GET financing': need(); return financing()
    case 'POST financing':
      need()
      if (me.financing) throw new Error('لديك تمويل قائم، سدّده أولًا')
      if (!(body.amount > 0) || body.amount > financing().limit) throw new Error('المبلغ أكبر من حد التمويل المتاح')
      me.financing = { principal: body.amount, fee: Math.floor(body.amount / 100), due_at: new Date(Date.now() + 30 * 864e5).toISOString() }
      me.cash += body.amount
      return financing()
    case 'POST financing/repay':
      need()
      if (!me.financing) throw new Error('لا يوجد تمويل قائم')
      if (me.cash < debt()) throw new Error('النقد المتاح لا يكفي للسداد')
      me.cash -= debt(); me.financing = null
      return financing()
    case 'GET chat/' + p[1]: return { room: p[1], messages: (chat[p[1]] ?? []).map((m) => ({ ...m })) }
    case 'POST chat/' + p[1]: need(); return { id: say(p[1], me.user.alias, String(body.text).slice(0, 300)).id }
    case 'GET forum': {
      const sym = new URLSearchParams(query).get('symbol')
      return { threads: forum.filter((t) => !sym || t.symbol === sym).map((t) => ({ id: t.id, title: t.title, alias: t.alias, symbol: t.symbol, company: t.symbol && co[t.symbol].name, reply_count: t.posts.length })).reverse() }
    }
    case 'GET forum/*': {
      const t = forum.find((x) => x.id === Number(p[1]))
      if (!t) throw new Error('الموضوع غير موجود')
      return { thread: { id: t.id, title: t.title, body: t.body, alias: t.alias, symbol: t.symbol, company: t.symbol && co[t.symbol].name }, posts: t.posts }
    }
    case 'POST forum': need(); forum.push({ id: ++nextId, title: body.title, body: body.body, alias: me.user.alias, symbol: body.symbol || null, posts: [] }); return { id: nextId }
    case 'POST forum/*/posts': {
      need()
      const t = forum.find((x) => x.id === Number(p[1]))
      if (!t) throw new Error('الموضوع غير موجود')
      t.posts.push({ id: ++nextId, alias: me.user.alias, body: body.body })
      return { id: nextId }
    }
  }
  throw new Error('غير متاح في النسخة التجريبية')
}

/** Start the simulated market. `handlers` receive the same payloads as the live feed. */
export function startDemoFeed(handlers, getSelected) {
  on = handlers
  selected = getSelected
  setInterval(tick, 600)
  let i = 0
  setInterval(() => { const [alias, text] = CHATTER[i++ % CHATTER.length]; say('general', alias, text) }, 7000)
  say('general', ...CHATTER[7]); say('general', ...CHATTER[3])
}
