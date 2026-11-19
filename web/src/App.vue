<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AdminPanel from './AdminPanel.vue'
import AuthModal from './AuthModal.vue'
import ChartPanel from './ChartPanel.vue'
import {
  cancelOrder, connect, loadAccount, loadHolders, loadIpos, loadMarket, loadNews, loadWhales, logout, money, pct, placeOrder, qty,
  restoreSession, select, state, subscribeIpo, toast,
} from './store'

const rows = computed(() => state.symbols.map((s) => state.companies[s]))
const current = computed(() => state.companies[state.selected] || null)
const tab = ref('orders')
const view = ref('trade') // 'trade' or 'admin'
const isAdmin = computed(() => state.user?.role === 'admin')
watch(isAdmin, (a) => { if (!a) view.value = 'trade' })

// ---- market clock (Riyadh time)
const now = ref(Date.now())
setInterval(() => (now.value = Date.now()), 15000)
const marketOpen = computed(() => {
  const hm = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: state.market.timezone })
    .format(now.value)
  return hm >= state.market.open && hm < state.market.close
})

// ---- order ticket
const ticket = reactive({ side: 'buy', kind: 'limit', price: '', qty: 100 })
const busy = ref(false)
watch(() => state.selected, () => { ticket.price = current.value ? (current.value.last / 100).toFixed(2) : '' })
watch(current, (c) => { if (c && ticket.price === '') ticket.price = (c.last / 100).toFixed(2) })

// Price step in riyals. The input's minimum is the same value so every
// price on the tick grid passes the browser's own validation.
const tick = computed(() => (current.value?.tick_size || 1) / 100)
const priceHalalas = computed(() => Math.round(Number(ticket.price) * 100))
const estimate = computed(() => {
  const p = ticket.kind === 'market' ? (current.value?.last || 0) : priceHalalas.value
  return p * Number(ticket.qty || 0)
})

function pick(price, side) {
  ticket.kind = 'limit'
  ticket.price = (price / 100).toFixed(2)
  if (side) ticket.side = side
}

async function submit() {
  if (!state.token) { state.showAuth = true; return }
  busy.value = true
  try {
    const order = { symbol: state.selected, side: ticket.side, kind: ticket.kind, qty: Number(ticket.qty) }
    if (ticket.kind === 'limit') order.price = priceHalalas.value
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
const time = (ms) => new Date(ms).toLocaleTimeString('en-GB', { timeZone: state.market.timezone })

// ---- news, whales and top holders: refreshed on a slow timer while visible
function refreshTab() {
  // Allocations and refunds change the account without any order event, so
  // the account tabs reload on their own too.
  if (state.token && ['orders', 'positions', 'ipos'].includes(tab.value)) loadAccount().catch(() => {})
  if (tab.value === 'whales') loadWhales().catch(() => {})
  if (tab.value === 'ipos') {
    loadIpos().catch(() => {})
    // A listed IPO adds a company to the market: pick it up.
    if (state.ipos.some((i) => i.status === 'listed' && !state.companies[i.symbol])) loadMarket().catch(() => {})
  }
  if (tab.value === 'holders' && state.selected) loadHolders(state.selected).catch(() => {})
}
watch([tab, () => state.selected], refreshTab)
setInterval(refreshTab, 10000)
setInterval(() => loadNews().catch(() => {}), 30000)
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
  connect()
  try { await loadMarket() } catch (e) { toast(e.message, 'bad') }
  restoreSession()
})
</script>

