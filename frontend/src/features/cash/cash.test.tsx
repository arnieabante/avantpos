import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { uuid } from '@/lib/uuid'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import { countPayload, countTotal } from './count'
import type { CashSession, PosTerminal } from './types'

const plan = { code: 'business', name: 'Business', limits: null, features: { pos: true } }
const cashier = () =>
  makeAccount({
    permissions: ['pos.sell', 'cash.open_session', 'cash.cash_in_out', 'cash.close_session'],
    subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan },
  })

const terminals: PosTerminal[] = [
  { id: 1, code: 'POS-1', name: 'Counter 1', in_use_by: { id: 99, name: 'Maria' }, drawers: [{ id: 1, name: 'Drawer 1', shared: false, in_use: true }] },
  { id: 2, code: 'POS-2', name: 'Counter 2', in_use_by: null, drawers: [{ id: 2, name: 'Drawer 2', shared: false, in_use: false }] },
]

const openSession: CashSession = {
  id: 5, status: 'open', branch: { id: 11, name: 'Main Branch' }, terminal: { id: 2, code: 'POS-2', name: 'Counter 2' }, drawer: { id: 2, name: 'Drawer 2' },
  cashier: { id: 7, name: 'Arnel Santos' }, opened_at: '2026-11-15T08:00:00+08:00', opening_float: '2000.00', closed_at: null, closed_by: null,
  counted_cash: null, expected_cash: null, variance: null, closing_notes: null, can: { operate: true, close: true },
  summary: { opening_float: '2000.00', cash_sales: '350.00', cash_refunds: '0.00', cash_in: '0.00', cash_out: '0.00', payouts: '100.00', sales_count: 2, payments_by_method: [{ method: 'Cash', type: 'cash', amount: '350.00' }] },
  denominations: null, movements: [],
}

describe('cash helpers', () => {
  it('totals a bill and coin count exactly and builds the API payload', () => {
    const counts = { '1000': '2', '20c': '2', '0.25': '3', '0.05': '7', '500': '' }
    expect(countTotal(counts)).toBe('2041.10') // 2000 + 40 + 0.75 + 0.35
    expect(countPayload(counts)).toEqual({ '1000': 2, '20c': 2, '0.25': 3, '0.05': 7 })
  })

  it('makes v4 UUIDs', () => {
    expect(uuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(uuid()).not.toBe(uuid())
  })
})

describe('register page', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('starts a shift on a free terminal with a counted float', async () => {
    let opened: Record<string, unknown> | null = null
    const account = cashier()
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/pos/terminals') return { status: 200, data: { data: { terminals, my_session: opened ? openSession : null } } }
      if (req.url === '/api/v1/cash-sessions' && req.method === 'POST') {
        opened = req.data as Record<string, unknown>
        return { status: 201, data: { data: openSession } }
      }
      if (req.url === '/api/v1/cash-sessions/5') return { status: 200, data: { data: openSession } }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/pos')

    const busy = await screen.findByRole('radio', { name: /Counter 1/ }, { timeout: 15000 })
    expect(busy).toBeDisabled()
    expect(within(busy).getByText('In use by Maria')).toBeInTheDocument()
    // The only free terminal is pre-selected.
    expect(screen.getByRole('radio', { name: /Counter 2/ })).toHaveAttribute('aria-checked', 'true')

    await user.click(screen.getByRole('button', { name: 'Count bills and coins' }))
    await user.type(screen.getByLabelText('Number of ₱1,000 bills'), '2')
    await user.type(screen.getByLabelText('Number of 25¢ coins'), '4')
    expect(screen.getByTestId('count-total')).toHaveTextContent('₱2,001.00')

    await user.click(screen.getByRole('button', { name: 'Start shift with ₱2,001.00' }))
    await waitFor(() => expect(opened).not.toBeNull())
    expect(opened).toMatchObject({ terminal_id: 2, cash_register_id: 2, opening_float: '2001.00' })
    expect(String(opened!.client_uuid)).toMatch(/^[0-9a-f-]{36}$/)

    // The open shift replaces the start screen with the selling screen; the shift tab keeps the
    // expected cash hidden (blind count).
    expect(await screen.findByRole('tab', { name: 'Sell', selected: true })).toBeInTheDocument()
    await user.click(screen.getByRole('tab', { name: 'Shift & drawer' }))
    expect(await screen.findByRole('heading', { name: 'Shift on Counter 2' })).toBeInTheDocument()
    expect(screen.getByText('The expected amount is shown after you close and count.')).toBeInTheDocument()
  })

  it('closes the shift blind and then shows over / short', async () => {
    let closedWith: Record<string, unknown> | null = null
    const account = cashier()
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/pos/terminals') return { status: 200, data: { data: { terminals, my_session: openSession } } }
      if (req.url === '/api/v1/cash-sessions/5') return { status: 200, data: { data: openSession } }
      if (req.url === '/api/v1/cash-sessions/5/close') {
        closedWith = req.data as Record<string, unknown>
        return { status: 200, data: { data: { ...openSession, status: 'closed', closed_at: '2026-11-15T20:00:00+08:00', counted_cash: '2240.75', expected_cash: '2250.00', variance: '-9.25', can: { operate: false, close: false } } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/pos')
    await user.click(await screen.findByRole('tab', { name: 'Shift & drawer' }, { timeout: 15000 }))
    await user.click(await screen.findByRole('button', { name: 'Close shift' }))
    const dialog = await screen.findByRole('dialog', { name: 'Close shift: count the drawer' })
    await user.type(within(dialog).getByLabelText('Number of ₱1,000 bills'), '2')
    await user.type(within(dialog).getByLabelText('Number of ₱100 bills'), '2')
    await user.type(within(dialog).getByLabelText('Number of ₱20 coins'), '2')
    await user.type(within(dialog).getByLabelText('Number of 25¢ coins'), '3')
    await user.click(within(dialog).getByRole('button', { name: 'Close shift with ₱2,240.75' }))

    await waitFor(() => expect(closedWith).toEqual({ denominations: { '1000': 2, '100': 2, '20c': 2, '0.25': 3 }, counted_cash: null, notes: null }))
    expect(await screen.findByRole('heading', { name: 'Shift closed' })).toBeInTheDocument()
    expect(screen.getAllByText('Short ₱9.25').length).toBeGreaterThan(0)
  })
})
