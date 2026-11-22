<script setup>
// Companies: the list of every listed company, and one company's file with
// its share counts, price history, information and announcements.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { CandlestickSeries, HistogramSeries, createChart } from 'lightweight-charts'
import { api, isoTime, money, state, toast } from './store'

const emit = defineEmits(['trade'])
const symbol = ref('') // empty: the list
const query = ref('')
const sector = ref('')

const all = computed(() => state.symbols.map((s) => state.companies[s]))
const sectors = computed(() => [...new Set(all.value.map((c) => c.sector).filter(Boolean))].sort())
const shown = computed(() => {
  const q = query.value.trim()
  return all.value.filter((c) => (!sector.value || c.sector === sector.value) && (!q || c.name.includes(q) || c.symbol.includes(q)))
})
const live = computed(() => state.companies[symbol.value])

const qty = (n) => (n == null ? '—' : Number(n).toLocaleString('en-US'))
const pct = (bps) => (bps == null ? '—' : (bps > 0 ? '+' : '') + (bps / 100).toFixed(2) + '%')
const tone = (n) => (n > 0 ? 'up' : n < 0 ? 'down' : '')
const big = (h) => {
  if (h == null) return '—'
  const sar = h / 100
  return sar >= 1e9 ? (sar / 1e9).toFixed(2) + ' مليار' : sar >= 1e6 ? (sar / 1e6).toFixed(2) + ' مليون' : money(h)
}
const zone = () => state.market.timezone
const day = (s) => new Date(Date.parse(isoTime(s))).toLocaleDateString('en-GB', { timeZone: zone() })
const stamp = (s) => new Date(Date.parse(isoTime(s))).toLocaleString('en-GB', {
  timeZone: zone(), day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

// ---- one company
const RANGES = [
  { id: '1d', label: 'يوم' }, { id: '2d', label: 'يومان' }, { id: '1w', label: 'أسبوع' }, { id: '1m', label: 'شهر' },
  { id: '3m', label: '3 أشهر' }, { id: '6m', label: '6 أشهر' }, { id: '1y', label: 'سنة' }, { id: '2y', label: 'سنتان' },
  { id: 'all', label: 'الكل' },
]
const KIND = { positive: 'إيجابي', negative: 'سلبي', neutral: 'إعلان' }
const profile = ref(null)
const range = ref('1m')
const history = ref(null)
const el = ref(null)
let chart = null, candles = null, volume = null

// The chart draws times as UTC; shift so the axis reads in the market's time.
const RIYADH = 3 * 3600
function draw() {
  if (!history.value || !el.value) return
  if (!chart) {
    chart = createChart(el.value, {
      autoSize: true,
      layout: { background: { color: 'transparent' }, textColor: '#7d8a99', fontFamily: 'inherit' },
      grid: { vertLines: { color: '#1a222d' }, horzLines: { color: '#1a222d' } },
      rightPriceScale: { borderColor: '#1e2733' },
      timeScale: { borderColor: '#1e2733', secondsVisible: false },
      crosshair: { mode: 0 },
      localization: { locale: 'en-US' },
    })
    candles = chart.addSeries(CandlestickSeries, {
      upColor: '#16c784', downColor: '#ea3943', wickUpColor: '#16c784', wickDownColor: '#ea3943', borderVisible: false,
    })
    volume = chart.addSeries(HistogramSeries, { priceFormat: { type: 'volume' }, priceScaleId: 'vol' })
    chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } })
  }
  chart.timeScale().applyOptions({ timeVisible: history.value.candle !== 'day' })
  const rows = history.value.candles.map((c) => ({
    time: Math.floor(Date.parse(isoTime(c.bucket)) / 1000) + RIYADH,
    open: c.open / 100, high: c.high / 100, low: c.low / 100, close: c.close / 100, vol: c.volume,
  }))
  candles.setData(rows)
  volume.setData(rows.map((c) => ({ time: c.time, value: c.vol, color: c.close >= c.open ? '#16c78455' : '#ea394355' })))
  chart.timeScale().fitContent()
}
function dropChart() { chart?.remove(); chart = candles = volume = null }

