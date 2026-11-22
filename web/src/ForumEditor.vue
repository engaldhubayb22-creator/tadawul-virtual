<script setup>
// The post editor: a text box with a formatting bar, picture and file
// uploads, smileys and a live preview of how the post will look.
import { h, ref } from 'vue'
import { COLORS, FONTS, SIZES, renderBBCode } from './bbcode'
import { DEMO, state, toast, uploadForumFile } from './store'

const props = defineProps({ modelValue: { type: String, default: '' }, rows: { type: Number, default: 8 }, placeholder: { type: String, default: '' } })
const emit = defineEmits(['update:modelValue'])

const box = ref(null)
const panel = ref('') // which pop-up row is open: color | link | smile
const link = ref('')
const uploading = ref(false)
const picker = ref(null), attacher = ref(null)

const SMILEYS = ['😀', '😂', '😉', '😍', '🤔', '😎', '😢', '😡', '👍', '👎', '🙏', '👏', '💪', '🔥', '🚀', '📈', '📉', '💰', '💎', '🐂', '🐻', '⚠️', '✅', '❌']
const SIZE_NAMES = { 1: 'صغير جدًا', 2: 'صغير', 3: 'عادي', 4: 'متوسط', 5: 'كبير', 6: 'كبير جدًا', 7: 'عملاق' }
const Preview = () => h('div', { class: 'fbody' }, renderBBCode(props.modelValue))

function set(text, from, to) {
  emit('update:modelValue', text)
  requestAnimationFrame(() => { box.value?.focus(); box.value?.setSelectionRange(from, to ?? from) })
}
// Wrap the selection in a tag, or put the cursor between an empty pair.
function wrap(tag, arg) {
  const el = box.value, text = props.modelValue
  const a = el?.selectionStart ?? text.length, b = el?.selectionEnd ?? text.length
  const open = `[${tag}${arg ? '=' + arg : ''}]`, close = `[/${tag}]`
  set(text.slice(0, a) + open + text.slice(a, b) + close + text.slice(b), a + open.length, b + open.length)
}
function insert(piece) {
  const el = box.value, text = props.modelValue
  const a = el?.selectionStart ?? text.length, b = el?.selectionEnd ?? text.length
  set(text.slice(0, a) + piece + text.slice(b), a + piece.length)
}
function list(ordered) {
  const el = box.value, text = props.modelValue
  const a = el?.selectionStart ?? text.length, b = el?.selectionEnd ?? text.length
  const items = (text.slice(a, b) || 'البند الأول\nالبند الثاني').split('\n').filter((l) => l.trim()).map((l) => '[*]' + l.trim()).join('\n')
  const piece = `[list${ordered ? '=1' : ''}]\n${items}\n[/list]\n`
  set(text.slice(0, a) + piece + text.slice(b), a + piece.length)
}
function addLink() {
  const url = link.value.trim()
  if (!/^https?:\/\/\S+$/i.test(url)) return toast('اكتب رابطًا يبدأ بـ http:// أو https://', 'bad')
  const el = box.value, text = props.modelValue
  const a = el?.selectionStart ?? text.length, b = el?.selectionEnd ?? text.length
  const piece = `[url=${url}]${text.slice(a, b) || url}[/url]`
  set(text.slice(0, a) + piece + text.slice(b), a + piece.length)
  link.value = ''; panel.value = ''
}
async function upload(event, wantImage) {
  const file = event.target.files[0]
  event.target.value = ''
  if (!file) return
  if (!state.token) { state.showAuth = true; return }
  uploading.value = true
  try {
    const f = await uploadForumFile(file)
    if (wantImage && !f.is_image) toast('الملف ليس صورة، أُرفق كملف', 'info')
    insert(f.is_image ? `[img]${f.id}[/img]\n` : `[file=${f.id}]${f.name}[/file]\n`)
  } catch (e) { toast(e.message, 'bad') } finally { uploading.value = false }
}
const toggle = (name) => { panel.value = panel.value === name ? '' : name }
defineExpose({ focus: () => box.value?.focus() })
</script>

