<script setup>
// Admin: the maker's room. What the market maker reads in the market and in
// each share, the orders it plans, its alerts, and the admin's say over the
// direction. In watch mode the maker plans and places nothing.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api, isoTime, money, state, toast } from './store'

const room = ref(null)
const open = ref('')       // symbol with its details showing
const detail = ref(null)
const filter = reactive({ q: '', regime: '' })
const steer = reactive({ mode: 'oppose', hours: 24 })
const marketSteer = reactive({ mode: 'down', hours: 24 })
const showSettings = ref(false)
const settings = reactive({})

const REGIMES = {
  up_strong: 'صاعد قوي', up_tired: 'صاعد يفقد زخمه', range: 'عرضي', down: 'هابط', down_tired: 'هابط يهدأ',
  unclear: 'غير واضح', warming: 'غير مكتمل',
}
const TONE = { up_strong: 'up', up_tired: 'up', down: 'down', down_tired: 'down' }
const MODES = { auto: 'تلقائي (حسب التحليل)', follow: 'استمرار مع التحليل', oppose: 'مخالفة التحليل', up: 'صاعد', down: 'هابط', range: 'عرضي' }
const MOMENTUM = { building: 'يتسارع', fading: 'يضعف', steady: 'ثابت' }
const CLOUD = { above: 'فوق السحابة', below: 'تحت السحابة', inside: 'داخل السحابة' }
const count = (n) => Number(n ?? 0).toLocaleString('en-US')
const pct = (bps) => (bps == null ? '—' : (bps / 100).toFixed(1) + '%')
const stamp = (s) => (s ? new Date(Date.parse(isoTime(s))).toLocaleString('en-GB', {
  timeZone: state.market.timezone, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—')

const shown = computed(() => (room.value?.companies ?? []).filter((c) =>
  (!filter.regime || (filter.regime === 'flag' ? c.accumulation || c.wave || c.override : c.regime === filter.regime))
  && (!filter.q || c.name.includes(filter.q) || c.symbol.includes(filter.q))))
const tally = computed(() => {
  const t = {}
  for (const c of room.value?.companies ?? []) t[c.regime] = (t[c.regime] ?? 0) + 1
  return t
})

async function load() {
  try {
    room.value = await api('GET', '/admin/maker')
    if (!showSettings.value) Object.assign(settings, room.value.settings)
    if (open.value) detail.value = await api('GET', '/admin/maker/company/' + open.value)
  } catch (e) { toast(e.message, 'bad') }
}
async function run(action, done) {
  try { await action(); if (done) toast(done, 'good'); await load() } catch (e) { toast(e.message, 'bad') }
}
async function toggle(c) {
  if (open.value === c.symbol) { open.value = ''; detail.value = null; return }
  open.value = c.symbol; detail.value = null
  try { detail.value = await api('GET', '/admin/maker/company/' + c.symbol) } catch (e) { toast(e.message, 'bad') }
}
const analyse = () => run(() => api('POST', '/admin/maker/run'), 'حُدّث التحليل')
const setDirection = (symbol, mode, hours) => run(() => api('PUT', '/admin/maker/override', { symbol, mode, hours: Number(hours) }),
  mode === 'auto' ? 'رجع التوجه للتحليل' : 'تغيّر التوجه: ' + MODES[mode])
const setReserve = (symbol, value) => run(() => api('PUT', '/admin/maker/reserve/' + symbol, { open: value }),
  value ? 'فُتح الاحتياطي للبيع' : 'أُقفل الاحتياطي')
const answer = (a, action) => run(() => api('POST', '/admin/maker/alerts/' + a.id, { action, hours: 24 }),
  action === 'continue' ? 'الصانع يستمر مع الحركة 24 ساعة' : action === 'oppose' ? 'الصانع يخالف الحركة 24 ساعة' : null)
const saveSettings = () => run(() => api('PUT', '/admin/maker/settings', { ...settings }), 'حُفظت إعدادات الصانع')

let timer = null
onMounted(() => { load(); timer = setInterval(load, 30000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <template v-if="room">
    <section class="panel pad">
      <h3>غرفة الصانع
        <span class="mode">{{ room.settings.maker_brain === 'shadow' ? 'وضع المراقبة: الصانع يحلل ويخطط ولا ينفّذ أي أمر' : 'التحليل متوقف' }}</span>
        <button class="small" @click="analyse">حلّل الآن</button>
        <button class="small" @click="showSettings = !showSettings">{{ showSettings ? 'إخفاء الإعدادات' : 'إعدادات الصانع' }}</button>
      </h3>
      <form v-if="showSettings" class="row" @submit.prevent="saveSettings">
        <label>التحليل<select v-model="settings.maker_brain"><option value="shadow">مراقبة (يحلل ولا ينفّذ)</option><option value="off">متوقف</option></select></label>
        <label>الاحتياطي المقفل (نقطة أساس من الأسهم الحرة)<input v-model.number="settings.maker_reserve_bps" type="number" min="0" dir="ltr" /></label>
        <label>سقف البيع اليومي (نقطة أساس)<input v-model.number="settings.maker_sell_cap_bps" type="number" min="1" dir="ltr" /></label>
        <label>السقف وقت الموجة (نقطة أساس)<input v-model.number="settings.maker_wave_cap_bps" type="number" min="1" dir="ltr" /></label>
        <label>الموجة: أكثر من (جلسات صعود)<input v-model.number="settings.maker_wave_days" type="number" min="1" dir="ltr" /></label>
        <label>الموجة: ارتفاع تراكمي (نقطة أساس)<input v-model.number="settings.maker_wave_rise_bps" type="number" min="100" dir="ltr" /></label>
        <label>التجميع: ملكية عضو (نقطة أساس)<input v-model.number="settings.maker_accum_holder_bps" type="number" min="100" dir="ltr" /></label>
        <label>التجميع: حصة من تداول اليوم (نقطة أساس)<input v-model.number="settings.maker_accum_volume_bps" type="number" min="100" dir="ltr" /></label>
        <label>هدف الشراء وقت التجميع (نقطة أساس مما باع)<input v-model.number="settings.maker_accum_buy_bps" type="number" min="10000" dir="ltr" /></label>
        <label>ثبات القراءة (عدد مرات التحليل)<input v-model.number="settings.maker_confirm_runs" type="number" min="1" dir="ltr" /></label>
        <button class="primary small">حفظ</button>
        <span class="muted">100 نقطة أساس = 1%</span>
      </form>

      <!-- the market as a whole -->
      <div v-if="room.market" class="mk-market">
        <div><span class="muted">اتجاه السوق</span>
          <b :class="TONE[room.market.regime]">{{ REGIMES[room.market.regime] }}</b>
          <span v-if="room.market.data.override" class="flag">يدوي حتى {{ stamp(room.market.data.override.expires_at) }}</span>
          <small v-if="room.market.regime !== room.market.auto_regime" class="muted">التحليل يقول: {{ REGIMES[room.market.auto_regime] }}</small></div>
        <div><span class="muted">اتساع السوق</span><b class="num">{{ pct(room.market.data.reading.breadth_bps) }}</b><small class="muted">من الأسهم فوق متوسطها</small></div>
        <div v-if="room.market.data.reading.rsi != null"><span class="muted">مؤشرات المؤشر العام</span>
          <b class="num">RSI {{ room.market.data.reading.rsi }} · ADX {{ room.market.data.reading.adx }}</b>
          <small class="muted">{{ CLOUD[room.market.data.reading.cloud.position] }} · الزخم {{ MOMENTUM[room.market.data.reading.macd.momentum] }}</small></div>
        <form class="row" @submit.prevent="setDirection(null, marketSteer.mode, marketSteer.hours)">
          <label>غيّر توجه السوق<select v-model="marketSteer.mode"><option v-for="(l, m) in MODES" :key="m" :value="m">{{ l }}</option></select></label>
          <label>لمدة (ساعة)<input v-model.number="marketSteer.hours" type="number" min="1" max="720" dir="ltr" class="narrow" /></label>
          <button class="small primary">طبّق</button>
        </form>
      </div>
      <p v-else class="empty">لم يُجرَ أي تحليل بعد. اضغط "حلّل الآن".</p>
    </section>

    <!-- alerts -->
    <section class="panel pad">
      <h3>تنبيهات الصانع <span class="muted">{{ room.alerts.length }} تنبيه مفتوح</span></h3>
      <ul class="mk-alerts">
        <li v-for="a in room.alerts" :key="a.id">
          <div><b>{{ a.title }}</b><small class="num muted">{{ stamp(a.created_at) }}</small></div>
          <p v-if="a.is_pattern" class="muted">النمط المتوقع: {{ a.details.expect }}</p>
          <div class="acts">
            <template v-if="a.is_pattern">
              <button class="small" @click="answer(a, 'continue')">استمرار مع الحركة</button>
              <button class="small" @click="answer(a, 'oppose')">مخالفة الحركة</button>
            </template>
            <button class="small" @click="answer(a, 'dismiss')">{{ a.is_pattern ? 'اترك القرار للتحليل' : 'تم الاطلاع' }}</button>
          </div>
        </li>
        <li v-if="!room.alerts.length" class="empty">لا توجد تنبيهات مفتوحة</li>
      </ul>
    </section>

    <!-- every share -->
    <section class="panel pad">
      <h3>الأسهم <span class="muted">{{ shown.length }} من {{ room.companies.length }}</span>
        <input v-model="filter.q" class="search" placeholder="بحث بالاسم أو الرمز" /></h3>
      <div class="ranges">
        <button class="small" :class="{ on: !filter.regime }" @click="filter.regime = ''">الكل</button>
        <button v-for="(label, r) in REGIMES" :key="r" class="small" :class="{ on: filter.regime === r }" @click="filter.regime = r">{{ label }} {{ tally[r] ?? 0 }}</button>
        <button class="small" :class="{ on: filter.regime === 'flag' }" @click="filter.regime = 'flag'">تجميع أو موجة أو يدوي</button>
      </div>
      <div class="scroll">
        <table>
          <thead><tr><th>السهم</th><th>نوع الحركة</th><th>مع السوق؟</th><th>صافي شراء الأعضاء اليوم</th><th>يطلب</th><th>يعرض</th><th>لماذا</th><th></th></tr></thead>
          <tbody>
            <template v-for="c in shown" :key="c.symbol">
              <tr>
                <td><b>{{ c.name }}</b><small class="num">{{ c.symbol }} · {{ money(c.last) }}</small></td>
                <td><b :class="TONE[c.regime]">{{ REGIMES[c.regime] }}</b>
                  <span v-if="c.override" class="flag">يدوي</span><span v-if="c.accumulation" class="flag off">تجميع</span><span v-if="c.wave" class="flag off">موجة</span></td>
                <td class="muted">{{ c.follows_market == null ? '—' : c.follows_market ? 'يتبع السوق' : 'مستقل' }} <small v-if="c.corr != null" class="num">{{ c.corr }}</small></td>
                <td class="num" :class="c.member_net > 0 ? 'up' : c.member_net < 0 ? 'down' : 'muted'">{{ count(c.member_net) }}</td>
                <td class="num">{{ count(c.bid_qty) }}</td><td class="num">{{ count(c.ask_qty) }}</td>
                <td class="why">{{ c.reason }}</td>
                <td><button class="small" @click="toggle(c)">{{ open === c.symbol ? 'إغلاق' : 'تفاصيل' }}</button></td>
              </tr>
              <tr v-if="open === c.symbol" class="editrow">
                <td colspan="8">
                  <p v-if="!detail" class="muted">جارٍ التحميل…</p>
                  <div v-else class="mk-detail">
                    <dl v-if="detail.data.reading.rsi != null" class="facts-grid">
                      <div><dt>المتوسطان 20 و 50</dt><dd class="num">{{ money(detail.data.reading.ema20) }} / {{ money(detail.data.reading.ema50) }}</dd></div>
                      <div><dt>السحابة</dt><dd>{{ CLOUD[detail.data.reading.cloud.position] }}</dd></div>
                      <div><dt>MACD</dt><dd>الزخم {{ MOMENTUM[detail.data.reading.macd.momentum] }}</dd></div>
                      <div><dt>RSI</dt><dd class="num">{{ detail.data.reading.rsi }}</dd></div>
                      <div><dt>ستوكاستيك</dt><dd class="num">{{ detail.data.reading.stoch.k }} / {{ detail.data.reading.stoch.d }}</dd></div>
                      <div><dt>قوة الاتجاه ADX</dt><dd class="num">{{ detail.data.reading.adx }}</dd></div>
                      <div><dt>مدى التذبذب ATR</dt><dd class="num">{{ money(detail.data.reading.atr) }}</dd></div>
                      <div><dt>العلاقة بالسوق</dt><dd class="num">{{ detail.data.reading.relation.corr ?? '—' }} · بيتا {{ detail.data.reading.relation.beta ?? '—' }}</dd></div>
                    </dl>
                    <p v-else class="muted">التحليل الفني يكتمل بعد {{ detail.data.reading.ready_in }} شمعة ساعة. الصانع على أوامره المعتادة حتى ذلك.</p>
                    <dl class="facts-grid">
                      <div><dt>يملك الصندوق</dt><dd class="num">{{ count(detail.plan.fund.holding) }}</dd></div>
                      <div><dt>الاحتياطي المقفل</dt><dd class="num">{{ count(detail.plan.fund.reserve) }} {{ detail.plan.fund.reserve_open ? '(مفتوح)' : '' }}</dd></div>
                      <div><dt>سقف البيع اليوم</dt><dd class="num">{{ count(detail.plan.fund.cap) }}</dd></div>
                      <div><dt>باع اليوم / المتبقي</dt><dd class="num">{{ count(detail.plan.fund.sold) }} / {{ count(detail.plan.fund.left) }}</dd></div>
                      <div><dt>اشترى اليوم</dt><dd class="num">{{ count(detail.plan.fund.bought) }}</dd></div>
                      <div><dt>مشترون / بائعون من الأعضاء</dt><dd class="num">{{ detail.data.flow.buyers }} / {{ detail.data.flow.sellers }}</dd></div>
                      <div v-if="detail.data.accumulation"><dt>تجميع من</dt><dd>{{ detail.data.accumulation.alias }} ({{ pct(detail.data.accumulation.share_bps) }})</dd></div>
                      <div v-if="detail.data.accumulation"><dt>هدف الشراء / تم</dt><dd class="num">{{ count(detail.data.accumulation.buy_target) }} / {{ count(detail.data.accumulation.bought) }}</dd></div>
                      <div v-if="detail.data.wave"><dt>موجة بلا خبر</dt><dd>{{ detail.data.wave.days }} جلسات، {{ pct(detail.data.wave.rise_bps) }}</dd></div>
                    </dl>
                    <div class="mk-plan">
                      <div><h4>الطلبات المخططة (شراء)</h4>
                        <table><tbody><tr v-for="b in detail.plan.bids" :key="b.price"><td class="num up">{{ money(b.price) }}</td><td class="num">{{ count(b.qty) }}</td></tr>
                          <tr v-if="!detail.plan.bids.length"><td class="empty">لا طلبات</td></tr></tbody></table></div>
                      <div><h4>العروض المخططة (بيع)</h4>
                        <table><tbody><tr v-for="a in detail.plan.asks" :key="a.price"><td class="num down">{{ money(a.price) }}</td><td class="num">{{ count(a.qty) }}</td></tr>
                          <tr v-if="!detail.plan.asks.length"><td class="empty">لا عروض</td></tr></tbody></table></div>
                      <div><h4>تعديل الأوامر</h4>
                        <p>يعدّل أوامره إذا تحرك السعر <b class="num">{{ money(detail.plan.requote.after_move) }}</b> أو تغيّرت القراءة.</p>
                        <p>{{ detail.plan.requote.follow_price_up ? 'يرفع طلباته مع صعود السعر.' : 'لا يرفع طلباته مع صعود السعر.' }}</p>
                        <p v-if="detail.regime !== detail.auto_regime" class="muted">التحليل يقول: {{ detail.auto_label }}</p></div>
                    </div>
                    <form class="row" @submit.prevent="setDirection(c.symbol, steer.mode, steer.hours)">
                      <label>غيّر توجه السهم<select v-model="steer.mode"><option v-for="(l, m) in MODES" :key="m" :value="m">{{ l }}</option></select></label>
                      <label>لمدة (ساعة)<input v-model.number="steer.hours" type="number" min="1" max="720" dir="ltr" class="narrow" /></label>
                      <button class="small primary">طبّق</button>
                      <span v-if="detail.data.override" class="muted">توجه يدوي ({{ MODES[detail.data.override.mode] }}) حتى {{ stamp(detail.data.override.expires_at) }}</span>
                      <button type="button" class="small" :class="{ danger: !detail.reserve_open }" @click="setReserve(c.symbol, !detail.reserve_open)">
                        {{ detail.reserve_open ? 'أقفل الاحتياطي' : 'افتح الاحتياطي للبيع' }}</button>
                    </form>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!shown.length"><td colspan="8" class="empty">لا توجد أسهم مطابقة</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </template>
</template>