<template>
  <header class="top">
    <div class="brand">تداول الافتراضي <span class="note">أسعار افتراضية لا تمثل السوق الحقيقي</span></div>
    <div class="status">
      <span class="pill" :class="marketOpen ? 'good' : 'bad'">
        {{ marketOpen ? 'السوق مفتوح' : 'السوق مغلق' }} · {{ state.market.open }}–{{ state.market.close }}
      </span>
      <span class="pill" :class="state.connected ? 'good' : 'bad'">{{ state.connected ? 'بث مباشر' : 'يعيد الاتصال…' }}</span>
      <template v-if="state.user">
        <span class="cash">النقد <b class="num">{{ money(state.balances.cash) }}</b></span>
        <button v-if="isAdmin" class="small" :class="{ primary: view === 'admin' }" @click="view = view === 'admin' ? 'trade' : 'admin'">
          {{ view === 'admin' ? 'شاشة التداول' : 'لوحة الإدارة' }}</button>
        <span class="alias">{{ state.user.alias }}</span>
        <button class="ghost" @click="logout()">خروج</button>
      </template>
      <button v-else class="primary small" @click="state.showAuth = true">دخول / تسجيل</button>
    </div>
  </header>

  <div v-if="latestNews.length && view === 'trade'" class="newsbar">
    <b>الأخبار</b>
    <button v-for="n in latestNews" :key="n.id" class="newsitem" :title="n.body" @click="n.symbol && select(n.symbol)">
      <span v-if="n.name" class="muted">{{ n.name }}:</span> {{ n.title }}
    </button>
  </div>
  <AdminPanel v-if="view === 'admin' && isAdmin" />
  <main v-else class="grid">
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
      <ChartPanel @pick="(p) => pick(p)" />
    </section>

    <!-- depth + ticket -->
    <section class="panel side">
      <h2>عمق السوق <small>اضغط على السعر للتداول</small></h2>
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

      <form class="ticket" @submit.prevent="submit">
        <div class="seg">
          <button type="button" class="buy" :class="{ on: ticket.side === 'buy' }" @click="ticket.side = 'buy'">شراء</button>
          <button type="button" class="sell" :class="{ on: ticket.side === 'sell' }" @click="ticket.side = 'sell'">بيع</button>
        </div>
        <div class="seg thin">
          <button type="button" :class="{ on: ticket.kind === 'limit' }" @click="ticket.kind = 'limit'">محدد</button>
          <button type="button" :class="{ on: ticket.kind === 'market' }" @click="ticket.kind = 'market'">سوق</button>
        </div>
        <label v-if="ticket.kind === 'limit'">السعر (ريال)
          <input v-model="ticket.price" type="number" :min="tick" :step="tick" required dir="ltr" />
        </label>
        <label>الكمية<input v-model="ticket.qty" type="number" min="1" step="1" required dir="ltr" /></label>
        <div class="est">القيمة التقديرية <b class="num">{{ money(estimate) }}</b></div>
        <button class="go" :class="ticket.side" :disabled="busy || !state.selected">
          {{ ticket.side === 'buy' ? 'شراء' : 'بيع' }} {{ current?.name }}
        </button>
      </form>
    </section>

    <!-- tape -->
    <section class="panel tape">
      <h2>الصفقات</h2>
      <div class="rows">
        <div v-for="(t, i) in state.trades" :key="t.at + '-' + i" class="trow">
          <span class="num" :class="t.side === 'buy' ? 'up' : 'down'">{{ money(t.price) }}</span>
          <span class="num">{{ qty(t.qty) }}</span>
          <span class="num muted">{{ time(t.at) }}</span>
        </div>
        <p v-if="!state.trades.length" class="empty">لا توجد صفقات بعد</p>
      </div>
    </section>

    <!-- account -->
    <section class="panel account">
      <div class="tabs">
        <button :class="{ on: tab === 'orders' }" @click="tab = 'orders'">أوامري المفتوحة ({{ state.orders.length }})</button>
        <button :class="{ on: tab === 'positions' }" @click="tab = 'positions'">محفظتي</button>
        <button :class="{ on: tab === 'whales' }" @click="tab = 'whales'">الهوامير</button>
        <button :class="{ on: tab === 'holders' }" @click="tab = 'holders'">كبار الملاك</button>
        <button :class="{ on: tab === 'ipos' }" @click="tab = 'ipos'">الاكتتابات</button>
        <span v-if="state.user" class="worth">صافي الثروة <b class="num">{{ money(state.netWorth) }}</b>
          · محجوز <span class="num">{{ money(state.balances.reserved) }}</span></span>
      </div>
      <table v-if="tab === 'whales'">
        <thead><tr><th>#</th><th>المتداول</th><th>صافي الثروة</th><th>نقد</th><th>أسهم</th><th>عدد الشركات</th></tr></thead>
        <tbody>
          <tr v-for="w in state.whales" :key="w.user_id" :class="{ sel: w.user_id === state.user?.id }">
            <td class="num">{{ w.rank }}</td><td><b>{{ w.alias }}</b></td>
            <td class="num">{{ money(w.net_worth) }}</td><td class="num muted">{{ money(w.cash) }}</td>
            <td class="num muted">{{ money(w.stocks) }}</td><td class="num muted">{{ w.holdings }}</td>
          </tr>
          <tr v-if="!state.whales.length"><td colspan="6" class="empty">لا يوجد متداولون بعد</td></tr>
        </tbody>
      </table>
      <table v-else-if="tab === 'ipos'">
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
              <template v-else-if="i.accepting">
                <input v-model.number="ipoQty[i.id]" type="number" :min="i.min_qty" :max="i.max_qty" class="sm" dir="ltr" :placeholder="i.min_qty + ' – ' + i.max_qty" />
                <button class="primary small" @click="subscribe(i)">اكتتب</button>
              </template>
              <span v-else class="muted">—</span>
            </td>
          </tr>
          <tr v-if="!state.ipos.length"><td colspan="6" class="empty">لا توجد اكتتابات</td></tr>
        </tbody>
      </table>
      <table v-else-if="tab === 'holders'">
        <thead><tr><th>المالك في {{ current?.name }}</th><th>الكمية</th><th>النسبة من الأسهم المملوكة</th></tr></thead>
        <tbody>
          <tr v-for="h in state.holders.holders" :key="h.holder">
            <td><b>{{ h.holder }}</b> <small v-if="h.is_fund" class="tag">صندوق</small></td>
            <td class="num">{{ qty(h.qty) }}</td>
            <td class="num">{{ (h.share_bps / 100).toFixed(2) }}%</td>
          </tr>
          <tr v-if="!state.holders.holders.length"><td colspan="3" class="empty">لا يوجد ملاك بعد</td></tr>
        </tbody>
      </table>
      <p v-else-if="!state.user" class="empty">سجّل الدخول لتبدأ التداول برصيدك الافتراضي.</p>
      <table v-else-if="tab === 'orders'">
        <thead><tr><th>السهم</th><th>الاتجاه</th><th>السعر</th><th>الكمية</th><th>المنفّذ</th><th></th></tr></thead>
        <tbody>
          <tr v-for="o in state.orders" :key="o.id">
            <td class="num">{{ o.symbol }}</td>
            <td :class="o.side === 'buy' ? 'up' : 'down'">{{ o.side === 'buy' ? 'شراء' : 'بيع' }}</td>
            <td class="num">{{ money(o.price) }}</td><td class="num">{{ qty(o.qty) }}</td><td class="num">{{ qty(o.filled_qty) }}</td>
            <td><button class="ghost" @click="cancel(o.id)">إلغاء</button></td>
          </tr>
          <tr v-if="!state.orders.length"><td colspan="6" class="empty">لا توجد أوامر مفتوحة</td></tr>
        </tbody>
      </table>
      <table v-else>
        <thead><tr><th>السهم</th><th>الكمية</th><th>متوسط التكلفة</th><th>آخر سعر</th><th>القيمة</th><th>الربح / الخسارة</th></tr></thead>
        <tbody>
          <tr v-for="p in state.positions" :key="p.symbol" @click="select(p.symbol)">
            <td><b>{{ p.name }}</b></td><td class="num">{{ qty(p.qty) }}</td><td class="num">{{ money(p.avg_cost) }}</td>
            <td class="num">{{ money(state.companies[p.symbol]?.last ?? p.last) }}</td>
            <td class="num">{{ money(p.market_value) }}</td>
            <td class="num" :class="p.unrealized_pnl > 0 ? 'up' : p.unrealized_pnl < 0 ? 'down' : ''">{{ money(p.unrealized_pnl) }}</td>
          </tr>
          <tr v-if="!state.positions.length"><td colspan="6" class="empty">لا تملك أسهمًا بعد</td></tr>
        </tbody>
      </table>
    </section>
  </main>

  <div class="toasts"><div v-for="t in state.toasts" :key="t.id" class="toast" :class="t.kind">{{ t.text }}</div></div>
  <AuthModal v-if="state.showAuth" />
</template>
