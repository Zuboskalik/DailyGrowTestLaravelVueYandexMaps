<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useCompanyStore } from '../stores/company'
import { translateApiMessage } from '../i18n/apiMessages'
import CompanyInput from '../components/CompanyInput.vue'
import ParsingStatusBadge from '../components/ParsingStatusBadge.vue'
import CompanyMetrics from '../components/CompanyMetrics.vue'
import ReviewsList from '../components/ReviewsList.vue'
import Pagination from '../components/Pagination.vue'

const props = defineProps({
  id: {
    type: [String, Number],
    default: null,
  },
})

const router = useRouter()
const auth = useAuthStore()
const companyStore = useCompanyStore()

const parseError = ref('')

async function loadSelected(id) {
  companyStore.stopWatchingParsingStatus()

  if (!id) {
    companyStore.currentCompany = null
    return
  }

  const company = await companyStore.fetchCompany(id)
  await companyStore.fetchReviews(id, 1)

  if (['pending', 'processing'].includes(company.parse_status)) {
    companyStore.watchParsingStatus(id)
  }
}

onMounted(async () => {
  await companyStore.fetchCompanies()
  await loadSelected(props.id)
})

watch(
  () => props.id,
  (id) => loadSelected(id),
)

onUnmounted(() => {
  companyStore.stopWatchingParsingStatus()
})

function selectCompany(company) {
  router.push({ name: 'company', params: { id: company.id } })
}

function onCompanyAdded(company) {
  selectCompany(company)
}

async function onStartParsing() {
  parseError.value = ''

  try {
    await companyStore.startParsing(props.id)
  } catch (e) {
    parseError.value = translateApiMessage(e.response?.data?.message, 'Не удалось запустить сбор отзывов.')
  }
}

async function onPageChange(page) {
  await companyStore.fetchReviews(props.id, page)
}

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="dashboard">
    <header class="dashboard-header">
      <h1>Отзывы с Яндекс.Карт</h1>
      <button type="button" class="logout" @click="onLogout">Выйти</button>
    </header>

    <section class="add-company">
      <h2>Добавить организацию</h2>
      <CompanyInput @added="onCompanyAdded" />
    </section>

    <div class="dashboard-body">
      <aside class="company-list">
        <h2>Организации</h2>
        <p v-if="companyStore.companies.length === 0" class="empty-state">
          Организации ещё не добавлены.
        </p>
        <ul v-else>
          <li
            v-for="company in companyStore.companies"
            :key="company.id"
            :class="{ active: String(company.id) === String(props.id) }"
          >
            <button type="button" @click="selectCompany(company)">
              <span class="name">{{ company.name || company.url }}</span>
              <ParsingStatusBadge :status="company.parse_status" />
            </button>
          </li>
        </ul>
      </aside>

      <section v-if="companyStore.currentCompany" class="company-detail">
        <div class="detail-header">
          <h2>{{ companyStore.currentCompany.name || companyStore.currentCompany.url }}</h2>
          <ParsingStatusBadge
            :status="companyStore.currentCompany.parse_status"
            :error-message="companyStore.currentCompany.last_error"
          />
        </div>

        <CompanyMetrics :company="companyStore.currentCompany" />

        <div class="parse-action">
          <button
            type="button"
            :disabled="['pending', 'processing'].includes(companyStore.currentCompany.parse_status)"
            @click="onStartParsing"
          >
            {{
              ['pending', 'processing'].includes(companyStore.currentCompany.parse_status)
                ? 'Идёт сбор…'
                : 'Запустить сбор отзывов'
            }}
          </button>
          <p v-if="parseError" class="error" role="alert">{{ parseError }}</p>
        </div>

        <h3>Отзывы</h3>
        <ReviewsList :reviews="companyStore.reviews" />
        <Pagination
          :current-page="companyStore.pagination.currentPage"
          :last-page="companyStore.pagination.lastPage"
          @update:page="onPageChange"
        />
      </section>

      <p v-else class="empty-state select-hint">Выберите организацию, чтобы увидеть детали.</p>
    </div>
  </div>
</template>

<style scoped>
.dashboard {
  max-width: 1000px;
  margin: 0 auto;
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.logout {
  padding: 0.4rem 0.8rem;
  border: 1px solid #ccc;
  border-radius: 4px;
  background: white;
  cursor: pointer;
}

.dashboard-body {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 1.5rem;
}

.company-list ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.company-list button {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.3rem;
  padding: 0.5rem;
  border: 1px solid #e5e5e5;
  border-radius: 6px;
  background: white;
  cursor: pointer;
  text-align: left;
}

.company-list li.active button {
  border-color: #2f6fed;
  background: #f0f5ff;
}

.name {
  font-weight: 500;
  word-break: break-all;
}

.company-detail {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.detail-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.parse-action {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.parse-action button {
  align-self: flex-start;
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 4px;
  background: #2f6fed;
  color: white;
  cursor: pointer;
}

.parse-action button:disabled {
  opacity: 0.6;
  cursor: default;
}

.empty-state {
  color: #777;
  font-style: italic;
}

.select-hint {
  padding-top: 2rem;
}

.error {
  color: #b00020;
  margin: 0;
  font-size: 0.85rem;
}
</style>
