import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import type { Customer } from './types'

const plan = (features: Record<string, boolean>) => ({ code: 'business', name: 'Business', limits: null, features: { customers: true, ...features } })
const accountWith = (permissions: string[], features: Record<string, boolean> = { credit_sales: true, loyalty: true }) =>
  makeAccount({ permissions, subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan: plan(features) } })

const nena: Customer = {
  id: 8, code: null, name: 'Aling Nena', phone: '+639171234567', email: null, tin: null, address: null, birthdate: null, group: null,
  sc_pwd: null, allow_credit: true, credit_limit: '2000.00', balance: '750.50', available_credit: '1249.50', loyalty_points: '0.00',
  notes: null, is_active: true, created_at: '2026-11-14T00:00:00+08:00',
}
const page = (items: unknown[]) => ({ status: 200, data: { data: items, meta: { pagination: { current_page: 1, per_page: 20, total: items.length, last_page: 1 } } } })

describe('customers', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('adds a Senior Citizen customer; credit fields are hidden from staff who cannot manage credit', async () => {
    let sent: Record<string, unknown> | null = null
    const account = accountWith(['customers.view', 'customers.manage'])
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/customers' && req.method === 'GET') return page([])
      if (req.url === '/api/v1/customer-groups') return { status: 200, data: { data: [] } }
      if (req.url === '/api/v1/customers' && req.method === 'POST') {
        sent = req.data as Record<string, unknown>
        return { status: 201, data: { data: { ...nena, id: 9, name: 'Lola Charing' } } }
      }
      if (req.url === '/api/v1/customers/9') return { status: 200, data: { data: { ...nena, id: 9, name: 'Lola Charing' } } }
      return undefined
    })

    const user = userEvent.setup()
    const { router } = renderRoutes(routes, '/customers')
    await user.click((await screen.findAllByRole('button', { name: 'Add customer' }, { timeout: 15000 }))[0]!)
    const dialog = await screen.findByRole('dialog', { name: 'Add customer' })

    expect(within(dialog).queryByLabelText('Allow credit (utang)')).not.toBeInTheDocument()
    await user.type(within(dialog).getByLabelText(/^Name/), 'Lola Charing')
    await user.selectOptions(within(dialog).getByLabelText('Senior Citizen / PWD'), 'senior')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await within(dialog).findByText('Enter the ID number.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText(/ID number/), 'QC-2019-0045678')
    await user.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(sent).not.toBeNull())
    expect(sent).toMatchObject({ name: 'Lola Charing', phone: null, sc_pwd_id_type: 'senior', sc_pwd_id_no: 'QC-2019-0045678', customer_group_id: null })
    expect(sent).not.toHaveProperty('allow_credit')
    await waitFor(() => expect(router.state.location.pathname).toBe('/customers/9'))
  })

  it('records a GCash payment with its reference number', async () => {
    let paid: Record<string, unknown> | null = null
    const account = accountWith(['customers.view', 'customers.credit_manage', 'business.view'])
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/customers/8') return { status: 200, data: { data: nena } }
      if (req.url === '/api/v1/customers/8/ledger') return page([{ id: 1, type: 'opening_balance', number: null, amount: '750.50', balance_after: '750.50', payment_method: null, reference_no: null, notes: null, branch: null, user: null, created_at: '2026-11-14T00:00:00+08:00' }])
      if (req.url === '/api/v1/customers/8/loyalty') return page([])
      if (req.url === '/api/v1/payment-methods') {
        return { status: 200, data: { data: [
          { id: 1, type: 'cash', name: 'Cash', requires_reference: false, sort_order: 1, is_active: true, gateway_code: null },
          { id: 2, type: 'gcash', name: 'GCash', requires_reference: true, sort_order: 2, is_active: true, gateway_code: null },
        ] } }
      }
      if (req.url === '/api/v1/customers/8/payments') {
        paid = req.data as Record<string, unknown>
        return { status: 201, data: { data: { number: 'CP-000000001', amount: '500.00', balance_after: '250.50', customer: { ...nena, balance: '250.50' } } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/customers/8')
    expect(await screen.findByText('₱750.50', {}, { timeout: 15000 })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Record payment' }))
    const dialog = await screen.findByRole('dialog', { name: 'Payment from Aling Nena' })
    const amount = within(dialog).getByLabelText(/Amount received/)
    expect(amount).toHaveValue('750.50')
    await user.clear(amount)
    await user.type(amount, '500')
    await within(dialog).findByRole('option', { name: 'GCash' })
    await user.selectOptions(within(dialog).getByLabelText(/Paid with/), '2')
    await user.click(within(dialog).getByRole('button', { name: 'Record payment' }))
    expect(await within(dialog).findByText('Enter the GCash reference number.')).toBeInTheDocument()

    await user.type(within(dialog).getByLabelText(/Reference number/), '1009 223 5512')
    await user.click(within(dialog).getByRole('button', { name: 'Record payment' }))

    await waitFor(() => expect(paid).toEqual({ amount: '500', payment_method_id: 2, reference_no: '1009 223 5512', notes: null }))
    expect(await screen.findByText('Payment of ₱500.00 recorded.')).toBeInTheDocument()
  })
})
