<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import AdminPanel from './AdminPanel.vue'
import BankPage from './BankPage.vue'
import AuthModal from './AuthModal.vue'
import ChartPanel from './ChartPanel.vue'
import { nearestTicks, tickFor } from './ticks'
import {
  cancelOrder, connect, loadAccount, loadHolders, loadMyTrades, loadMarketTrades, loadBank, loadPledges, loadIpos, loadMarket, loadNews, loadWhales, logout, money, pct, placeOrder, qty,
  loadChat, loadThreads, openThread, restoreSession, select, sendChat, setChatRoom,
  state, subscribeIpo, toast, api, isoTime, DEMO,
} from './store'

const rows = computed(() => state.symbols.map((s) => state.companies[s]))
const current = computed(() => state.companies[state.selected] || null)

// The index moves with every tick: free float x last price over free float x
// listing price, base 10,000. Same formula as the API's first snapshot.
const index = computed(() => {
  let now = 0, prev = 0, base = 0
  for (const c of rows.value) {
    if (!c || c.status !== 'trading' || !c.free_float) continue
    now += c.free_float * c.last; prev += c.free_float * (c.prev_close ?? c.last); base += c.free_float * c.base_price
  }
  return base ? { value: (now / base) * 10000, change: prev ? ((now - prev) / prev) * 100 : 0 } : null
})
const big = (h) => {
  if (h == null) return '—'
  const sar = h / 100
  return sar >= 1e9 ? (sar / 1e9).toFixed(2) + ' مليار' : sar >= 1e6 ? (sar / 1e6).toFixed(2) + ' مليون' : money(h)
}
const ratio = (x) => (x == null ? '—' : (x / 100).toFixed(2))
const forumSymbol = ref(false) // forum filter: false = all threads, true = only the selected company
const isAdmin = computed(() => state.user?.role === 'admin')

// ---- pages. A visitor sees the market and the public pages only: trading,
// the portfolio and financing appear after signing in.
const page = ref('market')
const menu = computed(() => [
  { title: 'السوق', items: [
    { id: 'market', label: 'شاشة السوق' },
    { id: 'alltrades', label: 'صفقات السوق' },
    { id: 'holders', label: 'ملاك الأسهم' },
    { id: 'ipos', label: 'الاكتتابات' }] },
  ...(state.user ? [{ title: 'حسابي', items: [
    { id: 'portfolio', label: 'محفظتي' },
    { id: 'orders', label: 'أوامري' },
    { id: 'mytrades', label: 'صفقاتي' },
    { id: 'bank', label: 'البنك' }] }] : []),
  { title: 'المجتمع', items: [
    { id: 'whales', label: 'الهوامير' },
    { id: 'forum', label: 'المنتدى' }] },
  ...(isAdmin.value ? [{ title: 'الإدارة', items: [{ id: 'admin', label: 'لوحة الإدارة' }] }] : []),
])
const pages = computed(() => menu.value.flatMap((g) => g.items))
watch(pages, (list) => { if (!list.some((p) => p.id === page.value)) page.value = 'market' })
function show(symbol) { select(symbol); page.value = 'market' }
// Panel under the chart on the market page: my open orders or the top holders.
const tab = ref('holders')
watch(() => state.user, (u) => { tab.value = u ? 'orders' : 'holders' })

// ---- market clock (Riyadh time)
const now = ref(Date.now())
setInterval(() => (now.value = Date.now()), 15000)
const marketOpen = computed(() => {
  const hm = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: state.market.timezone })
    .format(now.value)
  return hm >= state.market.open && hm < state.market.close
})

// ---- order ticket
const ticket = reactive({ side: 'buy', kind: 'limit', price: '', qty: 100, tif: 'day' })
const choices = ref(null) // the two valid prices offered when the typed price is between steps
const busy = ref(false)
watch(() => state.selected, () => { ticket.price = current.value ? (current.value.last / 100).toFixed(2) : '' })
watch(current, (c) => { if (c && ticket.price === '') ticket.price = (c.last / 100).toFixed(2) })

const priceHalalas = computed(() => Math.round(Number(ticket.price) * 100))
// The price step depends on the price itself (0.01 below 25, 0.02 from 25, ...).
const tick = computed(() => tickFor(priceHalalas.value || current.value?.last || 0) / 100)
watch(() => [ticket.price, ticket.kind], () => { choices.value = null })
function choose(price) { ticket.price = (price / 100).toFixed(2); choices.value = null }
const estimate = computed(() => {
  const p = ticket.kind === 'market' ? (current.value?.last || 0) : priceHalalas.value
  return p * Number(ticket.qty || 0)
})

