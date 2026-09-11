import { defineStore } from 'pinia'
import http from '../api/http'

const POLL_INTERVAL_MS = 3000
const ACTIVE_STATUSES = ['pending', 'processing']

export const useCompanyStore = defineStore('company', {
  state: () => ({
    companies: [],
    currentCompany: null,
    reviews: [],
    pagination: {
      currentPage: 1,
      lastPage: 1,
      total: 0,
    },
    loadingCompanies: false,
    loadingReviews: false,
    pollingHandle: null,
  }),

  actions: {
    async fetchCompanies() {
      this.loadingCompanies = true

      try {
        const response = await http.get('/api/companies')
        this.companies = response.data.data
      } finally {
        this.loadingCompanies = false
      }
    },

    async addCompany(url) {
      const response = await http.post('/api/companies', { url })
      const company = response.data.data

      this.syncCompanyInList(company)

      return company
    },

    async fetchCompany(id) {
      const response = await http.get(`/api/companies/${id}`)
      this.currentCompany = response.data.data
      this.syncCompanyInList(this.currentCompany)
      return this.currentCompany
    },

    /** Keeps the sidebar list in sync with whatever fetchCompany just saw. */
    syncCompanyInList(company) {
      const index = this.companies.findIndex((c) => c.id === company.id)

      if (index === -1) {
        this.companies.unshift(company)
      } else {
        this.companies[index] = company
      }
    },

    async startParsing(id) {
      const response = await http.post(`/api/companies/${id}/parse`)
      await this.fetchCompany(id)
      this.watchParsingStatus(id)
      return response.data
    },

    async fetchReviews(id, page = 1) {
      this.loadingReviews = true

      try {
        const response = await http.get(`/api/companies/${id}/reviews`, {
          params: { page },
        })
        this.reviews = response.data.data
        this.pagination = {
          currentPage: response.data.meta.current_page,
          lastPage: response.data.meta.last_page,
          total: response.data.meta.total,
        }
      } finally {
        this.loadingReviews = false
      }
    },

    /**
     * Polls fetchCompany every 3s while the run is pending/processing,
     * stopping itself once the status settles or stopWatchingParsingStatus
     * is called (e.g. on unmount).
     */
    watchParsingStatus(id) {
      this.stopWatchingParsingStatus()

      this.pollingHandle = setInterval(async () => {
        const company = await this.fetchCompany(id)

        if (!ACTIVE_STATUSES.includes(company.parse_status)) {
          this.stopWatchingParsingStatus()
        }
      }, POLL_INTERVAL_MS)
    },

    stopWatchingParsingStatus() {
      if (this.pollingHandle !== null) {
        clearInterval(this.pollingHandle)
        this.pollingHandle = null
      }
    },
  },
})
