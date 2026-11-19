<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AuthModal from './AuthModal.vue'
import ChartPanel from './ChartPanel.vue'
import {
  cancelOrder, connect, loadMarket, logout, money, pct, placeOrder, qty, restoreSession, select, state, toast,
} from './store'

const rows = computed(() => state.symbols.map((s) => state.companies[s]))
const current = computed(() => state.companies[state.selected] || null)
const tab = ref('orders')

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

onMounted(async () => {
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
        <span class="alias">{{ state.user.alias }}</span>
        <button class="ghost" @click="logout()">خروج</button>
      </template>
      <button v-else class="primary small" @click="state.showAuth = true">دخول / تسجيل</button>
    </div>
  </header>

  <main class="grid">
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
        <span v-if="state.user" class="worth">صافي الثروة <b class="num">{{ money(state.netWorth) }}</b>
          · محجوز <span class="num">{{ money(state.balances.reserved) }}</span></span>
      </div>
      <p v-if="!state.user" class="empty">سجّل الدخول لتبدأ التداول برصيدك الافتراضي.</p>
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
