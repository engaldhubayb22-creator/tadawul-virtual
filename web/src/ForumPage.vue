<script setup>
// The forum, laid out like a classic bulletin board: categories with their
// forums, a forum's thread list, and a thread with its posts and a member
// panel beside each one.
import { computed, h, onMounted, reactive, ref } from 'vue'
import { api, isoTime, state, toast } from './store'

const view = ref('index') // index | board | thread | new
const index = ref(null)
const board = ref(null)   // { board, threads, page, pages, total }
const thread = ref(null)  // { thread, posts, page, pages }
const busy = ref(false)

const isStaff = computed(() => ['admin', 'moderator'].includes(state.user?.role))
const boards = computed(() => (index.value?.categories ?? []).flatMap((c) => c.boards.map((b) => ({ ...b, category: c.name }))))
const stamp = (s) => (s ? new Date(Date.parse(isoTime(s))).toLocaleString('en-GB', {
  timeZone: state.market.timezone, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—')
const day = (s) => (s ? new Date(Date.parse(isoTime(s))).toLocaleDateString('en-GB', { timeZone: state.market.timezone }) : '—')
const count = (n) => Number(n ?? 0).toLocaleString('en-US')

async function guard(action) {
  busy.value = true
  try { return await action() } catch (e) { toast(e.message, 'bad') } finally { busy.value = false }
}
const needUser = () => { if (!state.token) { state.showAuth = true; return false } return true }

// ---- navigation
const openIndex = () => guard(async () => { index.value = await api('GET', '/forum/boards'); view.value = 'index' })
const openBoard = (id, page = 1) => guard(async () => {
  board.value = await api('GET', `/forum?board=${id}&page=${page}`); view.value = 'board'
})
const openThread = (id, page = 1) => guard(async () => {
  thread.value = await api('GET', `/forum/${id}?page=${page}`); view.value = 'thread'
  reply.value = ''; editing.id = null
})
const reloadThread = (page = thread.value.page) => openThread(thread.value.thread.id, page)
onMounted(openIndex)

// ---- BBCode: [b] [i] [u] [quote] [quote=name]. Text is never treated as HTML.
function parse(text) {
  const re = /\[(\/?)(b|i|u|quote)(?:=([^\]\n]{1,40}))?\]/gi
  const root = { children: [] }
  const stack = [root]
  let last = 0, m
  while ((m = re.exec(text))) {
    if (m.index > last) stack.at(-1).children.push(text.slice(last, m.index))
    const tag = m[2].toLowerCase()
    if (!m[1]) {
      const node = { tag, arg: m[3], children: [] }
      stack.at(-1).children.push(node); stack.push(node)
    } else {
      const at = stack.findLastIndex((n) => n.tag === tag)
      if (at > 0) stack.length = at
      else stack.at(-1).children.push(m[0])
    }
    last = re.lastIndex
  }
  if (last < text.length) stack.at(-1).children.push(text.slice(last))
  return root.children
}
const paint = (nodes) => nodes.map((n) => (typeof n === 'string' ? n
  : n.tag === 'quote' ? h('blockquote', [n.arg ? h('cite', n.arg + ' كتب:') : null, ...paint(n.children)])
    : h(n.tag, paint(n.children))))
const PostBody = (props) => h('div', { class: 'fbody' }, paint(parse(props.text ?? '')))
PostBody.props = ['text']

// ---- writing
const reply = ref('')
const draft = reactive({ title: '', body: '', symbol: '' })
const editing = reactive({ id: null, title: '', body: '' }) // id 0 = the opening post
const replyBox = ref(null)

function wrap(target, tag) {
  const el = target.el
  const start = el?.selectionStart ?? 0, end = el?.selectionEnd ?? 0
  const text = target.get()
  target.set(text.slice(0, start) + `[${tag}]` + text.slice(start, end) + `[/${tag}]` + text.slice(end))
}
const replyTarget = { get el() { return replyBox.value }, get: () => reply.value, set: (v) => { reply.value = v } }
function quote(author, text) {
  if (!needUser()) return
  // Quote the words, not earlier quotes nested inside them.
  const plain = text.replace(/\[quote(?:=[^\]]*)?\][\s\S]*?\[\/quote\]/gi, '').trim()
  reply.value += (reply.value ? '\n' : '') + `[quote=${author}]${plain}[/quote]\n`
  replyBox.value?.focus()
}

const sendReply = () => needUser() && guard(async () => {
  await api('POST', `/forum/${thread.value.thread.id}/posts`, { body: reply.value })
  reply.value = ''
  await openThread(thread.value.thread.id, 'last')
})
function startNew() {
  if (!needUser()) return
  Object.assign(draft, { title: '', body: '', symbol: '' })
  view.value = 'new'
}
const sendThread = () => guard(async () => {
  const r = await api('POST', '/forum', { title: draft.title, body: draft.body, symbol: draft.symbol || null, board_id: board.value.board.id })
  await openThread(r.id)
})

function startEdit(post) {
  Object.assign(editing, post ? { id: post.id, title: '', body: post.body } : { id: 0, title: thread.value.thread.title, body: thread.value.thread.body })
}
const saveEdit = () => guard(async () => {
  if (editing.id === 0) await api('PUT', `/forum/${thread.value.thread.id}`, { title: editing.title, body: editing.body })
  else await api('PUT', `/forum/posts/${editing.id}`, { body: editing.body })
  toast('حُفظ التعديل', 'good')
  await reloadThread()
})
const mine = (userId) => state.user?.id === userId
const canEdit = (userId) => isStaff.value || (mine(userId) && !thread.value.thread.locked)

// ---- moderation
const moderate = (changes, done) => guard(async () => {
  await api('PUT', `/forum/${thread.value.thread.id}`, changes)
  toast(done, 'good')
  await reloadThread()
})
const moveTo = ref('')
const move = () => moveTo.value && moderate({ board_id: Number(moveTo.value) }, 'نُقل الموضوع').then(() => { moveTo.value = '' })
const removeThread = () => guard(async () => {
  const id = thread.value.thread.board_id
  await api('DELETE', `/forum/${thread.value.thread.id}`)
  toast('حُذف الموضوع', 'good')
  await (id ? openBoard(id) : openIndex())
})
const removePost = (post) => guard(async () => {
  await api('DELETE', `/forum/posts/${post.id}`)
  toast('حُذف الرد', 'good')
  await reloadThread()
})
// The boards list is needed for the move menu even when the thread was opened directly.
const ensureIndex = async () => { if (!index.value) index.value = await api('GET', '/forum/boards') }

// ---- signature
const signature = ref(state.user?.forum_signature ?? '')
const showSignature = ref(false)
const saveSignature = () => guard(async () => {
  const r = await api('PUT', '/forum/signature', { signature: signature.value })
  if (state.user) state.user.forum_signature = r.signature
  showSignature.value = false
  toast('حُفظ توقيعك', 'good')
})

const pageList = (pages) => Array.from({ length: pages }, (_, i) => i + 1)
</script>

<template>
  <main class="page forum-page">
    <!-- where we are -->
    <nav class="crumbs">
      <a @click="openIndex">منتديات تداول الافتراضي</a>
      <template v-if="view === 'board' || view === 'new'">
        <span>‹</span><span class="muted">{{ board.board.category }}</span>
        <span>‹</span><a @click="openBoard(board.board.id)">{{ board.board.name }}</a>
        <template v-if="view === 'new'"><span>‹</span><b>موضوع جديد</b></template>
      </template>
      <template v-else-if="view === 'thread'">
        <template v-if="thread.thread.board"><span>‹</span><a @click="openBoard(thread.thread.board_id)">{{ thread.thread.board }}</a></template>
        <span>‹</span><b>{{ thread.thread.title }}</b>
      </template>
      <span class="welcome">
        <template v-if="state.user">مرحبًا <b>{{ state.user.alias }}</b> ·
          <a @click="showSignature = !showSignature; signature = state.user.forum_signature ?? ''">توقيعي</a></template>
        <template v-else><a @click="state.showAuth = true">سجّل الدخول</a> لتشارك في المنتدى</template>
      </span>
    </nav>
    <form v-if="showSignature && state.user" class="tbox sig-form" @submit.prevent="saveSignature">
      <label>توقيعك (يظهر تحت كل مشاركاتك، 300 حرف كحد أقصى)<input v-model="signature" maxlength="300" /></label>
      <button class="primary small">حفظ التوقيع</button>
    </form>

    <!-- front page: categories and their forums -->
    <template v-if="view === 'index' && index">
      <section v-for="c in index.categories" :key="c.id" class="tbox">
        <h2 class="tcat">{{ c.name }}</h2>
        <table class="ftable">
          <thead><tr><th colspan="2">المنتدى</th><th>آخر مشاركة</th><th>المواضيع</th><th>المشاركات</th></tr></thead>
          <tbody>
            <tr v-for="b in c.boards" :key="b.id" @click="openBoard(b.id)">
              <td class="ficon"><i :class="{ lit: b.threads }"></i></td>
              <td class="fname"><b>{{ b.name }}</b><small>{{ b.description }}</small></td>
              <td class="flast">
                <template v-if="b.last"><a @click.stop="openThread(b.last.thread_id, 'last')">{{ b.last.title }}</a>
                  <small>بواسطة <b>{{ b.last.alias }}</b> · <span class="num">{{ stamp(b.last.at) }}</span></small></template>
                <small v-else>لا توجد مشاركات</small>
              </td>
              <td class="num fnum">{{ count(b.threads) }}</td><td class="num fnum">{{ count(b.posts) }}</td>
            </tr>
            <tr v-if="!c.boards.length"><td colspan="5" class="empty">لا توجد منتديات في هذا القسم</td></tr>
          </tbody>
        </table>
      </section>
      <section class="tbox">
        <h2 class="tcat">إحصائيات المنتدى</h2>
        <p class="fstats">المواضيع: <b class="num">{{ count(index.stats.threads) }}</b> · المشاركات: <b class="num">{{ count(index.stats.posts) }}</b>
          · الأعضاء: <b class="num">{{ count(index.stats.members) }}</b>
          <template v-if="index.stats.newest_member"> · نرحب بالعضو الجديد: <b>{{ index.stats.newest_member }}</b></template></p>
      </section>
    </template>

    <!-- one forum: its threads -->
    <template v-else-if="view === 'board' && board">
      <div class="fbar">
        <button class="primary" :disabled="board.board.staff_only && !isStaff" @click="startNew">موضوع جديد</button>
        <span v-if="board.board.staff_only && !isStaff" class="muted">هذا المنتدى للإعلانات: الإدارة تفتح المواضيع والأعضاء يردّون.</span>
        <span class="pages"><button v-for="n in pageList(board.pages)" :key="n" class="small" :class="{ on: n === board.page }" @click="openBoard(board.board.id, n)">{{ n }}</button></span>
      </div>
      <section class="tbox">
        <h2 class="tcat">{{ board.board.name }} <small>{{ board.board.description }}</small></h2>
        <table class="ftable">
          <thead><tr><th colspan="2">الموضوع / الكاتب</th><th>آخر مشاركة</th><th>الردود</th><th>المشاهدات</th></tr></thead>
          <tbody>
            <tr v-for="t in board.threads" :key="t.id" @click="openThread(t.id)">
              <td class="ficon"><i :class="{ lit: true, pin: t.pinned, lock: t.locked }"></i></td>
              <td class="fname">
                <b><span v-if="t.pinned" class="flag">مثبت</span><span v-if="t.locked" class="flag off">مغلق</span>{{ t.title }}</b>
                <small>{{ t.alias }}<template v-if="t.company"> · {{ t.company }}</template></small>
              </td>
              <td class="flast"><small><span class="num">{{ stamp(t.last_post_at) }}</span><br />بواسطة <b>{{ t.last_alias }}</b></small></td>
              <td class="num fnum">{{ count(t.reply_count) }}</td><td class="num fnum">{{ count(t.views) }}</td>
            </tr>
            <tr v-if="!board.threads.length"><td colspan="5" class="empty">لا توجد مواضيع في هذا المنتدى بعد. كن أول من يكتب.</td></tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- new thread -->
    <section v-else-if="view === 'new'" class="tbox">
      <h2 class="tcat">موضوع جديد في {{ board.board.name }}</h2>
      <form class="editor" @submit.prevent="sendThread">
        <label>عنوان الموضوع<input v-model="draft.title" required minlength="3" maxlength="150" /></label>
        <label>الشركة المعنية (اختياري)
          <select v-model="draft.symbol">
            <option value="">موضوع عام</option>
            <option v-for="s in state.symbols" :key="s" :value="s">{{ state.companies[s].name }} ({{ s }})</option>
          </select></label>
        <label>نص الموضوع<textarea v-model="draft.body" rows="10" required maxlength="10000"></textarea></label>
        <small class="muted">للتنسيق: [b]عريض[/b] · [i]مائل[/i] · [u]تحته خط[/u] · [quote]اقتباس[/quote]</small>
        <div class="actions"><button class="primary" :disabled="busy">انشر الموضوع</button>
          <button type="button" @click="view = 'board'">إلغاء</button></div>
      </form>
    </section>

    <!-- one thread: its posts -->
    <template v-else-if="view === 'thread' && thread">
      <div class="fbar">
        <button v-if="!thread.thread.locked || isStaff" class="primary" @click="needUser() && replyBox?.focus()">إضافة رد</button>
        <span v-else class="flag off">الموضوع مغلق</span>
        <span class="muted">المشاهدات <b class="num">{{ count(thread.thread.views) }}</b> · الردود <b class="num">{{ count(thread.thread.reply_count) }}</b></span>
        <span class="pages"><button v-for="n in pageList(thread.pages)" :key="n" class="small" :class="{ on: n === thread.page }" @click="reloadThread(n)">{{ n }}</button></span>
      </div>
      <div v-if="isStaff" class="modbar">
        <b>أدوات الإشراف</b>
        <button class="small" @click="moderate({ pinned: !thread.thread.pinned }, thread.thread.pinned ? 'أُلغي التثبيت' : 'ثُبّت الموضوع')">{{ thread.thread.pinned ? 'إلغاء التثبيت' : 'تثبيت' }}</button>
        <button class="small" @click="moderate({ locked: !thread.thread.locked }, thread.thread.locked ? 'فُتح الموضوع' : 'أُغلق الموضوع')">{{ thread.thread.locked ? 'فتح الموضوع' : 'إغلاق الموضوع' }}</button>
        <select v-model="moveTo" @focus="ensureIndex">
          <option value="">نقل إلى…</option>
          <option v-for="b in boards" :key="b.id" :value="b.id" :disabled="b.id === thread.thread.board_id">{{ b.category }} ‹ {{ b.name }}</option>
        </select>
        <button class="small" :disabled="!moveTo" @click="move">انقل</button>
        <button class="small danger" @click="removeThread">حذف الموضوع</button>
      </div>

      <section class="tbox">
        <h2 class="tcat"><span v-if="thread.thread.pinned" class="flag">مثبت</span><span v-if="thread.thread.locked" class="flag off">مغلق</span>{{ thread.thread.title }}
          <small v-if="thread.thread.company">{{ thread.thread.company }}</small></h2>

        <!-- the opening post, on the first page -->
        <article v-if="thread.page === 1" class="fpost">
          <header><span class="num">{{ stamp(thread.thread.created_at) }}</span><span class="num">#1</span></header>
          <div class="fpost-main">
            <aside>
              <b>{{ thread.thread.author?.alias ?? thread.thread.alias }}</b>
              <span class="rank" :class="thread.thread.author?.role">{{ thread.thread.author?.rank }}</span>
              <small>تاريخ التسجيل: <span class="num">{{ day(thread.thread.author?.joined_at) }}</span></small>
              <small>المشاركات: <span class="num">{{ count(thread.thread.author?.posts) }}</span></small>
            </aside>
            <div class="fcontent">
              <form v-if="editing.id === 0" class="editor" @submit.prevent="saveEdit">
                <label>العنوان<input v-model="editing.title" required minlength="3" maxlength="150" /></label>
                <textarea v-model="editing.body" rows="8" required maxlength="10000"></textarea>
                <div class="actions"><button class="primary small" :disabled="busy">حفظ</button><button type="button" class="small" @click="editing.id = null">إلغاء</button></div>
              </form>
              <template v-else>
                <PostBody :text="thread.thread.body" />
                <small v-if="thread.thread.edited_at" class="edited">آخر تعديل: <span class="num">{{ stamp(thread.thread.edited_at) }}</span></small>
                <div v-if="thread.thread.author?.signature" class="sig">{{ thread.thread.author.signature }}</div>
              </template>
            </div>
          </div>
          <footer v-if="editing.id !== 0">
            <button class="small" @click="quote(thread.thread.alias, thread.thread.body)">اقتباس</button>
            <button v-if="canEdit(thread.thread.user_id)" class="small" @click="startEdit(null)">تعديل</button>
          </footer>
        </article>

        <article v-for="p in thread.posts" :key="p.id" class="fpost">
          <header><span class="num">{{ stamp(p.created_at) }}</span><span class="num">#{{ p.number }}</span></header>
          <div class="fpost-main">
            <aside>
              <b>{{ p.author?.alias ?? p.alias }}</b>
              <span class="rank" :class="p.author?.role">{{ p.author?.rank }}</span>
              <small>تاريخ التسجيل: <span class="num">{{ day(p.author?.joined_at) }}</span></small>
              <small>المشاركات: <span class="num">{{ count(p.author?.posts) }}</span></small>
            </aside>
            <div class="fcontent">
              <form v-if="editing.id === p.id" class="editor" @submit.prevent="saveEdit">
                <textarea v-model="editing.body" rows="6" required maxlength="10000"></textarea>
                <div class="actions"><button class="primary small" :disabled="busy">حفظ</button><button type="button" class="small" @click="editing.id = null">إلغاء</button></div>
              </form>
              <template v-else>
                <PostBody :text="p.body" />
                <small v-if="p.edited_at" class="edited">آخر تعديل: <span class="num">{{ stamp(p.edited_at) }}</span></small>
                <div v-if="p.author?.signature" class="sig">{{ p.author.signature }}</div>
              </template>
            </div>
          </div>
          <footer v-if="editing.id !== p.id">
            <button class="small" @click="quote(p.alias, p.body)">اقتباس</button>
            <button v-if="canEdit(p.user_id)" class="small" @click="startEdit(p)">تعديل</button>
            <button v-if="isStaff" class="small danger" @click="removePost(p)">حذف</button>
          </footer>
        </article>
      </section>

      <div class="fbar"><span class="pages"><button v-for="n in pageList(thread.pages)" :key="n" class="small" :class="{ on: n === thread.page }" @click="reloadThread(n)">{{ n }}</button></span></div>

      <!-- quick reply -->
      <section v-if="!thread.thread.locked || isStaff" class="tbox">
        <h2 class="tcat">الرد السريع</h2>
        <form v-if="state.user" class="editor" @submit.prevent="sendReply">
          <div class="toolbar">
            <button type="button" class="small" title="عريض" @click="wrap(replyTarget, 'b')"><b>B</b></button>
            <button type="button" class="small" title="مائل" @click="wrap(replyTarget, 'i')"><i>I</i></button>
            <button type="button" class="small" title="تحته خط" @click="wrap(replyTarget, 'u')"><u>U</u></button>
            <button type="button" class="small" @click="wrap(replyTarget, 'quote')">اقتباس</button>
          </div>
          <textarea ref="replyBox" v-model="reply" rows="6" required maxlength="10000" placeholder="اكتب ردّك هنا"></textarea>
          <div class="actions"><button class="primary" :disabled="busy">أضف الرد</button></div>
        </form>
        <p v-else class="empty"><a @click="state.showAuth = true">سجّل الدخول</a> لتضيف ردًّا.</p>
      </section>
    </template>
  </main>
</template>
