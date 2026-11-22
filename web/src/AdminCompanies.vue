<script setup>
// Admin: every company's file and share counts. A change in the free float
// is added to or taken from the liquidity fund, so no share goes missing.
import { onMounted, reactive, ref } from 'vue'
import { api, toast } from './store'

const list = reactive({ companies: [], sectors: [], q: '' })
const open = ref('') // symbol being edited
const form = reactive({})
const qty = (n) => Number(n).toLocaleString('en-US')

async function load() {
  try {
    const d = await api('GET', '/admin/companies' + (list.q ? '?q=' + encodeURIComponent(list.q) : ''))
    list.companies = d.companies; list.sectors = d.sectors
  } catch (e) { toast(e.message, 'bad') }
}
let timer = null
const search = () => { clearTimeout(timer); timer = setTimeout(load, 300) }

function edit(c) {
  if (open.value === c.symbol) { open.value = ''; return }
  open.value = c.symbol
  Object.assign(form, {
    name: c.name, sector_id: c.sector_id, shares_outstanding: c.shares_outstanding, founder_shares: c.founder_shares,
    par_value: c.par_value / 100, equity: c.equity / 100, net_income: c.net_income / 100,
    about: c.about, founded_year: c.founded_year, headquarters: c.headquarters, website: c.website,
  })
}
const floatChange = (c) => (form.shares_outstanding - form.founder_shares) - c.free_float
async function save(c) {
  try {
    const d = await api('PUT', '/admin/companies/' + c.symbol, {
      name: form.name, sector_id: form.sector_id || null,
      shares_outstanding: Math.round(form.shares_outstanding), founder_shares: Math.round(form.founder_shares),
      par_value: Math.round(form.par_value * 100), equity: Math.round(form.equity * 100), net_income: Math.round(form.net_income * 100),
      about: form.about, founded_year: form.founded_year || null, headquarters: form.headquarters, website: form.website,
    })
    toast(d.float_change ? `حُفظت ${form.name}، وتغيّرت الأسهم الحرة ${qty(d.float_change)} سهم` : 'حُفظت ' + form.name, 'good')
    open.value = ''
    await load()
  } catch (e) { toast(e.message, 'bad') }
}
onMounted(load)
</script>

<template>
  <section class="panel pad">
    <h3>الشركات والأسهم <span class="muted">{{ list.companies.length }} شركة</span>
      <input v-model="list.q" class="search" placeholder="بحث بالاسم أو الرمز" @input="search" /></h3>
    <div class="scroll">
      <table>
        <thead><tr><th>الشركة</th><th>القطاع</th><th>الأسهم المصدرة</th><th>أسهم المؤسسين</th><th>الأسهم الحرة</th><th>المملوك فعليًا</th>
          <th>ملاك من الأعضاء</th><th>حر عند صندوق السيولة</th><th></th></tr></thead>
        <tbody>
          <template v-for="c in list.companies" :key="c.symbol">
            <tr>
              <td><b>{{ c.name }}</b><small class="num">{{ c.symbol }}</small></td>
              <td class="muted">{{ c.sector || '—' }}</td>
              <td class="num">{{ qty(c.shares_outstanding) }}</td><td class="num">{{ qty(c.founder_shares) }}</td>
              <td class="num">{{ qty(c.free_float) }}</td>
              <td class="num" :class="c.held === c.free_float ? 'up' : 'down'">{{ qty(c.held) }}</td>
              <td class="num">{{ c.member_holders }}</td><td class="num muted">{{ qty(c.fund_free) }}</td>
              <td><button class="small" @click="edit(c)">{{ open === c.symbol ? 'إغلاق' : 'تعديل' }}</button></td>
            </tr>
            <tr v-if="open === c.symbol" class="editrow">
              <td colspan="9">
                <form class="cform" @submit.prevent="save(c)">
                  <label>اسم الشركة<input v-model="form.name" required minlength="2" maxlength="120" /></label>
                  <label>القطاع<select v-model="form.sector_id">
                    <option :value="null">بدون قطاع</option>
                    <option v-for="s in list.sectors" :key="s.id" :value="s.id">{{ s.name }}</option>
                  </select></label>
                  <label>الأسهم المصدرة<input v-model.number="form.shares_outstanding" type="number" min="1" step="1" required dir="ltr" /></label>
                  <label>أسهم المؤسسين (غير متداولة)<input v-model.number="form.founder_shares" type="number" min="0" step="1" required dir="ltr" /></label>
                  <label>القيمة الاسمية (ريال)<input v-model.number="form.par_value" type="number" min="0.01" step="0.01" required dir="ltr" /></label>
                  <label>حقوق المساهمين (ريال)<input v-model.number="form.equity" type="number" min="0" step="0.01" required dir="ltr" /></label>
                  <label>صافي الربح لآخر 12 شهرًا (ريال)<input v-model.number="form.net_income" type="number" step="0.01" required dir="ltr" /></label>
                  <label>سنة التأسيس<input v-model.number="form.founded_year" type="number" min="1800" max="2200" step="1" dir="ltr" /></label>
                  <label>المقر<input v-model="form.headquarters" maxlength="120" /></label>
                  <label>الموقع الإلكتروني<input v-model="form.website" maxlength="200" dir="ltr" /></label>
                  <label class="wide">نبذة عن الشركة<textarea v-model="form.about" rows="5" maxlength="10000"></textarea></label>
                  <p v-if="floatChange(c)" class="wide" :class="floatChange(c) > 0 ? 'up' : 'down'">
                    الأسهم الحرة {{ floatChange(c) > 0 ? 'تزيد' : 'تنقص' }} <b class="num">{{ qty(Math.abs(floatChange(c))) }}</b> سهم:
                    {{ floatChange(c) > 0 ? 'تُضاف إلى صندوق السيولة ليطرحها في السوق.' : 'تُسحب من الأسهم الحرة عند صندوق السيولة.' }}</p>
                  <div class="wide"><button class="primary">حفظ التعديلات</button></div>
                </form>
              </td>
            </tr>
          </template>
          <tr v-if="!list.companies.length"><td colspan="9" class="empty">لا توجد شركات مطابقة</td></tr>
        </tbody>
      </table>
    </div>
    <p class="muted">"المملوك فعليًا" هو مجموع ما في محافظ الجميع، ويجب أن يساوي الأسهم الحرة. إذا ظهر بالأحمر فهناك فرق يحتاج معالجة.</p>
  </section>
</template>
