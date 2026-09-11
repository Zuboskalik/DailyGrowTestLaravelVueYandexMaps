<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { translateApiMessage } from '../i18n/apiMessages'

const emit = defineEmits(['success'])

const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')
const submitting = ref(false)

async function onSubmit() {
  error.value = ''
  submitting.value = true

  try {
    await auth.login({ email: email.value, password: password.value })
    emit('success')
  } catch (e) {
    if (e.response?.status === 422) {
      const errors = e.response.data.errors
      const message = errors ? Object.values(errors).flat().join(' ') : e.response.data.message
      error.value = translateApiMessage(message, 'Неверный email или пароль.')
    } else {
      error.value = 'Не удалось войти. Пожалуйста, попробуйте ещё раз.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form class="login-form" @submit.prevent="onSubmit">
    <label class="field">
      <span>Email</span>
      <input v-model="email" type="email" name="email" required autocomplete="username" />
    </label>

    <label class="field">
      <span>Пароль</span>
      <input
        v-model="password"
        type="password"
        name="password"
        required
        autocomplete="current-password"
      />
    </label>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <button type="submit" :disabled="submitting">
      {{ submitting ? 'Вход…' : 'Войти' }}
    </button>
  </form>
</template>

<style scoped>
.login-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 320px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}

.field input {
  padding: 0.5rem;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 1rem;
}

.error {
  color: #b00020;
  margin: 0;
  font-size: 0.9rem;
}

button {
  padding: 0.6rem;
  border: none;
  border-radius: 4px;
  background: #2f6fed;
  color: white;
  font-size: 1rem;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: default;
}
</style>
