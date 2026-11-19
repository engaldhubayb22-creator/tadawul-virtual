// Application state, REST calls and the live feed connection.
// All prices and amounts from the server are integers in halalas.
import { reactive } from 'vue'

export const state = reactive({
  token: localStorage.getItem('token') || '',
  user: null,
  balances: { cash: 0, reserved: 0 },
  market: { open: '10:00', close: '22:00', timezone: 'Asia/Riyadh' },
  companies: {}, // by symbol
  symbols: [],
  selected: '',
  book: { bids: [], asks: [], last: 0 },
  trades: [],
  orders: [],
  positions: [],
  netWorth: 0,
  news: [],
  chatRoom: 'general', // 'general' or 'symbol' (the selected company's room)
  chat: [],
  threads: [],
  thread: null,
  financing: null,
  ipos: [],
  whales: [],
  holders: { symbol: '', total_held: 0, holders: [] },
  connected: false,
  toasts: [],
  showAuth: false,
})

export const money = (h) =>
  h == null ? '—' : (h / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
export const qty = (n) => (n == null ? '—' : Number(n).toLocaleString('en-US'))
export const pct = (bps) => (bps == null ? '—' : (bps > 0 ? '+' : '') + (bps / 100).toFixed(2) + '%')

const REASONS = {
  insufficient_funds: 'الرصيد غير كافٍ',
  insufficient_shares: 'لا تملك هذه الكمية',
  outside_daily_limits: 'السعر خارج حدود التذبذب اليومية',
  market_closed: 'السوق مغلق الآن',
  bad_tick: 'السعر لا يطابق وحدة تغيّر السعر',
  bad_price: 'السعر غير صحيح',
  bad_quantity: 'الكمية غير صحيحة',
  halted: 'التداول موقوف على هذا السهم',
  self_trade_blocked: 'أُلغي الباقي: الطرف المقابل أمر لك',
  no_reference_price: 'لا يوجد سعر مرجعي لأمر السوق',
  unknown_order: 'الأمر غير موجود',
  not_open: 'الأمر ليس مفتوحًا',
}
const STATUS = {
  open: 'دخل الأمر السوق', partial: 'نُفّذ جزء من الأمر', filled: 'نُفّذ الأمر كاملًا',
  cancelled: 'أُلغي الأمر', rejected: 'رُفض الأمر', expired: 'انتهت صلاحية الأمر',
}

let toastId = 0
export function toast(text, kind = 'info') {
  const id = ++toastId
  state.toasts.push({ id, text, kind })
  setTimeout(() => {
    const i = state.toasts.findIndex((t) => t.id === id)
    if (i >= 0) state.toasts.splice(i, 1)
  }, 4000)
}

// ---------------------------------------------------------------- REST

export async function api(method, path, body) {
  const headers = { Accept: 'application/json' }
  if (body) headers['Content-Type'] = 'application/json'
  if (state.token) headers.Authorization = 'Bearer ' + state.token
  const res = await fetch('/api/v1' + path, { method, headers, body: body ? JSON.stringify(body) : undefined })
  const data = await res.json().catch(() => ({}))
  if (res.status === 401 && state.token) logout(false)
  if (!res.ok) {
    const first = data.errors ? Object.values(data.errors)[0][0] : data.message
    throw new Error(first || 'تعذّر تنفيذ الطلب')
  }
  return data
}

export async function loadMarket() {
  const data = await api('GET', '/market')
  state.market = data.market
  for (const c of data.companies) {
    const old = state.companies[c.symbol]
    state.companies[c.symbol] = { ...c, dir: old?.dir || '', flash: old?.flash || 0 }
  }
  state.symbols = data.companies.map((c) => c.symbol)
  if (!state.selected && state.symbols.length) select(state.symbols[0])
}

export async function loadAccount() {
  if (!state.token) return
  const [p, o] = await Promise.all([api('GET', '/portfolio'), api('GET', '/orders?status=open')])
  state.balances = p.balances
  state.positions = p.positions
  state.netWorth = p.net_worth
  state.orders = o.orders
  loadFinancing().catch(() => {})
}

export async function loadSymbol(symbol) {
  const [d, t] = await Promise.all([
    api('GET', `/market/${symbol}/depth`),
    api('GET', `/market/${symbol}/trades?limit=40`),
  ])
  if (symbol !== state.selected) return
  state.book = { bids: d.bids, asks: d.asks, last: state.companies[symbol]?.last || 0 }
  state.trades = t.trades.map((x) => ({ price: x.price, qty: x.qty, side: x.taker_side, at: Date.parse(isoTime(x.executed_at)) }))
}

// ---------------------------------------------------------------- chat and forum

export const chatRoomName = () => (state.chatRoom === 'general' ? 'general' : state.selected)

export async function loadChat() {
  const room = chatRoomName()
  if (!room) return
  const data = await api('GET', '/chat/' + room)
  if (room !== chatRoomName()) return
  state.chat = data.messages.map((m) => ({ id: m.id, alias: m.alias, text: m.text }))
}

export function setChatRoom(kind) {
  const old = chatRoomName()
  state.chatRoom = kind
  const now = chatRoomName()
  if (old === now) return
  state.chat = []
  if (old) send({ op: 'unsub', ch: ['chat:' + old] })
  if (now) send({ op: 'sub', ch: ['chat:' + now] })
  loadChat().catch(() => {})
}

export async function sendChat(text) {
  if (!state.token) { state.showAuth = true; return }
  await api('POST', '/chat/' + chatRoomName(), { text })
}

export async function loadThreads(symbol) {
  state.threads = (await api('GET', '/forum' + (symbol ? '?symbol=' + symbol : ''))).threads
}

export async function openThread(id) {
  state.thread = id == null ? null : await api('GET', '/forum/' + id)
}

export async function loadFinancing() {
  if (state.token) state.financing = await api('GET', '/financing')
}

export async function requestFinancing(amount) {
  state.financing = await api('POST', '/financing', { amount })
  await loadAccount()
}

export async function repayFinancing() {
  state.financing = await api('POST', '/financing/repay')
  await loadAccount()
}

export async function loadIpos() {
  state.ipos = (await api('GET', '/ipos')).ipos
}

export async function subscribeIpo(id, qty) {
  await api('POST', `/ipos/${id}/subscribe`, { qty })
  await Promise.all([loadIpos(), loadAccount()])
}

export async function loadNews() {
  state.news = (await api('GET', '/news')).news
}

export async function loadWhales() {
  state.whales = (await api('GET', '/leaderboard')).traders
}

export async function loadHolders(symbol) {
  const data = await api('GET', `/market/${symbol}/holders`)
  if (symbol === state.selected) state.holders = data
}

// PostgreSQL prints "2026-11-20 01:17:00+03"; browsers want "2026-11-20T01:17:00+03:00".
export const isoTime = (s) => String(s).replace(' ', 'T').replace(/([+-]\d\d)$/, '$1:00')

export function select(symbol) {
  if (symbol === state.selected) return
  const old = state.selected
  state.selected = symbol
  state.book = { bids: [], asks: [], last: 0 }
  state.trades = []
  const mine = state.chatRoom === 'symbol'
  if (old) send({ op: 'unsub', ch: ['book:' + old, 'trades:' + old].concat(mine ? ['chat:' + old] : []) })
  send({ op: 'sub', ch: ['book:' + symbol, 'trades:' + symbol].concat(mine ? ['chat:' + symbol] : []) })
  if (mine) { state.chat = []; loadChat().catch(() => {}) }
  loadSymbol(symbol).catch(() => {})
}

export async function authenticate(mode, form) {
  const data = await api('POST', mode === 'register' ? '/register' : '/login', form)
  state.token = data.token
  state.user = data.user
  state.balances = data.user.balances
  localStorage.setItem('token', data.token)
  state.showAuth = false
  send({ op: 'auth', token: state.token })
  await loadAccount()
}

export function logout(callServer = true) {
  if (callServer && state.token) api('POST', '/logout').catch(() => {})
  state.token = ''
  state.user = null
  state.orders = []
  state.positions = []
  state.financing = null
  localStorage.removeItem('token')
  if (ws) ws.close() // reconnects as a guest, dropping the private channel
}

export async function restoreSession() {
  if (!state.token) return
  try {
    const data = await api('GET', '/me')
    state.user = data.user
    state.balances = data.user.balances
    await loadAccount()
  } catch { /* token expired: api() already logged out */ }
}

export async function placeOrder(order) {
  if (!state.token) { state.showAuth = true; return }
  const key = crypto.randomUUID()
  await api('POST', '/orders', { ...order, key })
}

export async function cancelOrder(id) {
  await api('DELETE', '/orders/' + id)
}

// ---------------------------------------------------------------- live feed

let ws = null
let retry = 0
let refreshTimer = null
const tradeListeners = new Set()
export const onTrade = (fn) => { tradeListeners.add(fn); return () => tradeListeners.delete(fn) }

function send(msg) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(msg))
}

