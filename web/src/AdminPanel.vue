<script setup>
// Admin panel: market settings, the market maker, scenarios, halts and news.
// The engine applies changes within five seconds and the bot on its next cycle.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api, money, toast } from './store'

const data = ref(null)
const settings = reactive({})
const edits = reactive({}) // per symbol: fields being edited
const news = reactive({ symbol: '', title: '', impact: 0, minutes: 15 })
const ipo = reactive({ symbol: '', name: '', sector: '', price: '', tick: 0.01, offered: 100000, maker: 500000, min: 10, max: 5000, minutes: 60 })
const ipos = ref([])
const loadIpos = async () => { try { ipos.value = (await api('GET', '/ipos')).ipos } catch { /* shown on next refresh */ } }
async function createIpo() {
  try {
    await api('POST', '/admin/ipos', { symbol: ipo.symbol, name: ipo.name, sector: ipo.sector || null,
      price: Math.round(Number(ipo.price) * 100), tick_size: Math.round(Number(ipo.tick) * 100),
      shares_offered: ipo.offered, maker_shares: ipo.maker, min_qty: ipo.min, max_qty: ipo.max, minutes: ipo.minutes })
    toast('أُعلن الاكتتاب', 'good'); ipo.symbol = ''; ipo.name = ''
    loadIpos()
  } catch (e) { toast(e.message, 'bad') }
}
async function allocateIpo(i) {
  try {
    const r = await api('POST', `/admin/ipos/${i.id}/allocate`)
    toast(`خُصص ${r.sold} سهم لـ ${r.subscribers} مكتتب، وأُدرجت الشركة`, 'good')
    loadIpos(); refresh()
  } catch (e) { toast(e.message, 'bad') }
}
let timer = null

function apply(d) {
  data.value = d
  if (!Object.keys(settings).length) {
    Object.assign(settings, d.settings, { starting_sar: d.settings.starting_balance_halalas / 100 })
    settings.market_days = [...d.settings.market_days]
  }
  for (const c of d.companies) {
    edits[c.symbol] ??= { spread_bps: c.spread_bps, levels: c.levels, qty_min: c.qty_min, qty_max: c.qty_max,
      noise_pct: c.noise_pct, target: '', minutes: 15 }
  }
  if (!news.symbol && d.companies.length) news.symbol = d.companies[0].symbol
}

async function call(method, path, body, done) {
  try {
    apply(await api(method, '/admin' + path, body))
    if (done) toast(done, 'good')
  } catch (e) {
    toast(e.message, 'bad')
  }
}

const refresh = () => call('GET', '/overview')
const saveSettings = () => call('PUT', '/settings', {
  starting_balance_halalas: Math.round(Number(settings.starting_sar) * 100),
  market_open: settings.market_open, market_close: settings.market_close,
  market_hours_enforced: settings.market_hours_enforced,
  market_days: settings.market_days, commission_bps: Number(settings.commission_bps),
  financing_enabled: settings.financing_enabled, financing_fee_bps: settings.financing_fee_bps,
  financing_days: settings.financing_days, financing_max_ratio_bps: settings.financing_max_ratio_bps,
  margin_call_bps: settings.margin_call_bps, liquidation_bps: settings.liquidation_bps,
}, 'حُفظت الإعدادات')
const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
const toggleBots = () => call('PUT', '/settings', { bots_enabled: !data.value.settings.bots_enabled },
  data.value.settings.bots_enabled ? 'أُوقفت كل البوتات' : 'شُغّلت البوتات')
const saveBot = (c) => {
  const e = edits[c.symbol]
  call('PUT', '/bots/' + c.symbol, { spread_bps: e.spread_bps, levels: e.levels, qty_min: e.qty_min, qty_max: e.qty_max, noise_pct: e.noise_pct }, 'حُفظ ' + c.name)
}
const toggleBot = (c) => call('PUT', '/bots/' + c.symbol, { enabled: !c.enabled })
const startScenario = (c) => {
  const e = edits[c.symbol]
  call('PUT', '/bots/' + c.symbol, { target_price: Math.round(Number(e.target) * 100), target_minutes: e.minutes }, 'بدأ السيناريو على ' + c.name)
}
const stopScenario = (c) => call('PUT', '/bots/' + c.symbol, { target_price: null })
const toggleHalt = (c) => call('PUT', `/companies/${c.symbol}/status`, { status: c.status === 'halted' ? 'trading' : 'halted' })
const publish = async () => {
  await call('POST', '/news', { symbol: news.symbol, title: news.title, impact_bps: Math.round(Number(news.impact) * 100), minutes: news.minutes }, 'نُشر الخبر')
  news.title = ''
}

