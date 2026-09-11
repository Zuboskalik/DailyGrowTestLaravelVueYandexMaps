import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/http', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}))

import http from '../../src/api/http'
import { useCompanyStore } from '../../src/stores/company'

describe('useCompanyStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('fetchCompanies populates the companies list', async () => {
    http.get.mockResolvedValueOnce({ data: { data: [{ id: 1, name: 'Cafe' }] } })

    const store = useCompanyStore()
    await store.fetchCompanies()

    expect(store.companies).toEqual([{ id: 1, name: 'Cafe' }])
  })

  it('addCompany appends the created company', async () => {
    http.post.mockResolvedValueOnce({ data: { data: { id: 5, name: null } } })

    const store = useCompanyStore()
    const company = await store.addCompany('https://yandex.ru/maps/org/x/5/')

    expect(company).toEqual({ id: 5, name: null })
    expect(store.companies).toEqual([{ id: 5, name: null }])
  })

  it('addCompany propagates a 422 validation error without mutating state', async () => {
    const error = { response: { status: 422, data: { errors: { url: ['Invalid link.'] } } } }
    http.post.mockRejectedValueOnce(error)

    const store = useCompanyStore()

    await expect(store.addCompany('not a url')).rejects.toBe(error)
    expect(store.companies).toEqual([])
  })

  it('startParsing refetches the company and begins polling', async () => {
    http.post.mockResolvedValueOnce({ data: { message: 'Parsing started.' } })
    http.get.mockResolvedValueOnce({ data: { data: { id: 1, parse_status: 'pending' } } })

    const store = useCompanyStore()
    await store.startParsing(1)

    expect(store.currentCompany).toEqual({ id: 1, parse_status: 'pending' })
    expect(store.pollingHandle).not.toBeNull()

    store.stopWatchingParsingStatus()
  })

  it('startParsing propagates a 409 conflict when a run is already active', async () => {
    const error = { response: { status: 409, data: { message: 'Already running.' } } }
    http.post.mockRejectedValueOnce(error)

    const store = useCompanyStore()

    await expect(store.startParsing(1)).rejects.toBe(error)
  })

  it('fetchReviews stores the page of reviews and pagination meta', async () => {
    http.get.mockResolvedValueOnce({
      data: {
        data: [{ id: 1 }, { id: 2 }],
        meta: { current_page: 1, last_page: 3, total: 120 },
      },
    })

    const store = useCompanyStore()
    await store.fetchReviews(1, 1)

    expect(store.reviews).toHaveLength(2)
    expect(store.pagination).toEqual({ currentPage: 1, lastPage: 3, total: 120 })
  })

  it('watchParsingStatus stops polling once the run settles', async () => {
    http.get
      .mockResolvedValueOnce({ data: { data: { id: 1, parse_status: 'processing' } } })
      .mockResolvedValueOnce({ data: { data: { id: 1, parse_status: 'completed' } } })

    const store = useCompanyStore()
    store.watchParsingStatus(1)

    await vi.advanceTimersByTimeAsync(3000)
    expect(store.currentCompany.parse_status).toBe('processing')
    expect(store.pollingHandle).not.toBeNull()

    await vi.advanceTimersByTimeAsync(3000)
    expect(store.currentCompany.parse_status).toBe('completed')
    expect(store.pollingHandle).toBeNull()
  })
})
