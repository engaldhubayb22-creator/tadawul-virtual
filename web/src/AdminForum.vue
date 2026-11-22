<script setup>
// Admin: the forum's layout. Categories hold forums; an empty one can be removed.
import { onMounted, reactive, ref } from 'vue'
import { api, toast } from './store'

const categories = ref([])
const fresh = reactive({ category: '', board: {} }) // new category name; new forum name per category id

async function load() {
  try { categories.value = (await api('GET', '/forum/boards')).categories } catch (e) { toast(e.message, 'bad') }
}
async function run(action, done) {
  try { await action(); toast(done, 'good'); await load() } catch (e) { toast(e.message, 'bad') }
}
const addCategory = () => run(async () => { await api('POST', '/admin/forum/boards', { name: fresh.category }); fresh.category = '' }, 'أُضيف القسم')
const addBoard = (c) => run(async () => {
  await api('POST', '/admin/forum/boards', { name: fresh.board[c.id], parent_id: c.id }); fresh.board[c.id] = ''
}, 'أُضيف المنتدى')
const save = (b, extra = {}) => run(() => api('PUT', '/admin/forum/boards/' + b.id,
  { name: b.name, description: b.description ?? '', position: Number(b.position), ...extra }), 'حُفظ ' + b.name)
const remove = (b) => run(() => api('DELETE', '/admin/forum/boards/' + b.id), 'حُذف ' + b.name)
onMounted(load)
</script>

<template>
  <section class="panel pad">
    <h3>أقسام المنتدى <span class="muted">القسم يحتوي منتديات، والمواضيع تُكتب داخل المنتديات</span></h3>
    <div v-for="c in categories" :key="c.id" class="fcat">
      <div class="row">
        <label class="grow">اسم القسم<input v-model="c.name" maxlength="80" /></label>
        <label>الترتيب<input v-model="c.position" type="number" min="0" dir="ltr" /></label>
        <button class="small" @click="save(c)">حفظ القسم</button>
        <button class="small danger" :disabled="c.boards.length > 0" @click="remove(c)">حذف القسم</button>
      </div>
      <table>
        <thead><tr><th>المنتدى</th><th>الوصف</th><th>الترتيب</th><th>المواضيع</th><th>للإدارة فقط</th><th></th></tr></thead>
        <tbody>
          <tr v-for="b in c.boards" :key="b.id">
            <td><input v-model="b.name" maxlength="80" /></td>
            <td><input v-model="b.description" maxlength="300" /></td>
            <td><input v-model="b.position" type="number" min="0" dir="ltr" class="narrow" /></td>
            <td class="num">{{ b.threads }}</td>
            <td><label class="check"><input type="checkbox" :checked="b.staff_only" @change="save(b, { staff_only: $event.target.checked })" /> إعلانات</label></td>
            <td class="acts"><button class="small" @click="save(b)">حفظ</button>
              <button class="small danger" :disabled="b.threads > 0" @click="remove(b)">حذف</button></td>
          </tr>
          <tr v-if="!c.boards.length"><td colspan="6" class="empty">لا توجد منتديات في هذا القسم</td></tr>
        </tbody>
      </table>
      <form class="row" @submit.prevent="addBoard(c)">
        <label class="grow">منتدى جديد في هذا القسم<input v-model="fresh.board[c.id]" required minlength="2" maxlength="80" /></label>
        <button class="primary small">أضف المنتدى</button>
      </form>
    </div>
    <form class="row" @submit.prevent="addCategory">
      <label class="grow">قسم رئيسي جديد<input v-model="fresh.category" required minlength="2" maxlength="80" /></label>
      <button class="primary small">أضف القسم</button>
    </form>
    <p class="muted">لا يُحذف منتدى فيه مواضيع ولا قسم فيه منتديات: انقل المواضيع أولًا من أدوات الإشراف داخل الموضوع. "للإدارة فقط" يجعل فتح المواضيع للإدارة والمشرفين، ويبقى الرد متاحًا للأعضاء.</p>
  </section>
</template>
