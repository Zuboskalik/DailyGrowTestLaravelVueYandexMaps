<script setup>
import { computed, ref } from 'vue'
import { useCompanyStore } from '../stores/company'
import { translateApiMessage } from '../i18n/apiMessages'

const emit = defineEmits(['added'])

const companyStore = useCompanyStore()

const url = ref('')
const clientError = ref('')
const serverError = ref('')
const submitting = ref(false)

// Lenient hint only — the backend is the real source of truth for what
// counts as a valid Yandex Maps organization link (plan.md §2.2).
const looksLikeYandexOrgLink = computed(() => {
  if (!url.value) return true
  return /^https?:\/\/(www\.)?yandex\.[a-z.]+\/maps\//i.test(url.value.trim())
})

function isWellFormedUrl(value) {
  try {
    const parsed = new URL(value)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
  } catch {
    return false
  }
}

async function onSubmit() {
  clientError.value = ''
  serverError.value = ''

  const value = url.value.trim()

  if (!isWellFormedUrl(value)) {
    clientError.value = 'Введите корректную ссылку, начинающуюся с http(s)://.'
    return
  }

  submitting.value = true

  try {
    const company = await companyStore.addCompany(value)
    url.value = ''
    emit('added', company)
  } catch (e) {
    if (e.response?.status === 422) {
      const errors = e.response.data.errors
      const message = errors ? Object.values(errors).flat().join(' ') : e.response.data.message
      serverError.value = translateApiMessage(message, 'Не удалось проверить ссылку.')
    } else {
      serverError.value = 'Не удалось добавить организацию. Попробуйте ещё раз.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form class="company-input" @submit.prevent="onSubmit">
    <label class="field">
      <span>Ссылка на организацию в Яндекс.Картах</span>
      <input
        v-model="url"
        type="text"
        placeholder="https://yandex.ru/maps/org/.../123456/"
        :aria-invalid="!looksLikeYandexOrgLink"
      />
    </label>

    <p v-if="!looksLikeYandexOrgLink" class="hint">
      Это не похоже на ссылку на организацию в Яндекс.Картах — но вы можете отправить её.
    </p>
    <p v-if="clientError" class="error" role="alert">{{ clientError }}</p>
    <p v-if="serverError" class="error" role="alert">{{ serverError }}</p>

    <button type="submit" :disabled="submitting || !url">
      {{ submitting ? 'Добавление…' : 'Добавить организацию' }}
    </button>
  </form>
</template>

<style scoped>
.company-input {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 480px;
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

.field input[aria-invalid='true'] {
  border-color: #d9a400;
}

.hint {
  color: #8a6d00;
  margin: 0;
  font-size: 0.85rem;
}

.error {
  color: #b00020;
  margin: 0;
  font-size: 0.85rem;
}

button {
  align-self: flex-start;
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 4px;
  background: #2f6fed;
  color: white;
  font-size: 0.95rem;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: default;
}
</style>
