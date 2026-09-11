import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/http', () => ({
  default: { get: vi.fn(), post: vi.fn() },
  ensureCsrfCookie: vi.fn().mockResolvedValue(),
}))

import http from '../../src/api/http'
import { useAuthStore } from '../../src/stores/auth'

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('sets the user on successful login', async () => {
    http.post.mockResolvedValueOnce({ data: { id: 1, email: 'admin@example.com' } })

    const auth = useAuthStore()
    await auth.login({ email: 'admin@example.com', password: 'password' })

    expect(auth.user).toEqual({ id: 1, email: 'admin@example.com' })
    expect(auth.isAuthenticated).toBe(true)
  })

  it('does not set a user and rethrows on a 422 validation error', async () => {
    const error = {
      response: { status: 422, data: { message: 'The given data was invalid.' } },
    }
    http.post.mockRejectedValueOnce(error)

    const auth = useAuthStore()

    await expect(
      auth.login({ email: 'admin@example.com', password: 'wrong' }),
    ).rejects.toBe(error)

    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
  })

  it('clears the user on logout', async () => {
    http.post.mockResolvedValueOnce({})

    const auth = useAuthStore()
    auth.user = { id: 1 }

    await auth.logout()

    expect(auth.user).toBeNull()
  })

  it('fetchUser restores the session from the cookie', async () => {
    http.get.mockResolvedValueOnce({ data: { id: 1, email: 'admin@example.com' } })

    const auth = useAuthStore()
    await auth.fetchUser()

    expect(auth.user).toEqual({ id: 1, email: 'admin@example.com' })
    expect(auth.checkedSession).toBe(true)
  })

  it('fetchUser leaves the user null on a 401 without throwing', async () => {
    http.get.mockRejectedValueOnce({ response: { status: 401 } })

    const auth = useAuthStore()
    await auth.fetchUser()

    expect(auth.user).toBeNull()
    expect(auth.checkedSession).toBe(true)
  })
})
