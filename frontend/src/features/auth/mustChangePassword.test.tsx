import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'

describe('password chosen by someone else', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('makes a business user set their own password before the app opens', async () => {
    let changed: Record<string, unknown> | null = null
    const base = makeAccount()
    let account = { ...base, user: { ...base.user, email: 'cashier@trd-motorshop.avantpos.co', must_change_password: true } }
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/auth/password') {
        changed = req.data as Record<string, unknown>
        account = { ...account, user: { ...account.user, must_change_password: false } }
        return { status: 200, data: { message: 'Password changed.' } }
      }
      return { status: 200, data: { data: [], meta: { pagination: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } } }
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/')

    expect(await screen.findByRole('heading', { name: 'Set your own password' }, { timeout: 15000 })).toBeInTheDocument()
    expect(screen.getByText('cashier@trd-motorshop.avantpos.co')).toBeInTheDocument()
    await user.type(screen.getByLabelText(/Current password/), 'Password123')
    await user.type(screen.getByLabelText(/^New password/), 'Password123')
    await user.type(screen.getByLabelText(/Confirm new password/), 'Password123')
    await user.click(screen.getByRole('button', { name: 'Save and continue' }))
    expect(await screen.findByText('Choose a password different from the one you were given.')).toBeInTheDocument()
    expect(changed).toBeNull()

    await user.clear(screen.getByLabelText(/^New password/))
    await user.type(screen.getByLabelText(/^New password/), 'My-own-pass-1')
    await user.clear(screen.getByLabelText(/Confirm new password/))
    await user.type(screen.getByLabelText(/Confirm new password/), 'My-own-pass-1')
    await user.click(screen.getByRole('button', { name: 'Save and continue' }))

    await waitFor(() => expect(changed).toEqual({ current_password: 'Password123', password: 'My-own-pass-1', password_confirmation: 'My-own-pass-1' }))
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Set your own password' })).toBeNull(), { timeout: 15000 })
  }, 60000)

  it('makes a platform admin set their own password before the admin area opens', async () => {
    let changed = false
    let admin = { id: 2, name: 'Ana', email: 'ana@avantpos.co', role: 'support', two_factor: false, must_change_password: true }
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/admin/v1/auth/me') return { status: 200, data: { data: admin } }
      if (req.url === '/api/admin/v1/auth/password') {
        changed = true
        admin = { ...admin, must_change_password: false }
        return { status: 200, data: { data: admin } }
      }
      if (req.url === '/api/admin/v1/dashboard') return { status: 200, data: { data: {
        tenants: { total: 2, by_status: { trial: 2 }, new_30_days: 2, new_7_days: 2 },
        subscriptions: { by_status: { trialing: 2 }, by_plan: { Basic: 1 }, paying: 0 }, mrr: '0.00', arr: '0.00',
        signups: [], expiring: [], payments: { last_30_days: '0.00', count_30_days: 0, pending_verification: 0, recent: [] }, support: { open: 0, waiting: 0 },
      } } }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/admin')

    expect(await screen.findByRole('heading', { name: 'Set your own password' }, { timeout: 15000 })).toBeInTheDocument()
    await user.type(screen.getByLabelText(/Current password/), 'Start-pass-1')
    await user.type(screen.getByLabelText(/^New password/), 'My-own-pass-1')
    await user.type(screen.getByLabelText(/Confirm new password/), 'My-own-pass-1')
    await user.click(screen.getByRole('button', { name: 'Save and continue' }))

    await waitFor(() => expect(changed).toBe(true))
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Set your own password' })).toBeNull(), { timeout: 15000 })
  }, 60000)
})
