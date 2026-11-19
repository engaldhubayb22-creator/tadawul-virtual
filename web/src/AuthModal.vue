<script setup>
import { reactive, ref } from 'vue'
import { authenticate, state } from './store'

const mode = ref('login')
const form = reactive({ name: '', alias: '', mobile: '', password: '' })
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await authenticate(mode.value, mode.value === 'login' ? { mobile: form.mobile, password: form.password } : { ...form })
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="overlay" @click.self="state.showAuth = false">
    <form class="modal" @submit.prevent="submit">
      <div class="tabs">
        <button type="button" :class="{ on: mode === 'login' }" @click="mode = 'login'">دخول</button>
        <button type="button" :class="{ on: mode === 'register' }" @click="mode = 'register'">حساب جديد</button>
      </div>
      <template v-if="mode === 'register'">
        <label>الاسم<input v-model="form.name" required maxlength="100" autocomplete="name" /></label>
        <label>الاسم الظاهر للمتداولين<input v-model="form.alias" required minlength="3" maxlength="30" /></label>
      </template>
      <label>رقم الجوال<input v-model="form.mobile" required inputmode="numeric" placeholder="05xxxxxxxx" dir="ltr" autocomplete="tel" /></label>
      <label>كلمة المرور<input v-model="form.password" type="password" required minlength="8" dir="ltr"
        :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" /></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="primary" :disabled="busy">{{ mode === 'login' ? 'دخول' : 'إنشاء الحساب' }}</button>
      <p v-if="mode === 'register'" class="hint">الرصيد افتراضي للعب والتعلّم ولا يمكن سحبه نقدًا.</p>
    </form>
  </div>
</template>