export function connect() {
  const url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/ws'
  ws = new WebSocket(url)
  ws.onopen = () => {
    const wasDown = retry > 0
    retry = 0
    state.connected = true
    if (state.token) send({ op: 'auth', token: state.token })
    const ch = ['ticker']
    if (state.selected) ch.push('book:' + state.selected, 'trades:' + state.selected)
    if (chatRoomName()) ch.push('chat:' + chatRoomName())
    send({ op: 'sub', ch })
    // After a drop, reload the snapshots: ticks sent while offline are gone.
    if (wasDown) { loadMarket().catch(() => {}); loadAccount().catch(() => {}); if (state.selected) loadSymbol(state.selected).catch(() => {}) }
  }
  ws.onclose = () => {
    state.connected = false
    retry++
    setTimeout(connect, Math.min(500 * 2 ** Math.min(retry, 5), 10000))
  }
  ws.onerror = () => ws.close()
  ws.onmessage = (e) => {
    const m = JSON.parse(e.data)
    if (!m.ch) return
    if (m.ch === 'ticker') return onTicker(m.data)
    if (m.ch.startsWith('book:')) return onBook(m.data)
    if (m.ch.startsWith('trades:')) return onTradeMsg(m.data)
    if (m.ch.startsWith('user:')) return onOrder(m.data)
    if (m.ch.startsWith('chat:')) return onChat(m.ch.slice(5), m.data)
  }
}