function pick(price, side) {
  ticket.kind = 'limit'
  if (!state.user) return
  ticket.price = (price / 100).toFixed(2)
  if (side) ticket.side = side
}

async function submit() {
  if (!state.token) { state.showAuth = true; return }
  busy.value = true
  try {
    const order = { symbol: state.selected, side: ticket.side, kind: ticket.kind, qty: Number(ticket.qty), tif: ticket.tif }
    if (ticket.kind === 'limit') {
      // A price between two steps is not guessed: the trader picks one.
      const [down, up] = nearestTicks(priceHalalas.value)
      if (down !== up) { choices.value = [down, up]; return }
      order.price = priceHalalas.value
    }
    await placeOrder(order)
  } catch (e) {
    toast(e.message, 'bad')
  } finally {
    busy.value = false
  }
}

async function cancel(id) {
  try { await cancelOrder(id) } catch (e) { toast(e.message, 'bad') }
}

// ---- depth bars: widths relative to the largest level on screen
const maxDepth = computed(() => Math.max(1, ...state.book.bids.map((l) => l.qty), ...state.book.asks.map((l) => l.qty)))
const asksTopDown = computed(() => [...state.book.asks].reverse())
const stamp = (s) => new Date(Date.parse(isoTime(s))).toLocaleString('en-GB', {
  timeZone: state.market.timezone, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const time = (ms) => new Date(ms).toLocaleTimeString('en-GB', { timeZone: state.market.timezone })

const reloadThreads = () => loadThreads(forumSymbol.value ? state.selected : '').catch(() => {})

// ---- news, whales and top holders: refreshed on a slow timer while visible
function refreshTab() {
  // Allocations and refunds change the account without any order event, so
  // the account tabs reload on their own too.
  const on = page.value
  if (state.token && ['market', 'portfolio', 'orders', 'ipos', 'bank'].includes(on)) loadAccount().catch(() => {})
  if (on === 'mytrades') loadMyTrades().catch(() => {})
  if (on === 'bank') loadBank().catch(() => {})
  // Loans against pledged shares: always on the bank page, elsewhere only while there is one to watch.
  if (state.token && (on === 'bank' || state.pledges === null || state.pledges.loans.length)) loadPledges().catch(() => {})
  if (on === 'alltrades') loadMarketTrades().catch(() => {})
  if (on === 'whales') loadWhales().catch(() => {})
  if (on === 'forum' && !state.thread) reloadThreads()
  if (on === 'ipos') {
    loadIpos().catch(() => {})
    // A listed IPO adds a company to the market: pick it up.
    if (state.ipos.some((i) => i.status === 'listed' && !state.companies[i.symbol])) loadMarket().catch(() => {})
  }
  if (on === 'market' && tab.value === 'holders' && state.selected) loadHolders(state.selected).catch(() => {})
  if (on === 'holders' && state.selected) loadHolders(state.selected, 100).catch(() => {})
}
watch([page, tab, () => state.selected, forumSymbol], refreshTab)
setInterval(refreshTab, 10000)
// The market-wide trades page follows the market closely while it is open.
setInterval(() => { if (page.value === 'alltrades') loadMarketTrades().catch(() => {}) }, 2000)

// ---- my trades: totals over the listed executions
const tradeTotals = computed(() => {
  const t = { buy: 0, sell: 0, commission: 0 }
  for (const x of state.myTrades) { t[x.side] += x.value; t.commission += x.commission }
  return t
})
setInterval(() => loadNews().catch(() => {}), 30000)
// ---- chat
const side = ref('tape') // bottom-left panel: 'tape' or 'chat'
const chatText = ref('')
const chatBox = ref(null)
watch(() => state.chat.length, async () => { await nextTick(); if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight })
async function say() {
  const text = chatText.value.trim()
  if (!text) return
  try { await sendChat(text); if (state.token) chatText.value = '' } catch (e) { toast(e.message, 'bad') }
}

// ---- forum
const draft = reactive({ title: '', body: '', here: true, reply: '' })
async function postThread() {
  if (!state.token) { state.showAuth = true; return }
  try {
    const r = await api('POST', '/forum', { title: draft.title, body: draft.body, symbol: draft.here ? state.selected : null })
    draft.title = ''; draft.body = ''
    await openThread(r.id)
  } catch (e) { toast(e.message, 'bad') }
}
async function postReply() {
  if (!state.token) { state.showAuth = true; return }
  try {
    await api('POST', `/forum/${state.thread.thread.id}/posts`, { body: draft.reply })
    draft.reply = ''
    await openThread(state.thread.thread.id)
  } catch (e) { toast(e.message, 'bad') }
}

const fin = computed(() => state.financing)
const pledgeWarned = computed(() => state.pledges?.loans.some((l) => l.warned))
const ipoQty = reactive({})
async function subscribe(ipo) {
  if (!state.token) { state.showAuth = true; return }
  try {
    await subscribeIpo(ipo.id, Number(ipoQty[ipo.id] || ipo.min_qty))
    toast('تم الاكتتاب وحُجز المبلغ', 'good')
  } catch (e) { toast(e.message, 'bad') }
}
const latestNews = computed(() => state.news.slice(0, 6))

onMounted(async () => {
  loadNews().catch(() => {})
  loadChat().catch(() => {})
  connect()
  try { await loadMarket() } catch (e) { toast(e.message, 'bad') }
  restoreSession()
})
</script>

<template>
  <header class="top">
    <div class="brand">تداول الافتراضي <span class="note">{{ DEMO ? 'نسخة تجريبية داخل المتصفح: السوق محاكاة والبيانات تُمسح عند إعادة التحميل' : 'أسعار افتراضية لا تمثل السوق الحقيقي' }}</span></div>
    <div class="status">
      <span v-if="index" class="idx">المؤشر العام <b class="num">{{ index.value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</b>
        <span class="num" :class="index.change > 0 ? 'up' : index.change < 0 ? 'down' : ''">{{ (index.change > 0 ? '+' : '') + index.change.toFixed(2) }}%</span></span>
      <span class="pill" :class="marketOpen ? 'good' : 'bad'">
        {{ marketOpen ? 'السوق مفتوح' : 'السوق مغلق' }} · {{ state.market.open }}–{{ state.market.close }}
      </span>
      <span class="pill" :class="state.connected ? 'good' : 'bad'">{{ state.connected ? 'بث مباشر' : 'يعيد الاتصال…' }}</span>
      <template v-if="state.user">
        <span class="cash">النقد <b class="num">{{ money(state.balances.cash) }}</b></span>
        <span class="alias">{{ state.user.alias }}</span>
        <button class="ghost" @click="logout()">خروج</button>
      </template>
      <button v-else class="primary small" @click="state.showAuth = true">دخول / تسجيل</button>
    </div>
  </header>
  <div class="shell">
  <nav class="menu">
    <div v-for="g in menu" :key="g.title" class="group">
      <h3>{{ g.title }}</h3>
      <button v-for="p in g.items" :key="p.id" :class="{ on: page === p.id }" @click="page = p.id">{{ p.label }}</button>
    </div>
  </nav>
  <div class="content">

  <div v-if="latestNews.length && page === 'market'" class="newsbar">
    <b>الأخبار</b>
    <button v-for="n in latestNews" :key="n.id" class="newsitem" :title="n.body" @click="n.symbol && show(n.symbol)">
      <span v-if="n.name" class="muted">{{ n.name }}:</span> {{ n.title }}
    </button>
  </div>
  <div v-if="fin?.financing?.margin_called || fin?.financing?.status === 'liquidating'" class="marginbar">
    {{ fin.financing.status === 'liquidating' ? 'هامشك نزل تحت حد التسييل: تُباع أسهمك الآن لسداد التمويل.'
      : 'تنبيه هامش: قيمة محفظتك اقتربت من حد التسييل. سدّد التمويل أو خفّف مراكزك.' }}
  </div>
  <div v-if="pledgeWarned" class="marginbar">
    تنبيه تغطية: قيمة أسهمك المرهونة نزلت. سدّد القرض من صفحة البنك قبل أن ينفّذ البنك الرهن.
  </div>
  <AdminPanel v-if="page === 'admin' && isAdmin" />
  <main v-else-if="page === 'market'" class="grid">
    <!-- watchlist -->
    <section class="panel list">
      <table>
        <thead><tr><th>السهم</th><th>آخر سعر</th><th>التغير</th><th class="hide-s">الحجم</th></tr></thead>
        <tbody>
          <tr v-for="c in rows" :key="c.symbol" :class="{ sel: c.symbol === state.selected }" @click="select(c.symbol)">
            <td><b>{{ c.name }}</b><small class="num">{{ c.symbol }}</small></td>
            <td class="num"><span :key="c.flash" class="px" :class="[c.flash ? 'flash-' + c.dir : '', c.change > 0 ? 'up' : c.change < 0 ? 'down' : '']">
              {{ money(c.last) }}<i v-if="c.dir" class="arrow">{{ c.dir === 'up' ? '▲' : '▼' }}</i></span></td>
            <td class="num" :class="c.change > 0 ? 'up' : c.change < 0 ? 'down' : ''">{{ pct(c.change_bps) }}</td>
            <td class="num hide-s muted">{{ qty(c.volume) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- chart -->
    <section class="panel main">
      <div v-if="current" class="headline">
        <h1>{{ current.name }} <small class="num">{{ current.symbol }}</small></h1>
        <span :key="current.flash" class="big num" :class="[current.flash ? 'flash-' + current.dir : '', current.change > 0 ? 'up' : current.change < 0 ? 'down' : '']">{{ money(current.last) }}</span>
        <span class="num" :class="current.change > 0 ? 'up' : current.change < 0 ? 'down' : ''">{{ money(current.change) }} ({{ pct(current.change_bps) }})</span>
        <span class="muted limits">الحدود <span class="num">{{ money(current.low_limit) }} – {{ money(current.high_limit) }}</span></span>
      </div>
      <div v-if="current?.issued_shares" class="facts">
        <span>القيمة السوقية <b>{{ big(current.issued_shares * current.last) }}</b></span>
        <span>الأسهم المصدرة <b class="num">{{ qty(current.issued_shares) }}</b></span>
        <span>الأسهم الحرة <b class="num">{{ qty(current.free_float) }}</b></span>
        <span>القيمة الاسمية <b class="num">{{ money(current.par_value) }}</b></span>
        <span>القيمة الدفترية <b class="num">{{ money(current.book_value) }}</b></span>
        <span>ربحية السهم <b class="num">{{ money(current.eps) }}</b></span>
        <span>مكرر الأرباح <b class="num">{{ current.eps > 0 ? (current.last / current.eps).toFixed(2) : '—' }}</b></span>
        <span>مضاعف القيمة الدفترية <b class="num">{{ current.book_value > 0 ? (current.last / current.book_value).toFixed(2) : '—' }}</b></span>
      </div>
      <ChartPanel @pick="(p) => pick(p)" />
    </section>

    <!-- depth + ticket -->
    <section class="panel side">
      <h2>عمق السوق <small v-if="state.user">اضغط على السعر للتداول</small></h2>
      <div class="depth">
        <div class="dhead"><span>السعر</span><span>الكمية</span><span>أوامر</span></div>
        <div v-for="l in asksTopDown" :key="'a' + l.price" class="lvl ask" @click="pick(l.price, 'buy')">
          <i :style="{ width: (l.qty / maxDepth) * 100 + '%' }"></i>
          <span class="num down">{{ money(l.price) }}</span><span class="num">{{ qty(l.qty) }}</span><span class="num muted">{{ l.orders }}</span>
        </div>
        <div class="mid num">{{ money(state.book.last || current?.last) }}</div>
        <div v-for="l in state.book.bids" :key="'b' + l.price" class="lvl bid" @click="pick(l.price, 'sell')">
          <i :style="{ width: (l.qty / maxDepth) * 100 + '%' }"></i>
          <span class="num up">{{ money(l.price) }}</span><span class="num">{{ qty(l.qty) }}</span><span class="num muted">{{ l.orders }}</span>
        </div>
        <p v-if="!state.book.bids.length && !state.book.asks.length" class="empty">لا توجد أوامر في الدفتر</p>
      </div>

      <div v-if="!state.user" class="gate">
        <b>الشراء والبيع بعد تسجيل الدخول</b>
        <span>سجّل الدخول أو أنشئ حسابًا لتتداول برصيدك الافتراضي.</span>
        <button class="primary" @click="state.showAuth = true">دخول / تسجيل</button>
      </div>
      <form v-else class="ticket" @submit.prevent="submit">
        <div class="seg">
          <button type="button" class="buy" :class="{ on: ticket.side === 'buy' }" @click="ticket.side = 'buy'">شراء</button>
          <button type="button" class="sell" :class="{ on: ticket.side === 'sell' }" @click="ticket.side = 'sell'">بيع</button>
        </div>
        <div class="seg thin">
          <button type="button" :class="{ on: ticket.kind === 'limit' }" @click="ticket.kind = 'limit'">محدد</button>
          <button type="button" :class="{ on: ticket.kind === 'market' }" @click="ticket.kind = 'market'">سوق</button>
        </div>
        <label v-if="ticket.kind === 'limit'">السعر (ريال)
          <input v-model="ticket.price" type="number" min="0.01" step="0.01" required dir="ltr" />
          <small class="hint">خطوة السعر عند هذا المستوى {{ tick.toFixed(2) }}</small>
        </label>
        <div v-if="choices" class="choices">
          <span>السعر {{ ticket.price }} بين خطوتين. اختر أقرب سعر مسموح:</span>
          <button type="button" @click="choose(choices[0])"><b class="num">{{ money(choices[0]) }}</b></button>
          <button type="button" @click="choose(choices[1])"><b class="num">{{ money(choices[1]) }}</b></button>
        </div>
        <p v-if="ticket.kind === 'market'" class="hint">ينفَّذ من أفضل سعر متاح وحتى 5 خطوات سعرية منه. ما يبقى يتحول إلى أمر محدد عند آخر سعر تنفيذ.</p>
        <label>الكمية<input v-model="ticket.qty" type="number" min="1" step="1" required dir="ltr" /></label>
        <label>عند عدم توفر الكمية كاملة
          <select v-model="ticket.tif">
            <option value="day">يبقى الباقي في السوق</option>
            <option value="ioc">نفّذ المتاح وألغِ الباقي</option>
            <option value="fok">نفّذ كامل الكمية أو ألغِ الكل</option>
          </select>
        </label>
        <div class="est">القيمة التقديرية <b class="num">{{ money(estimate) }}</b></div>
        <button class="go" :class="ticket.side" :disabled="busy || !state.selected">
          {{ ticket.side === 'buy' ? 'شراء' : 'بيع' }} {{ current?.name }}
        </button>
      </form>
    </section>

    <!-- tape and chat -->
    <section class="panel tape">
      <div class="tabs">
        <button :class="{ on: side === 'tape' }" @click="side = 'tape'">الصفقات</button>
        <button :class="{ on: side === 'chat' }" @click="side = 'chat'">الشات</button>
        <template v-if="side === 'chat'">
          <button class="room" :class="{ on: state.chatRoom === 'general' }" @click="setChatRoom('general')">عام</button>
          <button class="room" :class="{ on: state.chatRoom === 'symbol' }" @click="setChatRoom('symbol')">{{ current?.name || 'السهم' }}</button>
        </template>
      </div>
      <div v-if="side === 'tape'" class="rows">
        <div v-for="(t, i) in state.trades" :key="t.at + '-' + i" class="trow">
          <span class="num" :class="t.side === 'buy' ? 'up' : 'down'">{{ money(t.price) }}</span>
          <span class="num">{{ qty(t.qty) }}</span>
          <span class="num muted">{{ time(t.at) }}</span>
        </div>
        <p v-if="!state.trades.length" class="empty">لا توجد صفقات بعد</p>
      </div>
      <template v-else>
        <div ref="chatBox" class="rows chat">
          <p v-for="m in state.chat" :key="m.id" class="msg"><b>{{ m.alias }}</b> {{ m.text }}</p>
          <p v-if="!state.chat.length" class="empty">لا توجد رسائل بعد</p>
        </div>
        <form class="say" @submit.prevent="say">
          <input v-model="chatText" maxlength="300" :placeholder="state.user ? 'اكتب رسالة…' : 'سجّل الدخول للمشاركة'" />
          <button class="primary small">إرسال</button>
        </form>
      </template>
    </section>

    <!-- under the chart: my open orders (signed in) and the top holders -->
    <section class="panel account">
      <div class="tabs">
        <button v-if="state.user" :class="{ on: tab === 'orders' }" @click="tab = 'orders'">أوامري المفتوحة ({{ state.orders.length }})</button>
        <button :class="{ on: tab === 'holders' }" @click="tab = 'holders'">كبار الملاك</button>
      </div>
      <table v-if="tab === 'holders'">
        <thead><tr><th>المالك في {{ current?.name }}</th><th>الكمية</th><th>النسبة من الأسهم المصدرة</th></tr></thead>
        <tbody>
          <tr v-for="h in state.holders.holders" :key="h.holder">
            <td><b>{{ h.holder }}</b> <small v-if="h.is_fund" class="tag">صندوق</small></td>
            <td class="num">{{ qty(h.qty) }}</td>
            <td class="num">{{ (h.share_bps / 100).toFixed(2) }}%</td>
          </tr>
          <tr v-if="!state.holders.holders.length"><td colspan="3" class="empty">لا يوجد ملاك بعد</td></tr>
        </tbody>
      </table>
      <table v-else-if="state.user">
        <thead><tr><th>السهم</th><th>الاتجاه</th><th>السعر</th><th>الكمية</th><th>المنفّذ</th><th>المحجوز له</th><th></th></tr></thead>
        <tbody>
          <tr v-for="o in state.orders" :key="o.id">
            <td class="num">{{ o.symbol }}</td>
            <td :class="o.side === 'buy' ? 'up' : 'down'">{{ o.side === 'buy' ? 'شراء' : 'بيع' }}</td>
            <td class="num">{{ money(o.price) }}</td><td class="num">{{ qty(o.qty) }}</td><td class="num">{{ qty(o.filled_qty) }}</td>
            <td class="num muted">{{ o.side === 'buy' ? money(o.reserved_amount) : qty(o.qty - o.filled_qty) + ' سهم' }}</td>
            <td><button class="ghost" @click="cancel(o.id)">إلغاء</button></td>
          </tr>
          <tr v-if="!state.orders.length"><td colspan="7" class="empty">لا توجد أوامر مفتوحة</td></tr>
        </tbody>
      </table>
    </section>
  </main>

  <main v-else class="page">
    <template v-if="page === 'portfolio' && state.user">
      <div class="cards">
        <div class="card"><span>صافي الثروة</span><b class="num">{{ money(state.netWorth) }}</b></div>
        <div class="card"><span>النقد المتاح</span><b class="num">{{ money(state.balances.cash) }}</b></div>
        <div class="card"><span>محجوز في أوامر</span><b class="num">{{ money(state.balances.reserved) }}</b></div>
      </div>
      <section class="panel">
        <h2>أسهمي <small>اضغط على السهم لفتحه في السوق</small></h2>
        <div class="scroll"><table>
        <thead><tr><th>السهم</th><th>الكمية</th><th>مرهونة</th><th>متوسط سعر الشراء</th><th>إجمالي تكلفة الشراء</th><th>آخر سعر</th><th>القيمة السوقية</th><th>الربح / الخسارة</th><th>النسبة</th></tr></thead>
        <tbody>
          <tr v-for="p in state.positions" :key="p.symbol" @click="show(p.symbol)">
            <td><b>{{ p.name }}</b><small class="num">{{ p.symbol }}</small></td><td class="num">{{ qty(p.qty) }}</td>
            <td class="num" :class="{ muted: !p.pledged_qty }">{{ p.pledged_qty ? qty(p.pledged_qty) : '—' }}</td>
            <td class="num">{{ money(p.avg_cost) }}</td>
            <td class="num">{{ money(p.market_value - p.unrealized_pnl) }}</td>
            <td class="num">{{ money(state.companies[p.symbol]?.last ?? p.last) }}</td>
            <td class="num">{{ money(p.market_value) }}</td>
            <td class="num" :class="p.unrealized_pnl > 0 ? 'up' : p.unrealized_pnl < 0 ? 'down' : ''">{{ money(p.unrealized_pnl) }}</td>
            <td class="num" :class="p.unrealized_pnl > 0 ? 'up' : p.unrealized_pnl < 0 ? 'down' : ''">
              {{ p.market_value - p.unrealized_pnl > 0 ? ((p.unrealized_pnl / (p.market_value - p.unrealized_pnl)) * 100).toFixed(2) + '%' : '—' }}</td>
          </tr>
          <tr v-if="!state.positions.length"><td colspan="9" class="empty">لا تملك أسهمًا بعد</td></tr>
        </tbody>
      </table></div>
      </section>
    </template>
    <template v-else-if="page === 'orders' && state.user">
      <section class="panel">
        <h2>أوامري المفتوحة <small>الأوامر التي لم تُنفذ كاملة بعد</small></h2>
        <div class="scroll"><table>
        <thead><tr><th>السهم</th><th>الاتجاه</th><th>السعر</th><th>الكمية</th><th>المنفّذ</th><th>المحجوز له</th><th></th></tr></thead>
        <tbody>
          <tr v-for="o in state.orders" :key="o.id">
            <td class="num">{{ o.symbol }}</td>
            <td :class="o.side === 'buy' ? 'up' : 'down'">{{ o.side === 'buy' ? 'شراء' : 'بيع' }}</td>
            <td class="num">{{ money(o.price) }}</td><td class="num">{{ qty(o.qty) }}</td><td class="num">{{ qty(o.filled_qty) }}</td>
            <td class="num muted">{{ o.side === 'buy' ? money(o.reserved_amount) : qty(o.qty - o.filled_qty) + ' سهم' }}</td>
            <td><button class="ghost" @click="cancel(o.id)">إلغاء</button></td>
          </tr>
          <tr v-if="!state.orders.length"><td colspan="7" class="empty">لا توجد أوامر مفتوحة</td></tr>
        </tbody>
      </table></div>
      </section>
    </template>
    <template v-else-if="page === 'mytrades' && state.user">
      <div class="cards">
        <div class="card"><span>عدد الصفقات</span><b class="num">{{ state.myTrades.length }}</b></div>
        <div class="card"><span>إجمالي الشراء</span><b class="num">{{ money(tradeTotals.buy) }}</b></div>
        <div class="card"><span>إجمالي البيع</span><b class="num">{{ money(tradeTotals.sell) }}</b></div>
        <div class="card"><span>العمولات المدفوعة</span><b class="num">{{ money(tradeTotals.commission) }}</b></div>
      </div>
      <section class="panel">
        <h2>صفقاتي <small>كل تنفيذ بسعره وعمولته، الأحدث أولًا</small></h2>
        <div class="scroll"><table>
          <thead><tr><th>الوقت</th><th>السهم</th><th>العملية</th><th>الكمية</th><th>سعر التنفيذ</th><th>القيمة</th><th>العمولة</th><th>الصافي</th><th>رقم الأمر</th></tr></thead>
          <tbody>
            <tr v-for="t in state.myTrades" :key="t.id" @click="show(t.symbol)">
              <td class="num muted">{{ stamp(t.executed_at) }}</td>
              <td><b>{{ t.name }}</b><small class="num">{{ t.symbol }}</small></td>
              <td :class="t.side === 'buy' ? 'up' : 'down'">{{ t.side === 'buy' ? 'شراء' : 'بيع' }}</td>
              <td class="num">{{ qty(t.qty) }}</td><td class="num">{{ money(t.price) }}</td>
              <td class="num">{{ money(t.value) }}</td><td class="num muted">{{ money(t.commission) }}</td>
              <td class="num">{{ t.side === 'buy' ? '-' : '+' }}{{ money(t.net) }}</td>
              <td class="num muted">{{ t.order_id }}</td>
            </tr>
            <tr v-if="!state.myTrades.length"><td colspan="9" class="empty">لا توجد عمليات منفذة بعد</td></tr>
          </tbody>
        </table></div>
      </section>
    </template>
    <BankPage v-else-if="page === 'bank' && state.user" />
    <section v-else-if="page === 'holders'" class="panel">
      <h2>ملاك الأسهم
        <select class="pick" :value="state.selected" @change="select($event.target.value)">
          <option v-for="c in rows" :key="c.symbol" :value="c.symbol">{{ c.name }} ({{ c.symbol }})</option>
        </select>
        <small>أكبر مئة مالك في الشركة المختارة</small></h2>
      <div class="scroll"><table>
        <thead><tr><th>الترتيب</th><th>المالك</th><th>عدد الأسهم</th><th>القيمة السوقية</th><th>النسبة من الأسهم المصدرة</th></tr></thead>
        <tbody>
          <tr v-for="(h, i) in state.holders.holders" :key="h.holder">
            <td class="num muted">{{ i + 1 }}</td>
            <td><b>{{ h.holder }}</b> <small v-if="h.is_fund" class="tag">صندوق</small></td>
            <td class="num">{{ qty(h.qty) }}</td>
            <td class="num">{{ big(h.qty * (current?.last ?? 0)) }}</td>
            <td class="num">{{ (h.share_bps / 100).toFixed(2) }}%</td>
          </tr>
          <tr v-if="!state.holders.holders.length"><td colspan="5" class="empty">لا يوجد ملاك بعد</td></tr>
        </tbody>
      </table></div>
    </section>
    <section v-else-if="page === 'alltrades'" class="panel">
      <h2>صفقات السوق <small>كل الصفقات المنفذة في السوق، تتحدث تلقائيًا</small></h2>
      <div class="scroll"><table>
        <thead><tr><th>الوقت</th><th>الشركة</th><th>السعر</th><th>الكمية</th><th>القيمة</th><th>النوع</th></tr></thead>
        <tbody>
          <tr v-for="t in state.marketTrades" :key="t.id" class="fresh" @click="show(t.symbol)">
            <td class="num muted">{{ stamp(t.executed_at) }}</td>
            <td><b>{{ t.name }}</b><small class="num">{{ t.symbol }}</small></td>
            <td class="num" :class="t.taker_side === 'buy' ? 'up' : 'down'">{{ money(t.price) }}</td>
            <td class="num">{{ qty(t.qty) }}</td>
            <td class="num">{{ money(t.price * t.qty) }}</td>
            <td :class="t.taker_side === 'buy' ? 'up' : 'down'">{{ t.taker_side === 'buy' ? 'شراء' : 'بيع' }}</td>
          </tr>
          <tr v-if="!state.marketTrades.length"><td colspan="6" class="empty">لا توجد صفقات بعد</td></tr>
        </tbody>
      </table></div>
    </section>
    <section v-else-if="page === 'ipos'" class="panel">
      <h2>الاكتتابات</h2>
      <div class="scroll"><table>
        <thead><tr><th>الشركة</th><th>سعر الطرح</th><th>المطروح</th><th>التغطية</th><th>الحالة</th><th>اكتتابي</th></tr></thead>
        <tbody>
          <tr v-for="i in state.ipos" :key="i.id">
            <td><b>{{ i.name }}</b><small class="num">{{ i.symbol }}</small></td>
            <td class="num">{{ money(i.price) }}</td><td class="num">{{ qty(i.shares_offered) }}</td>
            <td class="num">{{ (i.coverage_bps / 100).toFixed(0) }}%</td>
            <td>{{ i.status === 'listed' ? 'أُدرجت' : i.accepting ? 'مفتوح' : 'أُغلق، بانتظار التخصيص' }}</td>
            <td class="pair">
              <template v-if="i.my_requested != null">
                <span class="num">طلبت {{ qty(i.my_requested) }}</span>
                <span v-if="i.my_allocated != null" class="num up">خُصص {{ qty(i.my_allocated) }}</span>
              </template>
              <template v-else-if="i.accepting && state.user">

                <input v-model.number="ipoQty[i.id]" type="number" :min="i.min_qty" :max="i.max_qty" class="sm" dir="ltr" :placeholder="i.min_qty + ' – ' + i.max_qty" />
                <button class="primary small" @click="subscribe(i)">اكتتب</button>
              </template>
              <button v-else-if="i.accepting" class="small" @click="state.showAuth = true">سجّل الدخول للاكتتاب</button>
              <span v-else class="muted">—</span>
            </td>
          </tr>
          <tr v-if="!state.ipos.length"><td colspan="6" class="empty">لا توجد اكتتابات</td></tr>
        </tbody>
      </table></div>
    </section>
    <section v-else-if="page === 'whales'" class="panel">
      <h2>الهوامير <small>ترتيب المتداولين بصافي الثروة</small></h2>
      <div class="scroll"><table>
        <thead><tr><th>#</th><th>المتداول</th><th>صافي الثروة</th><th>نقد</th><th>أسهم</th><th>عدد الشركات</th></tr></thead>
        <tbody>
          <tr v-for="w in state.whales" :key="w.user_id" :class="{ sel: w.user_id === state.user?.id }">
            <td class="num">{{ w.rank }}</td><td><b>{{ w.alias }}</b></td>
            <td class="num">{{ money(w.net_worth) }}</td><td class="num muted">{{ money(w.cash) }}</td>
            <td class="num muted">{{ money(w.stocks) }}</td><td class="num muted">{{ w.holdings }}</td>
          </tr>
          <tr v-if="!state.whales.length"><td colspan="6" class="empty">لا يوجد متداولون بعد</td></tr>
        </tbody>
      </table></div>
    </section>
    <section v-else-if="page === 'forum'" class="panel">
      <h2>المنتدى</h2>
      <div class="forum">
        <template v-if="state.thread">
          <button class="ghost" @click="openThread(null); reloadThreads()">→ كل المواضيع</button>
          <h3>{{ state.thread.thread.title }} <small class="muted">{{ state.thread.thread.alias }}<template v-if="state.thread.thread.company"> · {{ state.thread.thread.company }}</template></small></h3>
          <p class="post">{{ state.thread.thread.body }}</p>
          <p v-for="p in state.thread.posts" :key="p.id" class="post reply"><b>{{ p.alias }}</b> {{ p.body }}</p>
          <form v-if="state.user" class="row" @submit.prevent="postReply">
            <label class="grow">ردّك<input v-model="draft.reply" required maxlength="10000" /></label>
            <button class="primary small">إرسال الرد</button>
          </form>
        </template>
        <template v-else>
          <div class="row">
            <label class="check"><input v-model="forumSymbol" type="checkbox" /> مواضيع {{ current?.name }} فقط</label>
          </div>
          <table>
            <tbody>
              <tr v-for="t in state.threads" :key="t.id" @click="openThread(t.id)">
                <td><b>{{ t.title }}</b><small>{{ t.alias }}<template v-if="t.company"> · {{ t.company }}</template></small></td>
                <td class="num muted">{{ t.reply_count }} رد</td>
              </tr>
              <tr v-if="!state.threads.length"><td colspan="2" class="empty">لا توجد مواضيع بعد. اكتب أول تحليل.</td></tr>
            </tbody>
          </table>
          <form v-if="state.user" class="row" @submit.prevent="postThread">
            <label>العنوان<input v-model="draft.title" required minlength="3" maxlength="150" /></label>
            <label class="grow">التحليل<input v-model="draft.body" required maxlength="10000" /></label>
            <label class="check"><input v-model="draft.here" type="checkbox" /> عن {{ current?.name }}</label>
            <button class="primary small">نشر</button>
          </form>
          <p v-else class="hint">سجّل الدخول لتنشر تحليلًا أو تردّ.</p>
        </template>
      </div>
    </section>
  </main>
  </div>
  </div>

  <div class="toasts"><div v-for="t in state.toasts" :key="t.id" class="toast" :class="t.kind">{{ t.text }}<b v-if="t.count > 1" class="num" dir="ltr">×{{ t.count }}</b></div></div>
  <AuthModal v-if="state.showAuth" />
</template>
