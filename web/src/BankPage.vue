<script setup>
// The bank: balances, the margin facility, loans against pledged shares and
// the account statement.
import { computed, reactive, ref, watch } from 'vue'
import {
  isoTime, loadAccount, loadBank, loadPledges, money, openPledge, repayFinancing, repayPledge,
  requestFinancing, state, toast,
} from './store'

const tab = ref('overview')
const TABS = [
  { id: 'overview', label: 'نظرة عامة' },
  { id: 'facility', label: 'التسهيلات' },
  { id: 'loans', label: 'قرض بضمان الأسهم' },
  { id: 'pledged', label: 'الأسهم المرهونة' },
  { id: 'statement', label: 'كشف الحساب' },
]

const fin = computed(() => state.financing)
const pledges = computed(() => state.pledges)
const loans = computed(() => pledges.value?.loans ?? [])
const facilityDebt = computed(() => fin.value?.debt ?? 0)
const loanDebt = computed(() => pledges.value?.debt ?? 0)
const accountNo = computed(() => 'TV-' + String(state.user?.id ?? 0).padStart(8, '0'))

const qty = (n) => Number(n).toLocaleString('en-US')
const pct = (bps) => (bps == null ? '—' : (bps / 100).toFixed(2) + '%')
const day = (s) => new Date(Date.parse(isoTime(s))).toLocaleDateString('en-GB', { timeZone: state.market.timezone })
const stamp = (s) => new Date(Date.parse(isoTime(s))).toLocaleString('en-GB', {
  timeZone: state.market.timezone, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const dueIn = (days) => new Date(Date.now() + days * 86400000).toLocaleDateString('en-GB', { timeZone: state.market.timezone })
const halalas = (riyals) => Math.round(Number(riyals) * 100)

async function run(action, done) {
  try {
    await action()
    toast(done, 'good')
    await Promise.all([loadAccount(), loadBank(), loadPledges()])
  } catch (e) { toast(e.message, 'bad') }
}

// ---- margin facility
const facilityAmount = ref('')
const facilityFee = computed(() => Math.floor((halalas(facilityAmount.value) * (fin.value?.terms.fee_bps ?? 0)) / 10000))
const borrow = () => run(async () => { await requestFinancing(halalas(facilityAmount.value)); facilityAmount.value = '' }, 'صُرف مبلغ التسهيلات في حسابك')
const repayFacility = () => run(repayFinancing, 'سُدّدت التسهيلات')
// Where the margin sits between the liquidation level and a comfortable 60%.
const marginFill = computed(() => Math.max(0, Math.min(100, ((fin.value?.margin_bps ?? 0) / 6000) * 100)))

// ---- loan against pledged shares
const form = reactive({ symbol: '', qty: '', amount: '' })
const eligible = computed(() => pledges.value?.eligible ?? [])
const chosen = computed(() => eligible.value.find((e) => e.symbol === form.symbol))
watch(eligible, (list) => { if (!list.some((e) => e.symbol === form.symbol)) form.symbol = list[0]?.symbol ?? '' }, { immediate: true })
const pledgeValue = computed(() => (chosen.value ? Math.max(0, Math.floor(Number(form.qty) || 0)) * chosen.value.last : 0))
// principal + fee may reach value * ltv, the same rule the bank applies.
const maxLoan = computed(() => {
  const t = pledges.value?.terms
  return t ? Math.floor((Math.floor((pledgeValue.value * t.ltv_bps) / 10000) * 10000) / (10000 + t.fee_bps)) : 0
})
const loanFee = computed(() => Math.floor((halalas(form.amount) * (pledges.value?.terms.fee_bps ?? 0)) / 10000))
const loanLtv = computed(() => (pledgeValue.value > 0 ? ((halalas(form.amount) + loanFee.value) * 10000) / pledgeValue.value : null))
const takeLoan = () => run(async () => {
  await openPledge(form.symbol, Math.floor(Number(form.qty)), halalas(form.amount))
  form.qty = ''; form.amount = ''
}, 'رُهنت الأسهم وصُرف القرض في حسابك')
const repayLoan = (l) => run(() => repayPledge(l.id), 'سُدّد القرض وفُكّ رهن الأسهم')
// The price at which the bank takes the shares: debt reaches foreclose_bps of their value.
const foreclosePrice = (l) => Math.ceil((l.debt * 10000) / (pledges.value.terms.foreclose_bps * l.qty))
const ltvFill = (l) => Math.max(0, Math.min(100, ((l.ltv_bps ?? 0) / pledges.value.terms.foreclose_bps) * 100))

// ---- obligations: everything owed, soonest first
const obligations = computed(() => {
  const list = loans.value.map((l) => ({ key: 'l' + l.id, kind: 'قرض بضمان ' + l.name, amount: l.debt, due: l.due_at, warn: l.warned }))
  const f = fin.value?.financing
  if (f) list.push({ key: 'f', kind: 'تسهيلات الهامش', amount: facilityDebt.value, due: f.due_at, warn: f.margin_called || f.status === 'liquidating' })
  return list.sort((a, b) => Date.parse(isoTime(a.due)) - Date.parse(isoTime(b.due)))
})

// ---- statement wording
const ENTRY = {
  starting_balance: 'الرصيد الابتدائي', grant: 'إيداع', commission: 'عمولة تداول',
  admin_deposit: 'إيداع من الإدارة', admin_withdraw: 'خصم من الإدارة',
  financing_grant: 'صرف تسهيلات', financing_repay: 'سداد تسهيلات', ipo_allocation: 'تخصيص اكتتاب',
  pledge_loan: 'صرف قرض بضمان أسهم', pledge_repay: 'سداد قرض بضمان أسهم', pledge_foreclosure: 'تنفيذ الرهن: قيمة الأسهم المرهونة',
}
const entryLabel = (e) => (e.type === 'trade' ? (e.amount > 0 ? 'بيع أسهم' : 'شراء أسهم') : ENTRY[e.type] ?? e.type)
const signed = (h) => (h > 0 ? '+' : h < 0 ? '-' : '') + money(Math.abs(h))
const LOAN_STATUS = { repaid: 'مسدّد', foreclosed: 'نُفّذ الرهن' }
</script>

<template>
  <div class="bank">
    <header class="bank-head">
      <div class="who">
        <span class="seal">بنك</span>
        <div>
          <b>بنك تداول الافتراضي</b>
          <small>{{ state.user.alias }} · رقم الحساب <span class="num">{{ accountNo }}</span></small>
        </div>
      </div>
      <div class="avail">
        <span>الرصيد المتاح</span>
        <b class="num">{{ money(state.balances.cash) }}</b>
        <small>ريال افتراضي</small>
      </div>
    </header>

    <div class="cards">
      <div class="card"><span>محجوز في أوامر الشراء</span><b class="num">{{ money(state.balances.reserved) }}</b></div>
      <div class="card"><span>إجمالي الالتزامات</span><b class="num" :class="{ down: facilityDebt + loanDebt > 0 }">{{ money(facilityDebt + loanDebt) }}</b></div>
      <div class="card"><span>قيمة الأسهم المرهونة</span><b class="num">{{ money(pledges?.pledged_value ?? 0) }}</b></div>
      <div class="card"><span>صافي الثروة</span><b class="num">{{ money(state.netWorth) }}</b></div>
    </div>

    <nav class="bank-tabs">
      <button v-for="t in TABS" :key="t.id" :class="{ on: tab === t.id }" @click="tab = t.id">{{ t.label }}</button>
    </nav>

    <!-- overview -->
    <template v-if="tab === 'overview'">
      <div class="products">
        <article class="product">
          <h3>التسهيلات الائتمانية</h3>
          <p>سيولة إضافية للتداول بضمان محفظتك كاملة، برسم ثابت يُعرف عند الطلب.</p>
          <dl v-if="fin?.financing"><dt>المستحق</dt><dd class="num">{{ money(facilityDebt) }}</dd></dl>
          <dl v-else><dt>الحد المتاح لك</dt><dd class="num">{{ money(fin?.limit ?? 0) }}</dd></dl>
          <button class="small" @click="tab = 'facility'">{{ fin?.financing ? 'إدارة التسهيلات' : 'اطلب تسهيلات' }}</button>
        </article>
        <article class="product">
          <h3>قرض بضمان الأسهم</h3>
          <p>ارهن أسهم شركة تملكها واحصل على نقد حتى {{ pct(pledges?.terms.ltv_bps) }} من قيمتها، وتبقى الأسهم باسمك.</p>
          <dl><dt>قروض قائمة</dt><dd class="num">{{ loans.length }}</dd><dt>المستحق</dt><dd class="num">{{ money(loanDebt) }}</dd></dl>
          <button class="small" @click="tab = 'loans'">اطلب قرضًا</button>
        </article>
        <article class="product">
          <h3>رهن الأسهم</h3>
          <p>الأسهم المرهونة تبقى في محفظتك وتستفيد من ارتفاعها، لكن لا تُباع حتى تسدّد قرضها.</p>
          <dl><dt>أسهم مرهونة</dt><dd class="num">{{ qty(loans.reduce((n, l) => n + l.qty, 0)) }}</dd>
            <dt>قيمتها</dt><dd class="num">{{ money(pledges?.pledged_value ?? 0) }}</dd></dl>
          <button class="small" @click="tab = 'pledged'">عرض الأسهم المرهونة</button>
        </article>
      </div>
      <section class="panel">
        <h2>التزاماتك <small>ما عليك للبنك وموعد استحقاقه</small></h2>
        <table>
          <thead><tr><th>الالتزام</th><th>المستحق</th><th>تاريخ الاستحقاق</th><th>الحالة</th></tr></thead>
          <tbody>
            <tr v-for="o in obligations" :key="o.key">
              <td><b>{{ o.kind }}</b></td><td class="num">{{ money(o.amount) }}</td><td class="num">{{ day(o.due) }}</td>
              <td :class="o.warn ? 'down' : 'up'">{{ o.warn ? 'يحتاج تغطية' : 'منتظم' }}</td>
            </tr>
            <tr v-if="!obligations.length"><td colspan="4" class="empty">لا توجد عليك التزامات للبنك</td></tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- margin facility -->
    <section v-else-if="tab === 'facility'" class="panel">
      <h2>التسهيلات الائتمانية <small>تمويل بالهامش بضمان المحفظة كاملة</small></h2>
      <div v-if="fin" class="body">
        <dl class="terms">
          <div><dt>الرسم الثابت</dt><dd class="num">{{ pct(fin.terms.fee_bps) }}</dd></div>
          <div><dt>المدة</dt><dd>{{ fin.terms.days }} يومًا</dd></div>
          <div><dt>تنبيه الهامش عند</dt><dd class="num">{{ pct(fin.terms.margin_call_bps) }}</dd></div>
          <div><dt>تسييل المحفظة عند</dt><dd class="num">{{ pct(fin.terms.liquidation_bps) }}</dd></div>
        </dl>
        <template v-if="fin.financing">
          <dl class="terms">
            <div><dt>أصل المبلغ</dt><dd class="num">{{ money(fin.financing.principal) }}</dd></div>
            <div><dt>الرسم</dt><dd class="num">{{ money(fin.financing.fee) }}</dd></div>
            <div><dt>المستحق</dt><dd class="num">{{ money(facilityDebt) }}</dd></div>
            <div><dt>تاريخ الاستحقاق</dt><dd class="num">{{ day(fin.financing.due_at) }}</dd></div>
          </dl>
          <div class="gauge">
            <div class="gauge-top"><span>الهامش الحالي</span>
              <b class="num" :class="fin.financing.margin_called ? 'down' : 'up'">{{ pct(fin.margin_bps) }}</b></div>
            <div class="meter"><i :class="{ bad: fin.financing.margin_called }" :style="{ width: marginFill + '%' }"></i></div>
            <small class="muted">الهامش هو حقوقك مقسومة على أصولك. كلما نزل اقتربت من التسييل.</small>
          </div>
          <p v-if="fin.financing.status === 'liquidating'" class="alert">هامشك نزل تحت حد التسييل: تُباع أسهمك الحرة الآن لسداد التسهيلات.</p>
          <p v-else-if="fin.financing.margin_called" class="alert">تنبيه هامش: أضف نقدًا أو سدّد قبل أن يصل الهامش إلى حد التسييل.</p>
          <div class="actions"><button class="primary" @click="repayFacility">سدّد {{ money(facilityDebt) }}</button></div>
        </template>
        <form v-else-if="fin.enabled" class="apply" @submit.prevent="borrow">
          <div class="field">
            <label>المبلغ المطلوب (ريال)
              <input v-model="facilityAmount" type="number" min="1" :max="fin.limit / 100" step="0.01" required dir="ltr" /></label>
            <button type="button" class="small" @click="facilityAmount = (fin.limit / 100).toFixed(2)">الحد الأقصى {{ money(fin.limit) }}</button>
          </div>
          <dl class="quote">
            <div><dt>الرسم</dt><dd class="num">{{ money(facilityFee) }}</dd></div>
            <div><dt>إجمالي السداد</dt><dd class="num">{{ money(halalas(facilityAmount) + facilityFee) }}</dd></div>
            <div><dt>يستحق في</dt><dd class="num">{{ dueIn(fin.terms.days) }}</dd></div>
          </dl>
          <button class="primary" :disabled="fin.limit <= 0">اطلب التسهيلات</button>
        </form>
        <p v-else class="empty">التسهيلات متوقفة حاليًا.</p>
      </div>
    </section>

    <!-- loan against pledged shares -->
    <template v-else-if="tab === 'loans'">
      <section class="panel">
        <h2>طلب قرض بضمان الأسهم <small>ترهن الأسهم فيُصرف القرض فورًا</small></h2>
        <div v-if="pledges" class="body">
          <dl class="terms">
            <div><dt>حد القرض من قيمة الأسهم</dt><dd class="num">{{ pct(pledges.terms.ltv_bps) }}</dd></div>
            <div><dt>الرسم الثابت</dt><dd class="num">{{ pct(pledges.terms.fee_bps) }}</dd></div>
            <div><dt>المدة</dt><dd>{{ pledges.terms.days }} يومًا</dd></div>
            <div><dt>تنفيذ الرهن عند</dt><dd class="num">{{ pct(pledges.terms.foreclose_bps) }}</dd></div>
          </dl>
          <p v-if="!pledges.enabled" class="empty">القروض بضمان الأسهم متوقفة حاليًا.</p>
          <p v-else-if="!eligible.length" class="empty">لا تملك أسهمًا حرة ترهنها. الأسهم التي في أوامر بيع أو المرهونة سابقًا لا تُرهن.</p>
          <form v-else class="apply" @submit.prevent="takeLoan">
            <div class="field">
              <label>السهم
                <select v-model="form.symbol" @change="form.qty = ''; form.amount = ''">
                  <option v-for="e in eligible" :key="e.symbol" :value="e.symbol">{{ e.name }} ({{ e.symbol }})</option>
                </select></label>
              <small class="muted">آخر سعر <span class="num">{{ money(chosen?.last ?? 0) }}</span></small>
            </div>
            <div class="field">
              <label>عدد الأسهم المرهونة
                <input v-model="form.qty" type="number" min="1" :max="chosen?.free" step="1" required dir="ltr" /></label>
              <button type="button" class="small" @click="form.qty = chosen.free">كل الأسهم الحرة {{ qty(chosen?.free ?? 0) }}</button>
            </div>
            <div class="field">
              <label>مبلغ القرض (ريال)
                <input v-model="form.amount" type="number" min="0.01" :max="maxLoan / 100" step="0.01" required dir="ltr" /></label>
              <button type="button" class="small" :disabled="maxLoan <= 0" @click="form.amount = (maxLoan / 100).toFixed(2)">الحد الأقصى {{ money(maxLoan) }}</button>
            </div>
            <dl class="quote">
              <div><dt>قيمة الأسهم المرهونة</dt><dd class="num">{{ money(pledgeValue) }}</dd></div>
              <div><dt>الرسم</dt><dd class="num">{{ money(loanFee) }}</dd></div>
              <div><dt>إجمالي السداد</dt><dd class="num">{{ money(halalas(form.amount) + loanFee) }}</dd></div>
              <div><dt>نسبة القرض إلى القيمة</dt><dd class="num">{{ pct(loanLtv) }}</dd></div>
              <div><dt>يستحق في</dt><dd class="num">{{ dueIn(pledges.terms.days) }}</dd></div>
            </dl>
            <button class="primary">ارهن الأسهم واصرف القرض</button>
          </form>
        </div>
      </section>
      <section class="panel">
        <h2>قروضك القائمة</h2>
        <table>
          <thead><tr><th>الضمان</th><th>أصل القرض</th><th>الرسم</th><th>المستحق</th><th>نسبة القرض إلى قيمة الضمان</th><th>الاستحقاق</th><th></th></tr></thead>
          <tbody>
            <tr v-for="l in loans" :key="l.id">
              <td><b>{{ l.name }}</b><small class="num">{{ qty(l.qty) }} سهم</small></td>
              <td class="num">{{ money(l.principal) }}</td><td class="num muted">{{ money(l.fee) }}</td>
              <td class="num">{{ money(l.debt) }}</td>
              <td><div class="ltv"><b class="num" :class="l.warned ? 'down' : ''">{{ pct(l.ltv_bps) }}</b>
                <div class="meter"><i :class="{ bad: l.warned }" :style="{ width: ltvFill(l) + '%' }"></i></div></div></td>
              <td class="num">{{ day(l.due_at) }}</td>
              <td><button class="small primary" @click="repayLoan(l)">سدّد</button></td>
            </tr>
            <tr v-if="!loans.length"><td colspan="7" class="empty">لا توجد قروض قائمة</td></tr>
          </tbody>
        </table>
      </section>
      <section v-if="pledges?.closed.length" class="panel">
        <h2>قروض سابقة</h2>
        <table>
          <thead><tr><th>الضمان</th><th>أصل القرض</th><th>الرسم</th><th>النتيجة</th><th>تاريخ الإغلاق</th></tr></thead>
          <tbody>
            <tr v-for="l in pledges.closed" :key="l.id">
              <td><b>{{ l.name }}</b><small class="num">{{ qty(l.qty) }} سهم</small></td>
              <td class="num">{{ money(l.principal) }}</td><td class="num muted">{{ money(l.fee) }}</td>
              <td :class="l.status === 'repaid' ? 'up' : 'down'">{{ LOAN_STATUS[l.status] }}
                <small v-if="l.status === 'foreclosed'" class="num">بسعر {{ money(l.foreclosed_price) }}</small></td>
              <td class="num">{{ day(l.closed_at) }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- pledged shares -->
    <section v-else-if="tab === 'pledged'" class="panel">
      <h2>الأسهم المرهونة <small>أسهم باسمك محجوزة لصالح البنك حتى سداد قرضها</small></h2>
      <table>
        <thead><tr><th>السهم</th><th>الأسهم المرهونة</th><th>آخر سعر</th><th>القيمة السوقية</th><th>القرض المضمون</th><th>سعر تنفيذ الرهن</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
          <tr v-for="l in loans" :key="l.id">
            <td><b>{{ l.name }}</b><small class="num">{{ l.symbol }}</small></td>
            <td class="num">{{ qty(l.qty) }}</td><td class="num">{{ money(l.last) }}</td><td class="num">{{ money(l.value) }}</td>
            <td class="num">{{ money(l.debt) }}</td>
            <td class="num down">{{ money(foreclosePrice(l)) }}</td>
            <td :class="l.warned ? 'down' : 'up'">{{ l.warned ? 'يحتاج تغطية' : 'سليم' }}</td>
            <td><button class="small" @click="repayLoan(l)">فك الرهن بالسداد</button></td>
          </tr>
          <tr v-if="!loans.length"><td colspan="8" class="empty">لا توجد أسهم مرهونة</td></tr>
        </tbody>
      </table>
      <ul v-if="pledges" class="rules">
        <li>الأسهم المرهونة تبقى في محفظتك وتُحسب في ثروتك، لكن لا يمكن بيعها.</li>
        <li>إذا بلغ المستحق {{ pct(pledges.terms.call_bps) }} من قيمة الأسهم يصلك تنبيه تغطية.</li>
        <li>إذا بلغ {{ pct(pledges.terms.foreclose_bps) }}، أو حلّ الاستحقاق والنقد لا يكفي، ينفّذ البنك الرهن: يأخذ الأسهم بسعر السوق، يخصم المستحق، ويودع الباقي في حسابك.</li>
        <li>السداد الكامل يفك الرهن فورًا وتعود الأسهم حرة.</li>
      </ul>
    </section>

    <!-- statement -->
    <section v-else class="panel">
      <h2>كشف الحساب <small>كل مبلغ دخل حسابك أو خرج منه، الأحدث أولًا</small></h2>
      <table>
        <thead><tr><th>الوقت</th><th>البيان</th><th>السهم</th><th>المبلغ</th><th>الرصيد بعده</th></tr></thead>
        <tbody>
          <tr v-for="e in state.statement" :key="e.id">
            <td class="num muted">{{ stamp(e.at) }}</td>
            <td>{{ entryLabel(e) }}</td>
            <td><template v-if="e.name"><b>{{ e.name }}</b><small class="num">{{ e.symbol }}</small></template><span v-else class="muted">—</span></td>
            <td class="num" :class="e.amount > 0 ? 'up' : 'down'">{{ signed(e.amount) }}</td>
            <td class="num">{{ money(e.balance_after) }}</td>
          </tr>
          <tr v-if="!state.statement.length"><td colspan="5" class="empty">لا توجد حركات بعد</td></tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
