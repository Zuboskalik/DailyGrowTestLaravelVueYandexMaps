import axios from 'axios'
import router from '../router'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000',
  withCredentials: true,
  // Backend runs on a different origin (localhost:8000) than the SPA
  // (localhost:5173) in dev, so axios needs to be told explicitly to
  // still read the XSRF-TOKEN cookie and echo it back as a header.
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
  },
})

/**
 * Sanctum SPA auth needs a fresh CSRF cookie before any state-changing
 * request; the XSRF-TOKEN cookie it sets is picked up automatically by
 * axios' withCredentials + xsrfCookieName defaults on the next request.
 */
export function ensureCsrfCookie() {
  return http.get('/sanctum/csrf-cookie')
}

http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status

    if (status === 401 && router.currentRoute.value.name !== 'login') {
      router.push({ name: 'login' })
    }

    if (status === 419) {
      // CSRF token expired: refresh it so the next retry can succeed.
      return ensureCsrfCookie().then(() => Promise.reject(error))
    }

    return Promise.reject(error)
  },
)

export default http
