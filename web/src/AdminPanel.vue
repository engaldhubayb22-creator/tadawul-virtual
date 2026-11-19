<script setup>
// Admin panel: market settings, the market maker, scenarios, halts and news.
// The engine applies changes within five seconds and the bot on its next cycle.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api, money, toast } from './store'

const data = ref(null)
const settings = reactive({})
const edits = reactive({}) // per symbol: fields being edited
const news = reactive({ symbol: '', title: '', impact: 0, minutes: 15 })
let timer = null

function apply(d) {
  data.value = d
  if (!Object.keys(settings).length) {
    Object.assign(settings, d.settings, { starting_sar: d.settings.starting_balance_halalas / 100 })
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
}, 'حُفظت الإعدادات')
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

onMounted(() => { refresh(); timer = setInterval(refresh, 5000) })
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
