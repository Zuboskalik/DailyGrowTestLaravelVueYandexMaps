import { defineStore } from 'pinia'
import http, { ensureCsrfCookie } from '../api/http'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    checkedSession: false,
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
  },

  actions: {
    async login({ email, password }) {
      await ensureCsrfCookie()
      const response = await http.post('/login', { email, password })
      this.user = response.data
      this.checkedSession = true
      return this.user
    },

    async logout() {
      await http.post('/logout')
      this.user = null
      this.checkedSession = true
    },

    /**
     * Restores the session from the browser's cookie, if any. Called once
     * on app start / first navigation so a page refresh doesn't log the
     * user out.
     */
    async fetchUser() {
      try {
        const response = await http.get('/api/user')
        this.user = response.data
      } catch (error) {
        this.user = null

        if (error.response?.status !== 401) {
          throw error
        }
      } finally {
        this.checkedSession = true
      }

      return this.user
    },
  },
})