onMounted(() => { refresh(); loadIpos(); timer = setInterval(() => { refresh(); loadIpos() }, 5000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div v-if="data" class="admin">
    <section class="panel pad">
      <h3>السوق</h3>
      <div class="row">
        <label>الافتتاح<input v-model="settings.market_open" type="time" dir="ltr" /></label>
        <label>الإغلاق<input v-model="settings.market_close" type="time" dir="ltr" /></label>
        <label class="check"><input v-model="settings.market_hours_enforced" type="checkbox" /> تطبيق أوقات السوق</label>
        <label>الرصيد الابتدائي (ريال)<input v-model="settings.starting_sar" type="number" min="0" dir="ltr" /></label>
        <label>العمولة (نقطة أساس)<input v-model="settings.commission_bps" type="number" min="0" max="100" step="0.1" dir="ltr" /></label>
      </div>
      <div class="row">
        <span class="muted">أيام التداول</span>
        <label v-for="(d, i) in DAYS" :key="i" class="check"><input v-model="settings.market_days" type="checkbox" :value="i" /> {{ d }}</label>
      </div>
      <div class="row">
        <label class="check"><input v-model="settings.financing_enabled" type="checkbox" /> التمويل مفعّل</label>
        <label>رسم التمويل (نقطة أساس)<input v-model.number="settings.financing_fee_bps" type="number" min="0" dir="ltr" /></label>
        <label>مدة التمويل (يوم)<input v-model.number="settings.financing_days" type="number" min="1" dir="ltr" /></label>
        <label>حد التمويل من الحقوق (نقطة أساس)<input v-model.number="settings.financing_max_ratio_bps" type="number" min="0" dir="ltr" /></label>
        <label>تنبيه الهامش (نقطة أساس)<input v-model.number="settings.margin_call_bps" type="number" min="1" dir="ltr" /></label>
        <label>التسييل (نقطة أساس)<input v-model.number="settings.liquidation_bps" type="number" min="1" dir="ltr" /></label>
        <button class="primary" @click="saveSettings">حفظ</button>
      </div>
      <div class="stats">
        <span>المتداولون <b class="num">{{ data.stats.users }}</b></span>
        <span>أوامر مفتوحة <b class="num">{{ data.stats.open_orders }}</b></span>
        <span>صفقات اليوم <b class="num">{{ data.stats.trades_today }}</b></span>
        <span :class="data.stats.ledger_sum === 0 ? 'up' : 'down'">
          مطابقة الدفتر <b>{{ data.stats.ledger_sum === 0 ? 'سليمة' : 'فرق ' + money(data.stats.ledger_sum) }}</b></span>
      </div>
    </section>

    <section class="panel pad">
      <h3>صانع السوق
        <button :class="data.settings.bots_enabled ? 'danger' : 'primary'" @click="toggleBots">
          {{ data.settings.bots_enabled ? 'إيقاف كل البوتات' : 'تشغيل البوتات' }}</button>
      </h3>
      <div class="scroll">
        <table>
          <thead><tr>
            <th>السهم</th><th>آخر سعر</th><th>البوت</th><th>الفارق (نقطة أساس)</th><th>المستويات</th>
            <th>الكمية من – إلى</th><th>الضجيج %</th><th></th><th>سيناريو: السعر المستهدف / الدقائق</th><th>التداول</th>
          </tr></thead>
          <tbody>
            <tr v-for="c in data.companies" :key="c.symbol">
              <td><b>{{ c.name }}</b><small class="num">{{ c.symbol }}</small></td>
              <td class="num">{{ money(c.last) }}</td>
              <td><button :class="c.enabled ? 'on' : ''" @click="toggleBot(c)">{{ c.enabled ? 'يعمل' : 'متوقف' }}</button></td>
              <td><input v-model.number="edits[c.symbol].spread_bps" type="number" min="1" max="500" class="xs" dir="ltr" /></td>
              <td><input v-model.number="edits[c.symbol].levels" type="number" min="1" max="10" class="xs" dir="ltr" /></td>
              <td class="pair"><input v-model.number="edits[c.symbol].qty_min" type="number" min="1" class="sm" dir="ltr" />
                <input v-model.number="edits[c.symbol].qty_max" type="number" min="1" class="sm" dir="ltr" /></td>
              <td><input v-model.number="edits[c.symbol].noise_pct" type="number" min="0" max="100" class="xs" dir="ltr" /></td>
              <td><button @click="saveBot(c)">حفظ</button></td>
              <td class="pair">
                <template v-if="c.target_price">
                  <span class="num up">← {{ money(c.target_price) }}</span>
                  <button @click="stopScenario(c)">إيقاف</button>
                </template>
                <template v-else>
                  <input v-model="edits[c.symbol].target" type="number" :step="c.tick_size / 100" class="sm" dir="ltr"
                    :placeholder="money(c.low_limit) + ' – ' + money(c.high_limit)" />
                  <input v-model.number="edits[c.symbol].minutes" type="number" min="1" max="1440" class="xs" dir="ltr" />
                  <button :disabled="!edits[c.symbol].target" @click="startScenario(c)">ابدأ</button>
                </template>
              </td>
              <td><button :class="c.status === 'halted' ? 'danger' : ''" @click="toggleHalt(c)">
                {{ c.status === 'halted' ? 'موقوف — استئناف' : 'إيقاف' }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel pad">
      <h3>الاكتتابات</h3>
      <form class="row" @submit.prevent="createIpo">
        <label>الرمز<input v-model="ipo.symbol" required maxlength="8" class="sm" dir="ltr" /></label>
        <label class="grow">اسم الشركة<input v-model="ipo.name" required maxlength="100" /></label>
        <label>القطاع<input v-model="ipo.sector" maxlength="60" /></label>
        <label>سعر الطرح (ريال)<input v-model="ipo.price" type="number" min="0.01" step="0.01" required dir="ltr" /></label>
        <label>وحدة السعر<input v-model="ipo.tick" type="number" min="0.01" step="0.01" required dir="ltr" /></label>
        <label>الأسهم المطروحة<input v-model.number="ipo.offered" type="number" min="1" required dir="ltr" /></label>
        <label>حصة صانع السوق<input v-model.number="ipo.maker" type="number" min="0" required dir="ltr" /></label>
        <label>أقل كمية<input v-model.number="ipo.min" type="number" min="1" required dir="ltr" /></label>
        <label>أكبر كمية<input v-model.number="ipo.max" type="number" min="1" required dir="ltr" /></label>
        <label>مدة الاكتتاب (دقيقة)<input v-model.number="ipo.minutes" type="number" min="1" required dir="ltr" /></label>
        <button class="primary">إعلان الاكتتاب</button>
      </form>
      <table v-if="ipos.length">
        <thead><tr><th>الشركة</th><th>السعر</th><th>المطروح</th><th>المطلوب</th><th>المكتتبون</th><th>التغطية</th><th></th></tr></thead>
        <tbody>
          <tr v-for="i in ipos" :key="i.id">
            <td><b>{{ i.name }}</b><small class="num">{{ i.symbol }}</small></td>
            <td class="num">{{ money(i.price) }}</td><td class="num">{{ i.shares_offered }}</td>
            <td class="num">{{ i.requested }}</td><td class="num">{{ i.subscribers }}</td>
            <td class="num">{{ (i.coverage_bps / 100).toFixed(0) }}%</td>
            <td><button v-if="i.status === 'open'" class="primary small" @click="allocateIpo(i)">إغلاق وتخصيص وإدراج</button>
              <span v-else class="up">أُدرجت</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="panel pad">
      <h3>نشر خبر</h3>
      <form class="row" @submit.prevent="publish">
        <label>الشركة<select v-model="news.symbol"><option v-for="c in data.companies" :key="c.symbol" :value="c.symbol">{{ c.name }}</option></select></label>
        <label class="grow">العنوان<input v-model="news.title" required maxlength="200" /></label>
        <label>الأثر على السعر %<input v-model="news.impact" type="number" min="-10" max="10" step="0.1" dir="ltr" /></label>
        <label>خلال (دقيقة)<input v-model.number="news.minutes" type="number" min="1" max="1440" dir="ltr" /></label>
        <button class="primary">نشر</button>
      </form>
      <ul class="newslist">
        <li v-for="n in data.news" :key="n.id">{{ n.title }}
          <span class="num" :class="n.impact_bps > 0 ? 'up' : n.impact_bps < 0 ? 'down' : 'muted'">{{ (n.impact_bps / 100).toFixed(1) }}%</span></li>
      </ul>
    </section>
  </div>
  <p v-else class="empty">جارٍ التحميل…</p>
</template>
