import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import type { Billing } from './api'

const plan = (code: string, name: string, monthly: string, annual: string, current = false) => ({
  code, name, description: null, monthly_price: monthly, annual_price: annual, trial_days: 14, free: monthly === '0.00',
  limits: { branches: 1, users: 5, terminals: 2, products: null }, features: { pos: true, purchasing: code !== 'basic' }, current,
})

const billing: Billing = {
  subscription: { status: 'trialing', plan: { code: 'starter', name: 'Starter', free: false }, billing_cycle: 'monthly', trial_ends_at: '2026-11-29T00:00:00+08:00',
    current_period_start: null, current_period_end: null, grace_ends_at: null, cancel_at_period_end: false, pending_plan: null, pending_billing_cycle: null },
  plans: [plan('basic', 'Basic', '499.00', '4990.00'), plan('starter', 'Starter', '999.00', '9990.00', true), plan('professional', 'Professional', '1999.00', '19990.00')],
  usage: { branches: 1, users: 2, terminals: 1, products: 40 },
  invoices: [],
  payment: { gateway: 'manual', bank_details: 'BPI · AvantPOS Inc. · 1234-5678-90' },
}

describe('subscription page', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('chooses a yearly plan and reports a bank transfer for its invoice', async () => {
    let checkout: unknown = null
    let transfer: Record<string, unknown> | null = null
    const invoice = { id: 9, number: 'AVP-000009', kind: 'new', plan: { code: 'professional', name: 'Professional' }, billing_cycle: 'annual', period_start: '2026-11-15', period_end: '2027-11-15',
      subtotal: '19990.00', credit: '0.00', vat_amount: '2141.79', total: '19990.00', status: 'open', issued_at: '2026-11-15T10:00:00+08:00', due_at: '2026-11-22T10:00:00+08:00', paid_at: null, gateway: 'manual', checkout_url: null, payments: [] }
    const account = makeAccount({ permissions: ['billing.view', 'billing.manage', 'business.view'] })
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/billing') return { status: 200, data: { data: { ...billing, invoices: checkout ? [invoice] : [] } } }
      if (req.url === '/api/v1/billing/checkout') {
        checkout = req.data
        return { status: 201, data: { data: { invoice, checkout_url: null, scheduled: false, message: 'Pay by bank transfer, then send us the reference number.' } } }
      }
      if (req.url === '/api/v1/billing/invoices/9/manual-payment') {
        transfer = req.data as Record<string, unknown>
        return { status: 201, data: { data: invoice } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/settings/billing')

    expect(await screen.findByText(/You are on a free trial of Starter/, {}, { timeout: 15000 })).toBeInTheDocument()
    await user.click(screen.getByRole('radio', { name: /Yearly/ }))
    expect(screen.getByText('₱19,990.00')).toBeInTheDocument()
    const pro = screen.getByText('Professional', { selector: 'p' }).closest('div')!.parentElement!
    await user.click(within(pro).getByRole('button', { name: 'Choose' }))
    await user.click(await screen.findByRole('button', { name: 'Continue' }))
    await waitFor(() => expect(checkout).toEqual({ plan: 'professional', billing_cycle: 'annual' }))

    const dialog = await screen.findByRole('dialog', { name: 'Pay AVP-000009 by transfer' })
    expect(within(dialog).getByText(/BPI · AvantPOS Inc./)).toBeInTheDocument()
    await user.type(within(dialog).getByLabelText(/Paid through/), 'BPI')
    await user.type(within(dialog).getByLabelText(/Reference number/), 'BPI-7788')
    await user.click(within(dialog).getByRole('button', { name: 'Send for verification' }))
    await waitFor(() => expect(transfer).toMatchObject({ reference: 'BPI-7788', channel: 'BPI', amount: '19990.00' }))
  }, 60000)
})

describe('platform admin', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('asks for the authenticator code after the password when two-factor is on', async () => {
    const attempts: Array<Record<string, unknown>> = []
    let signedIn = false
    const admin = { id: 1, name: 'Super Admin', email: 'super@avantpos.ph', role: 'super_admin', two_factor: true }
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/admin/v1/auth/me') return signedIn ? { status: 200, data: { data: admin } } : { status: 401, data: { message: 'Unauthenticated.', code: 'UNAUTHENTICATED' } }
      if (req.url === '/api/admin/v1/auth/login') {
        const body = req.data as Record<string, unknown>
        attempts.push(body)
        if (!body.code) return { status: 422, data: { message: 'Enter the 6-digit code from your authenticator app.', code: 'TWO_FACTOR_REQUIRED' } }
        signedIn = true
        return { status: 200, data: { data: admin } }
      }
      if (req.url === '/api/admin/v1/dashboard') return { status: 200, data: { data: {
        tenants: { total: 12, by_status: { active: 10, trial: 2 }, new_30_days: 4, new_7_days: 1 },
        subscriptions: { by_status: { active: 10, trialing: 2 }, by_plan: { Starter: 6 }, paying: 10 }, mrr: '19990.00', arr: '239880.00',
        signups: [{ date: '2026-11-15', signups: 1 }], expiring: [], payments: { last_30_days: '45000.00', count_30_days: 20, pending_verification: 2, recent: [] }, support: { open: 3, waiting: 1 },
      } } }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/admin/login')
    await user.type(await screen.findByLabelText('E-mail address', {}, { timeout: 15000 }), 'super@avantpos.ph')
    await user.type(screen.getByLabelText('Password'), 'AdminSecret1')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    await user.type(await screen.findByLabelText('Authenticator code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Verify and sign in' }))
    expect(await screen.findByText('₱19,990.00', {}, { timeout: 15000 })).toBeInTheDocument() // MRR
    expect(attempts.at(-1)).toMatchObject({ email: 'super@avantpos.ph', code: '123456' })
  }, 60000)
})
