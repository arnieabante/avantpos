import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import { presetRange } from './dates'
import { formatCell } from './format'
import type { ReportData } from './types'

const plan = (features: Record<string, boolean>) => ({ code: 'business', name: 'Business', limits: null, features })

describe('report helpers', () => {
  it('builds date presets in the business time zone', () => {
    expect(presetRange('this_month', '2026-11-15')).toEqual({ from: '2026-11-01', to: '2026-11-15' })
    expect(presetRange('last_month', '2026-03-10')).toEqual({ from: '2026-02-01', to: '2026-02-28' })
    expect(presetRange('this_week', '2026-11-15')).toEqual({ from: '2026-11-09', to: '2026-11-15' }) // Monday start
    expect(presetRange('yesterday', '2026-01-01')).toEqual({ from: '2025-12-31', to: '2025-12-31' })
  })

  it('formats cells by column type', () => {
    expect(formatCell('1234.5', 'money')).toBe('₱1,234.50')
    expect(formatCell('2.000', 'qty')).toBe('2')
    expect(formatCell('23.4', 'percent')).toBe('23.4%')
    expect(formatCell(null, 'money')).toBe('—')
  })
})

describe('reports pages', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  const sales: ReportData = {
    key: 'sales', title: 'Sales', notes: ['Net sales = gross − discounts − returns.'],
    columns: [
      { key: 'period', label: 'Date', type: 'text' },
      { key: 'transactions', label: 'Sales', type: 'int' },
      { key: 'net_sales', label: 'Net sales', type: 'money' },
    ],
    rows: [{ period: '2026-11-14', transactions: 3, net_sales: '120.00' }, { period: '2026-11-15', transactions: 2, net_sales: '80.00' }],
    totals: { period: 'Total', transactions: 5, net_sales: '200.00' },
    filters: { from: '2026-11-01', to: '2026-11-15', branches: [{ id: 11, name: 'Main Branch' }], group_by: 'day', timezone: 'Asia/Manila' },
  }

  it('shows a report with totals, re-runs it with new filters, and offers exports', async () => {
    const asked: Array<Record<string, unknown>> = []
    const account = makeAccount({ permissions: ['reports.sales', 'reports.export'], subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan: plan({ report_exports: true }) } })
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/reports') return { status: 200, data: { data: [{ key: 'sales', allowed: true, feature: null, available: true }], meta: { can_export: true, can_see_costs: false } } }
      if (req.url === '/api/v1/reports/sales') {
        asked.push(req.params)
        return { status: 200, data: { data: sales } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/reports/sales')

    const table = await screen.findByRole('table', {}, { timeout: 15000 })
    expect(within(table).getByText('₱200.00')).toBeInTheDocument()
    expect(screen.getByText('Net sales = gross − discounts − returns.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Excel' })).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('Group by'), 'month')
    await waitFor(() => expect(asked.at(-1)).toMatchObject({ group_by: 'month' }))
    expect(asked[0]).toMatchObject({ from: expect.stringMatching(/^\d{4}-\d{2}-01$/), branch_id: 'all' })
  }, 60000)

  it('lists only the reports the user may open and marks plan upgrades', async () => {
    const account = makeAccount({ permissions: ['reports.sales'] })
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/reports') return { status: 200, data: { data: [
        { key: 'sales', allowed: true, feature: null, available: true },
        { key: 'products', allowed: true, feature: 'advanced_reports', available: false },
        { key: 'inventory-valuation', allowed: false, feature: null, available: true },
      ], meta: { can_export: false, can_see_costs: false } } }
      return undefined
    })
    renderRoutes(routes, '/reports')
    expect(await screen.findByRole('link', { name: /Sales Daily, weekly/ }, { timeout: 15000 })).toBeInTheDocument()
    expect(screen.getByText('Upgrade')).toBeInTheDocument()
    expect(screen.queryByText('Inventory valuation')).not.toBeInTheDocument()
  }, 60000)
})

describe('expenses', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('records an expense for approval', async () => {
    let body: Record<string, unknown> | null = null
    const account = makeAccount({ permissions: ['expenses.create'], subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan: plan({ expenses: true }) } })
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/expenses' && req.method === 'GET') return { status: 200, data: { data: [], meta: { pagination: { total: 0 } } } }
      if (req.url === '/api/v1/expenses/options') return { status: 200, data: { data: { categories: [{ id: 3, name: 'Electricity' }], payment_methods: [{ id: 1, name: 'Cash' }], branches: [{ id: 11, name: 'Main Branch' }], can_approve: false } } }
      if (req.url === '/api/v1/expenses' && req.method === 'POST') {
        body = req.data as Record<string, unknown>
        return { status: 201, data: { data: { id: 5, number: 'EXP-000000001', status: 'submitted' } } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/expenses')
    await user.click(await screen.findByRole('button', { name: 'Record expense' }, { timeout: 15000 }))
    const dialog = await screen.findByRole('dialog', { name: 'Record an expense' })
    await user.selectOptions(await within(dialog).findByLabelText(/Category/), '3')
    await user.type(within(dialog).getByLabelText(/Amount/), '2450.75')
    await user.type(within(dialog).getByLabelText(/Paid to/), 'Meralco')
    await user.click(within(dialog).getByRole('button', { name: 'Submit for approval' }))

    await waitFor(() => expect(body).not.toBeNull())
    expect(body).toMatchObject({ expense_category_id: 3, amount: '2450.75', vendor: 'Meralco', branch_id: 11, submit: true, paid_from_drawer: false })
    expect(await screen.findByText('EXP-000000001 submitted for approval')).toBeInTheDocument()
  }, 60000)
})