<template>
  <div class="feditor">
    <div class="ftools">
      <button type="button" title="عريض" @click="wrap('b')"><b>B</b></button>
      <button type="button" title="مائل" @click="wrap('i')"><i>I</i></button>
      <button type="button" title="تحته خط" @click="wrap('u')"><u>U</u></button>
      <button type="button" title="مشطوب" @click="wrap('s')"><s>S</s></button>
      <span class="sep"></span>
      <select title="حجم الخط" @change="wrap('size', $event.target.value); $event.target.value = ''">
        <option value="">الحجم</option>
        <option v-for="(px, n) in SIZES" :key="n" :value="n">{{ SIZE_NAMES[n] }}</option>
      </select>
      <select title="نوع الخط" @change="wrap('font', $event.target.value); $event.target.value = ''">
        <option value="">الخط</option>
        <option v-for="(f, key) in FONTS" :key="key" :value="key" :style="{ fontFamily: f.css }">{{ f.label }}</option>
      </select>
      <button type="button" title="لون الخط" :class="{ on: panel === 'color' }" @click="toggle('color')">اللون</button>
      <span class="sep"></span>
      <button type="button" title="محاذاة لليمين" @click="wrap('right')">يمين</button>
      <button type="button" title="توسيط" @click="wrap('center')">وسط</button>
      <button type="button" title="محاذاة لليسار" @click="wrap('left')">يسار</button>
      <span class="sep"></span>
      <button type="button" title="قائمة نقطية" @click="list(false)">• قائمة</button>
      <button type="button" title="قائمة مرقمة" @click="list(true)">1. قائمة</button>
      <button type="button" title="رابط" :class="{ on: panel === 'link' }" @click="toggle('link')">رابط</button>
      <button type="button" title="اقتباس" @click="wrap('quote')">اقتباس</button>
      <button type="button" title="نص بخط ثابت، مثل الأرقام والجداول" @click="wrap('code')">كود</button>
      <button type="button" title="خط فاصل" @click="insert('\n[hr]\n')">فاصل</button>
      <span class="sep"></span>
      <button type="button" title="إدراج صورة من جهازك" :disabled="uploading || DEMO" @click="picker.click()">🖼 صورة</button>
      <button type="button" title="إرفاق ملف من جهازك" :disabled="uploading || DEMO" @click="attacher.click()">📎 مرفق</button>
      <button type="button" title="ابتسامات" :class="{ on: panel === 'smile' }" @click="toggle('smile')">😀</button>
      <input ref="picker" type="file" hidden accept="image/jpeg,image/png,image/gif,image/webp" @change="upload($event, true)" />
      <input ref="attacher" type="file" hidden accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,image/*" @change="upload($event, false)" />
    </div>
    <div v-if="panel === 'color'" class="fpanel">
      <button v-for="c in COLORS" :key="c" type="button" class="swatch" :style="{ background: c }" :title="c" @click="wrap('color', c); panel = ''"></button>
    </div>
    <div v-else-if="panel === 'link'" class="fpanel">
      <input v-model="link" dir="ltr" placeholder="https://" @keydown.enter.prevent="addLink" />
      <button type="button" class="small primary" @click="addLink">أدرج الرابط</button>
      <span class="muted">حدّد النص أولًا ليصير هو الرابط.</span>
    </div>
    <div v-else-if="panel === 'smile'" class="fpanel">
      <button v-for="s in SMILEYS" :key="s" type="button" class="smiley" @click="insert(s)">{{ s }}</button>
    </div>
    <p v-if="uploading" class="muted fnote">جارٍ رفع الملف…</p>
    <textarea ref="box" :value="modelValue" :rows="rows" :placeholder="placeholder" required maxlength="10000"
      @input="emit('update:modelValue', $event.target.value)"></textarea>
    <template v-if="modelValue.trim()">
      <div class="flabel">المعاينة: هكذا ستظهر مشاركتك</div>
      <div class="fpreview"><Preview /></div>
    </template>
    <small class="muted fnote">الصور حتى 5 ميجابايت (JPG, PNG, GIF, WEBP) · المرفقات حتى 10 ميجابايت (PDF, Word, Excel, PowerPoint, نص, CSV, ZIP)</small>
  </div>
</template>