function onTicker(ticks) {
  for (const t of ticks) {
    const c = state.companies[t.symbol]
    if (!c) continue
    if (t.last !== c.last) {
      c.dir = t.last > c.last ? 'up' : 'down'
      c.flash++ // changing the key restarts the CSS flash animation
    }
    c.last = t.last
    c.volume += t.qty
    if (c.prev_close) {
      c.change = c.last - c.prev_close
      c.change_bps = Math.trunc((c.change * 10000) / c.prev_close)
    }
  }
}

function onBook(b) {
  if (b.symbol !== state.selected) return
  state.book = { bids: b.bids, asks: b.asks, last: b.last }
}

function onTradeMsg(t) {
  if (t.symbol !== state.selected) return
  state.trades.unshift({ price: t.price, qty: t.qty, side: t.taker_side, at: t.at })
  if (state.trades.length > 60) state.trades.length = 60
  tradeListeners.forEach((fn) => fn(t))
}

function onChat(room, m) {
  if (room !== chatRoomName()) return
  if (m.deleted) {
    const i = state.chat.findIndex((x) => x.id === m.id)
    if (i >= 0) state.chat.splice(i, 1)
    return
  }
  if (state.chat.some((x) => x.id === m.id)) return
  state.chat.push({ id: m.id, alias: m.alias, text: m.text })
  if (state.chat.length > 150) state.chat.splice(0, state.chat.length - 150)
}

function onOrder(o) {
  const why = o.reason && REASONS[o.reason]
  const text = (STATUS[o.status] || o.status) + (why ? ' — ' + why : '')
  toast(text, o.status === 'rejected' ? 'bad' : o.status === 'filled' || o.status === 'partial' ? 'good' : 'info')
  clearTimeout(refreshTimer) // many fills can arrive together: refresh once
  refreshTimer = setTimeout(() => loadAccount().catch(() => {}), 150)
}