async function loadHistory() {
  const wanted = symbol.value, r = range.value
  try {
    const data = await api('GET', `/market/${wanted}/history?range=${r}`)
    if (wanted !== symbol.value || r !== range.value) return
    history.value = data
    await nextTick()
    draw()
  } catch (e) { toast(e.message, 'bad') }
}
async function open(s) {
  dropChart()
  symbol.value = s; profile.value = null; history.value = null
  try {
    const data = await api('GET', `/market/${s}/profile`)
    if (s === symbol.value) profile.value = data
  } catch (e) { toast(e.message, 'bad') }
  loadHistory()
}
function back() { dropChart(); symbol.value = '' }
watch(range, loadHistory)
onBeforeUnmount(dropChart)

const span = computed(() => {
  const d = history.value?.days ?? []
  if (!d.length) return null
  const first = d.at(-1), last = d[0]
  return {
    high: Math.max(...d.map((x) => x.high)), low: Math.min(...d.map((x) => x.low)),
    volume: d.reduce((n, x) => n + x.volume, 0),
    change: first.open ? ((last.close - first.open) / first.open) * 10000 : null,
  }
})
</script>

<template>
  <!-- the list -->
  <main v-if="!symbol" class="page">
    <section class="panel">
      <h2>الشركات المدرجة <small>{{ shown.length }} شركة · اضغط على الشركة لفتح ملفها</small></h2>
      <div class="filters">
        <input v-model="query" placeholder="بحث بالاسم أو الرمز" />
        <select v-model="sector">
          <option value="">كل القطاعات</option>
          <option v-for="s in sectors" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <div class="scroll"><table>
        <thead><tr><th>الشركة</th><th>القطاع</th><th>آخر سعر</th><th>التغير</th><th>القيمة السوقية</th><th>الأسهم المصدرة</th><th>الأسهم الحرة</th></tr></thead>
        <tbody>
          <tr v-for="c in shown" :key="c.symbol" @click="open(c.symbol)">
            <td><b>{{ c.name }}</b><small class="num">{{ c.symbol }}</small></td>
            <td class="muted">{{ c.sector || '—' }}</td>
            <td class="num" :class="tone(c.change)">{{ money(c.last) }}</td>
            <td class="num" :class="tone(c.change)">{{ pct(c.change_bps) }}</td>
            <td class="num">{{ big(c.issued_shares * c.last) }}</td>
            <td class="num">{{ qty(c.issued_shares) }}</td>
            <td class="num">{{ qty(c.free_float) }}</td>
          </tr>
          <tr v-if="!shown.length"><td colspan="7" class="empty">لا توجد شركات مطابقة</td></tr>
        </tbody>
      </table></div>
    </section>
  </main>

  <!-- one company's file -->
  <main v-else class="page company">
    <header class="co-head">
      <button class="small" @click="back">→ كل الشركات</button>
      <div class="co-name">
        <b>{{ live?.name ?? profile?.name }}</b>
        <small><span class="num">{{ symbol }}</span><template v-if="profile?.sector"> · {{ profile.sector }}</template>
          <span v-if="profile?.status === 'halted'" class="down"> · موقوف عن التداول</span></small>
      </div>
      <div class="co-price">
        <b class="num" :class="tone(live?.change)">{{ money(live?.last) }}</b>
        <span class="num" :class="tone(live?.change)">{{ money(live?.change) }} ({{ pct(live?.change_bps) }})</span>
      </div>
      <button class="primary small" @click="emit('trade', symbol)">افتح في شاشة السوق</button>
    </header>

    <div v-if="profile" class="cards">
      <div class="card"><span>القيمة السوقية</span><b>{{ big(profile.shares_outstanding * (live?.last ?? 0)) }}</b></div>
      <div class="card"><span>الأسهم المصدرة</span><b class="num">{{ qty(profile.shares_outstanding) }}</b></div>
      <div class="card"><span>الأسهم الحرة</span><b class="num">{{ qty(profile.free_float) }}</b></div>
      <div class="card"><span>أسهم المؤسسين (غير متداولة)</span><b class="num">{{ qty(profile.founder_shares) }}</b></div>
    </div>

    <section class="panel">
      <h2>السعر التاريخي</h2>
      <div class="ranges">
        <button v-for="r in RANGES" :key="r.id" class="small" :class="{ on: range === r.id }" @click="range = r.id">{{ r.label }}</button>
      </div>
      <div ref="el" class="co-chart"></div>
      <p v-if="history && !history.candles.length" class="empty">لا توجد تداولات في هذه المدة.</p>
      <dl v-if="span" class="facts-grid">
        <div><dt>أعلى سعر في المدة</dt><dd class="num">{{ money(span.high) }}</dd></div>
        <div><dt>أدنى سعر في المدة</dt><dd class="num">{{ money(span.low) }}</dd></div>
        <div><dt>التغير في المدة</dt><dd class="num" :class="tone(span.change)">{{ pct(span.change) }}</dd></div>
        <div><dt>الكمية المتداولة</dt><dd class="num">{{ qty(span.volume) }}</dd></div>
        <div><dt>بداية السجل</dt><dd class="num">{{ history.first_day ? day(history.first_day) : '—' }}</dd></div>
      </dl>
    </section>

    <section v-if="history?.days.length" class="panel">
      <h2>البيانات اليومية <small>يوم بيوم في المدة المختارة، الأحدث أولًا</small></h2>
      <div class="scroll tall"><table>
        <thead><tr><th>التاريخ</th><th>الافتتاح</th><th>الأعلى</th><th>الأدنى</th><th>الإغلاق</th><th>التغير</th><th>الكمية</th></tr></thead>
        <tbody>
          <tr v-for="d in history.days" :key="d.bucket">
            <td class="num">{{ day(d.bucket) }}</td>
            <td class="num">{{ money(d.open) }}</td><td class="num">{{ money(d.high) }}</td><td class="num">{{ money(d.low) }}</td>
            <td class="num"><b>{{ money(d.close) }}</b></td>
            <td class="num" :class="tone(d.change_bps)">{{ pct(d.change_bps) }}</td>
            <td class="num muted">{{ qty(d.volume) }}</td>
          </tr>
        </tbody>
      </table></div>
    </section>

    <section v-if="profile" class="panel">
      <h2>معلومات الشركة</h2>
      <p v-if="profile.about" class="about">{{ profile.about }}</p>
      <p v-else class="empty">لم تُكتب نبذة عن الشركة بعد.</p>
      <dl class="facts-grid">
        <div><dt>القطاع</dt><dd>{{ profile.sector || '—' }}</dd></div>
        <div><dt>سنة التأسيس</dt><dd class="num">{{ profile.founded_year ?? '—' }}</dd></div>
        <div><dt>المقر</dt><dd>{{ profile.headquarters || '—' }}</dd></div>
        <div><dt>الموقع الإلكتروني</dt><dd class="num">{{ profile.website || '—' }}</dd></div>
        <div><dt>تاريخ الإدراج</dt><dd class="num">{{ profile.listed_at ? day(profile.listed_at) : '—' }}</dd></div>
        <div><dt>عدد الملاك</dt><dd class="num">{{ qty(profile.holders) }}</dd></div>
        <div><dt>القيمة الاسمية</dt><dd class="num">{{ money(profile.par_value) }}</dd></div>
        <div><dt>حقوق المساهمين</dt><dd>{{ big(profile.equity) }}</dd></div>
        <div><dt>صافي الربح (12 شهرًا)</dt><dd>{{ big(profile.net_income) }}</dd></div>
        <div><dt>ربحية السهم</dt><dd class="num">{{ money(live?.eps) }}</dd></div>
        <div><dt>مكرر الأرباح</dt><dd class="num">{{ live?.eps > 0 ? (live.last / live.eps).toFixed(2) : '—' }}</dd></div>
        <div><dt>القيمة الدفترية للسهم</dt><dd class="num">{{ money(live?.book_value) }}</dd></div>
        <div><dt>أعلى سعر في سنة</dt><dd class="num">{{ money(profile.year_high) }}</dd></div>
        <div><dt>أدنى سعر في سنة</dt><dd class="num">{{ money(profile.year_low) }}</dd></div>
      </dl>
    </section>

    <section v-if="profile" class="panel">
      <h2>إعلانات الشركة <small>{{ profile.announcements.length }} إعلان</small></h2>
      <ul class="announcements">
        <li v-for="n in profile.announcements" :key="n.id">
          <div><span class="tag" :class="n.kind === 'positive' ? 'up' : n.kind === 'negative' ? 'down' : ''">{{ KIND[n.kind] }}</span>
            <b>{{ n.title }}</b><small class="num muted">{{ stamp(n.created_at) }}</small></div>
          <p v-if="n.body">{{ n.body }}</p>
        </li>
        <li v-if="!profile.announcements.length" class="empty">لا توجد إعلانات لهذه الشركة بعد</li>
      </ul>
    </section>
  </main>
</template>
