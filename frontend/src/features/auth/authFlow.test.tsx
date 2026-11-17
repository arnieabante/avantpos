import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { storage } from '@/lib/utils'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'

const UNAUTHENTICATED = { status: 401, data: { message: 'Unauthenticated.', code: 'UNAUTHENTICATED' } }

describe('tenant authentication flow', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })

  afterEach(() => backend?.restore())

  it('sends guests to the login page, remembering where they were going', async () => {
    backend = fakeBackend((req) => (req.url === '/api/v1/auth/me' ? UNAUTHENTICATED : undefined))
    const { router } = renderRoutes(routes, '/account')

    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
    expect(router.state.location.search).toBe('?redirect=%2Faccount')
  })

  it('signs in and continues to the requested page', async () => {
    const account = makeAccount()
    let signedIn = false
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return signedIn ? { status: 200, data: { data: account } } : UNAUTHENTICATED
      if (req.url === '/api/v1/auth/login') {
        signedIn = true
        return { status: 200, data: { data: account } }
      }
      return undefined
    })

    const user = userEvent.setup()
    const { router } = renderRoutes(routes, '/login?redirect=%2Faccount')

    await user.type(await screen.findByLabelText('E-mail address'), 'owner@motoshop.test')
    await user.type(screen.getByLabelText('Password'), 'Password123')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { name: 'My account' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/account')

    const login = backend.requests.find((r) => r.url === '/api/v1/auth/login')
    expect(login?.data).toEqual({ email: 'owner@motoshop.test', password: 'Password123', remember: false })
  })

  it('shows server validation errors next to the field', async () => {
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') return UNAUTHENTICATED
      if (req.url === '/api/v1/auth/login') {
        return { status: 422, data: { message: 'These credentials do not match our records.', code: 'VALIDATION_ERROR', errors: { email: ['These credentials do not match our records.'] } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/login')

    await user.type(await screen.findByLabelText('E-mail address'), 'owner@motoshop.test')
    await user.type(screen.getByLabelText('Password'), 'wrong')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    const message = await screen.findByText('These credentials do not match our records.')
    expect(screen.getByLabelText('E-mail address')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('E-mail address').getAttribute('aria-describedby')).toContain(message.id)
  })

  it('validates required fields before calling the API', async () => {
    backend = fakeBackend((req) => (req.url === '/api/v1/auth/me' ? UNAUTHENTICATED : undefined))
    const user = userEvent.setup()
    renderRoutes(routes, '/login')

    await user.click(await screen.findByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Enter your e-mail address.')).toBeInTheDocument()
    expect(backend.requests.some((r) => r.url === '/api/v1/auth/login')).toBe(false)
  })

  it('blocks a suspended business and still lets the user sign out', async () => {
    let signedOut = false
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') {
        return signedOut ? UNAUTHENTICATED : { status: 403, data: { message: 'This business account is suspended. Please contact AvantPOS support.', code: 'TENANT_SUSPENDED' } }
      }
      if (req.url === '/api/v1/auth/logout') {
        signedOut = true
        return { status: 200, data: { message: 'Logged out.' } }
      }
      return undefined
    })

    const user = userEvent.setup()
    const { router } = renderRoutes(routes, '/')

    expect(await screen.findByRole('heading', { name: 'This business account is not available' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(backend.requests.some((r) => r.method === 'POST' && r.url === '/api/v1/auth/logout')).toBe(true)
  })

  it('sends the remembered branch with API requests and lets the user switch', async () => {
    const account = makeAccount()
    storage.set(`avantpos.branch.${account.user.id}`, '12')
    backend = fakeBackend((req) => (req.url === '/api/v1/auth/me' ? { status: 200, data: { data: account } } : undefined))

    const user = userEvent.setup()
    renderRoutes(routes, '/')

    const switcher = await screen.findByRole('button', { name: /change branch/i })
    expect(switcher).toHaveTextContent('Caloocan')

    await user.click(switcher)
    await user.click(screen.getByRole('menuitem', { name: /main branch/i }))

    expect(storage.get(`avantpos.branch.${account.user.id}`)).toBe('11')
    expect(await screen.findByRole('button', { name: /change branch/i })).toHaveTextContent('Main Branch')
  })
})
