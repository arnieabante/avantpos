import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { clearCookies, fakeBackend } from '@/test/utils'
import { api, onSessionEvent, scopeOf, setActiveBranchId } from './client'
import { ApiError } from './errors'

describe('api client', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    setActiveBranchId(null)
  })

  afterEach(() => backend?.restore())

  it('unwraps the { data } envelope', async () => {
    backend = fakeBackend(() => ({ status: 200, data: { data: { id: 1 } } }))
    await expect(api.get('/api/v1/things/1')).resolves.toEqual({ id: 1 })
  })

  it('returns items and pagination meta for collections', async () => {
    backend = fakeBackend(() => ({ status: 200, data: { data: [{ id: 1 }], meta: { pagination: { current_page: 2, total: 21 } } } }))
    await expect(api.getPage('/api/v1/things', { page: 2 })).resolves.toEqual({
      items: [{ id: 1 }],
      pagination: { current_page: 2, total: 21 },
    })
  })

  it('fetches the CSRF cookie before the first state-changing request only', async () => {
    backend = fakeBackend((req) => {
      if (req.url === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=token123; path=/'
        return { status: 204 }
      }
      return { status: 200, data: { data: null } }
    })

    await api.get('/api/v1/auth/me')
    await api.post('/api/v1/auth/login', { email: 'a@b.c' })
    await api.post('/api/v1/auth/login', { email: 'a@b.c' })

    expect(backend.requests.map((r) => `${r.method} ${r.url}`)).toEqual([
      'GET /api/v1/auth/me',
      'GET /sanctum/csrf-cookie',
      'POST /api/v1/auth/login',
      'POST /api/v1/auth/login',
    ])
  })

  it('sends X-Branch-Id on tenant requests but never on admin requests', async () => {
    document.cookie = 'XSRF-TOKEN=t; path=/'
    backend = fakeBackend(() => ({ status: 200, data: { data: null } }))
    setActiveBranchId(12)

    await api.get('/api/v1/products')
    await api.get('/api/admin/v1/auth/me')

    expect(backend.requests[0]?.headers['X-Branch-Id']).toBe('12')
    expect(backend.requests[1]?.headers['X-Branch-Id']).toBeUndefined()
  })

  it('refreshes the CSRF cookie and retries once on 419', async () => {
    document.cookie = 'XSRF-TOKEN=stale; path=/'
    let attempts = 0
    backend = fakeBackend((req) => {
      if (req.url === '/sanctum/csrf-cookie') return { status: 204 }
      attempts++
      return attempts === 1
        ? { status: 419, data: { message: 'Your session has expired.', code: 'CSRF_TOKEN_MISMATCH' } }
        : { status: 200, data: { data: 'ok' } }
    })

    await expect(api.post('/api/v1/things')).resolves.toBe('ok')
    expect(backend.requests.map((r) => r.url)).toEqual(['/api/v1/things', '/sanctum/csrf-cookie', '/api/v1/things'])
  })

  it('rejects with a normalised ApiError including field errors', async () => {
    document.cookie = 'XSRF-TOKEN=t; path=/'
    backend = fakeBackend(() => ({
      status: 422,
      data: { message: 'The email field is required.', code: 'VALIDATION_ERROR', errors: { email: ['The email field is required.'] } },
    }))

    const error = await api.post('/api/v1/auth/login', {}).catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).isValidation).toBe(true)
    expect((error as ApiError).fieldError('email')).toBe('The email field is required.')
  })

  it('broadcasts session events with the right scope', async () => {
    const listener = vi.fn()
    const unsubscribe = onSessionEvent(listener)
    backend = fakeBackend((req) =>
      req.url.startsWith('/api/admin')
        ? { status: 401, data: { message: 'Unauthenticated.', code: 'UNAUTHENTICATED' } }
        : { status: 403, data: { message: 'Suspended', code: 'TENANT_SUSPENDED' } },
    )

    await api.get('/api/v1/products').catch(() => undefined)
    await api.get('/api/admin/v1/tenants').catch(() => undefined)
    // skipSessionEvents silences the broadcast (used by GET /auth/me).
    await api.get('/api/v1/auth/me', { skipSessionEvents: true }).catch(() => undefined)
    unsubscribe()

    expect(listener).toHaveBeenCalledTimes(2)
    expect(listener.mock.calls[0]?.[0]).toMatchObject({ type: 'blocked', scope: 'tenant' })
    expect(listener.mock.calls[1]?.[0]).toMatchObject({ type: 'unauthenticated', scope: 'admin' })
  })

  it('omits X-Branch-Id when asked and reports an inaccessible branch', async () => {
    document.cookie = 'XSRF-TOKEN=t; path=/'
    const listener = vi.fn()
    const unsubscribe = onSessionEvent(listener)
    setActiveBranchId(99)
    backend = fakeBackend((req) =>
      req.url === '/api/v1/auth/me'
        ? { status: 200, data: { data: {} } }
        : { status: 403, data: { message: 'You do not have access to this branch.', code: 'BRANCH_FORBIDDEN' } },
    )

    await api.get('/api/v1/auth/me', { skipBranchHeader: true })
    await api.get('/api/v1/products').catch(() => undefined)
    unsubscribe()

    expect(backend.requests[0]?.headers['X-Branch-Id']).toBeUndefined()
    expect(listener).toHaveBeenCalledWith({ type: 'branch-invalid', scope: 'tenant' })
  })

  it('detects the API scope from relative and absolute URLs', () => {
    expect(scopeOf('/api/v1/auth/me')).toBe('tenant')
    expect(scopeOf('/api/admin/v1/auth/me')).toBe('admin')
    expect(scopeOf('https://api.avantpos.ph/api/admin/v1/x')).toBe('admin')
  })
})
