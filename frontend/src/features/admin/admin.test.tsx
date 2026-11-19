import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { clearCookies, fakeBackend, renderRoutes, type FakeRequest } from '@/test/utils'

const superAdmin = { id: 1, name: 'Chief', email: 'chief@avantpos.ph', role: 'super_admin', two_factor: true }

describe('platform admin: team and tenant management', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('lets a super admin add an admin with a role', async () => {
    let created: Record<string, unknown> | null = null
    const team = [{ id: 1, name: 'Chief', email: 'chief@avantpos.ph', role: 'super_admin', is_active: true, two_factor: true, last_login_at: null, created_at: null }]
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/admin/v1/auth/me') return { status: 200, data: { data: superAdmin } }
      if (req.url === '/api/admin/v1/admins' && req.method === 'GET') return { status: 200, data: { data: team } }
      if (req.url === '/api/admin/v1/admins' && req.method === 'POST') {
        created = req.data as Record<string, unknown>
        team.push({ id: 2, name: 'Ana', email: 'ana@avantpos.ph', role: 'billing', is_active: true, two_factor: false, last_login_at: null, created_at: null })
        return { status: 201, data: { data: team[1] } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/admin/team')

    expect(await screen.findByText('chief@avantpos.ph', {}, { timeout: 15000 })).toBeInTheDocument()
    // The menu groups pages in dropdowns; the Settings menu holds the admin team for super admins.
    await user.click(screen.getByRole('button', { name: /Settings/ }))
    expect(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Admin team/ })).toHaveClass('bg-brand-500/10') // current page
    await user.keyboard('{Escape}')
    await user.click(screen.getByRole('button', { name: 'Add admin' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add an admin' })
    await user.type(within(dialog).getByLabelText(/^Name/), 'Ana')
    await user.type(within(dialog).getByLabelText(/E-mail address/), 'ana@avantpos.ph')
    await user.selectOptions(within(dialog).getByLabelText(/Role/), 'billing')
    await user.type(within(dialog).getByLabelText(/Starting password/), 'Start-pass-123')
    await user.type(within(dialog).getByLabelText(/Confirm password/), 'Start-pass-123')
    await user.click(within(dialog).getByRole('button', { name: 'Add admin' }))

    await waitFor(() => expect(created).toEqual({ name: 'Ana', email: 'ana@avantpos.ph', role: 'billing', password: 'Start-pass-123', password_confirmation: 'Start-pass-123' }))
    expect(await screen.findByText('ana@avantpos.ph')).toBeInTheDocument()
  }, 60000)

  it('shows overdue businesses and enables one by hand for the next period', async () => {
    let enabled: Record<string, unknown> | null = null
    const listParams: Array<Record<string, unknown>> = []
    const sub = { status: 'expired', plan: { code: 'professional', name: 'Professional' }, billing_cycle: 'monthly', trial_ends_at: null,
      current_period_start: null, current_period_end: '2026-10-01T00:00:00+08:00', grace_ends_at: null, cancel_at_period_end: false, pending_plan: null,
      due_at: '2026-10-01T00:00:00+08:00', overdue: true }
    const tenant = { id: 7, name: 'Late Payer', slug: 'late-payer', subdomain: null, email: 'owner@late.test', status: 'active', owner: { name: 'Owner', email: 'owner@late.test' },
      plan: 'Professional', subscription_status: 'expired', ends_at: sub.current_period_end, due_at: sub.due_at, overdue: true,
      usage: { branches: 1, users: 2, terminals: 1, products: 30 }, created_at: '2026-09-01T00:00:00+08:00',
      registered_name: null, tin: null, vat_registered: false, phone: null, business_type: 'motor_shop', suspended_at: null, suspension_reason: null,
      activity: { sales_30_days: 0, last_sale_at: null, last_login_at: null }, subscription: sub, invoices: [], history: [] }
    const plans = [
      { id: 1, code: 'free', name: 'Free', is_active: false },
      { id: 2, code: 'professional', name: 'Professional', is_active: true },
      { id: 3, code: 'basic', name: 'Basic', is_active: true },
    ]
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/admin/v1/auth/me') return { status: 200, data: { data: superAdmin } }
      if (req.url === '/api/admin/v1/tenants') {
        listParams.push(req.params ?? {})
        return { status: 200, data: { data: [tenant], meta: { pagination: { current_page: 1, last_page: 1, per_page: 25, total: 1 } } } }
      }
      if (req.url === '/api/admin/v1/tenants/7' && req.method === 'GET') return { status: 200, data: { data: tenant } }
      if (req.url === '/api/admin/v1/plans') return { status: 200, data: { data: { plans, feature_keys: [] } } }
      if (req.url === '/api/admin/v1/tenants/7/subscription') {
        enabled = req.data as Record<string, unknown>
        return { status: 200, data: { data: { ...tenant, subscription: { ...sub, status: 'active', overdue: false } } } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/admin/tenants')

    await user.selectOptions(await screen.findByLabelText('Due', {}, { timeout: 15000 }), 'overdue')
    await waitFor(() => expect(listParams.at(-1)).toMatchObject({ due: 'overdue' }))
    expect(await screen.findByText('Overdue', { selector: 'span' })).toBeInTheDocument()
    await user.click(screen.getByText('Late Payer'))

    expect(await screen.findByText(/· overdue/, {}, { timeout: 15000 })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Enable access' }))
    const dialog = await screen.findByRole('dialog', { name: 'Enable access for Late Payer' })
    const planSelect = within(dialog).getByLabelText(/Plan/)
    await waitFor(() => expect(within(planSelect).getByRole('option', { name: 'Professional' })).toBeInTheDocument())
    expect(within(planSelect).queryByRole('option', { name: 'Free' })).toBeNull() // retired plans are not offered
    expect(within(dialog).getByLabelText(/Status/)).toHaveValue('active')
    const until = within(dialog).getByLabelText(/Paid until/) as HTMLInputElement
    const today = new Date()
    expect(new Date(until.value).getTime()).toBeGreaterThan(today.getTime()) // one month from today: the old end is in the past
    await user.type(within(dialog).getByLabelText(/Why/), 'Paid cash at the office')
    await user.click(within(dialog).getByRole('button', { name: 'Enable access' }))

    await waitFor(() => expect(enabled).toMatchObject({ plan: 'professional', status: 'active', billing_cycle: 'monthly', current_period_end: until.value, note: 'Paid cash at the office' }))
    expect(await screen.findByText('Late Payer can use AvantPOS again')).toBeInTheDocument()
  }, 60000)
})
