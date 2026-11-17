import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import type { PosSetup, RegisterReading, Sale } from '@/features/pos/types'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes } from '@/test/utils'
import { lineRefund, remainingQty } from './refund'

const plan = { code: 'business', name: 'Business', limits: null, features: { pos: true } }
const account = (permissions: string[]) =>
  makeAccount({ permissions, subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan } })

const item = {
  id: 1, product_variant_id: 101, name: 'Coke Mismo', sku: 'COKE', unit: 'pc', qty: '3.000', unit_price: '20.00', line_discount: '0.00', allocated_discount: '0.00',
  sc_pwd_discount: '0.00', tax_class: 'vatable' as const, vat_amount: '6.43', line_total: '60.00', qty_refunded: '0.000',
}
const sale: Sale = {
  id: 900, invoice_no: '000000001', status: 'completed', sold_at: '2026-11-15T09:30:00+08:00', branch: { id: 11, name: 'Main Branch' },
  terminal: { id: 2, code: 'POS-2', name: 'Counter 2', min: null, serial_no: null, ptu_no: null, ptu_issued_at: null },
  cashier: { id: 7, name: 'Arnel Santos' }, customer: null,
  totals: { gross: '60.00', line_discount: '0.00', transaction_discount: '0.00', sc_pwd_discount: '0.00', loyalty_discount: '0.00', vatable_sales: '53.57', vat_amount: '6.43', vat_exempt_sales: '0.00', zero_rated_sales: '0.00', total: '60.00', amount_paid: '100.00', change: '40.00' },
  points: { redeemed: '0.00', earned: '0.00' }, items: [item], discounts: [],
  payments: [{ method: 'Cash', type: 'cash', amount: '60.00', tendered: '100.00', change: '40.00', reference_no: null }],
  notes: null, void: null, refunds: [], can: { void: true, refund: true, reprint: true },
}
const setup = {
  business: { name: 'Arnel Motoshop', registered_name: null, business_style: null, tin: '123456789', vat_registered: true, address: null, phone: null, logo_url: null },
  branch: { id: 11, code: 'MAIN', name: 'Main Branch', is_main: true, tin_branch_code: '00000', address: null, phone: null },
  session: { id: 5, opened_at: '2026-11-15T08:00:00+08:00' }, terminal: null,
  receipt: { id: null, name: 'Standard', paper: '80mm', header_text: null, footer_text: null, show_logo: false, layout: {} },
  payment_methods: [{ id: 1, type: 'cash', name: 'Cash', requires_reference: false }, { id: 9, type: 'credit', name: 'Charge to account', requires_reference: false }],
  discount_presets: [], categories: [], loyalty: null, features: { customers: true, credit_sales: true, loyalty: false }, max_discount_percent: '20', vat_rate: '12.0000', next_invoice_no: '000000001',
  approvers: [{ id: 30, name: 'Maria Reyes', permissions: ['sales.void', 'sales.refund'] }],
} satisfies PosSetup

describe('refund amounts', () => {
  it('gives each unit its share and the last unit the exact remainder', () => {
    expect(lineRefund(sale, item, '1')).toBe('20.00')
    expect(lineRefund(sale, item, '4')).toBeNull()
    const odd = { ...item, line_total: '50.00' }
    expect(lineRefund(sale, odd, '1')).toBe('16.67')
    const after = { ...odd, qty_refunded: '1.000' }
    const refunded: Sale = { ...sale, refunds: [{ id: 1, number: 'RF-1', reason: 'x', subtotal: '0', vat_amount: '0', total: '16.67', refunded_at: '', terminal: null, processed_by: null, authorized_by: null, items: [{ sale_item_id: 1, qty: '1.000', amount: '16.67', vat_amount: '0', restock_to: 'sellable' }], payments: [] }] }
    expect(remainingQty(after)).toBe('2')
    expect(lineRefund(refunded, after, '2')).toBe('33.33') // 50.00 − 16.67
  })
})

describe('sales pages', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('voids a sale with a reason (manager)', async () => {
    let body: unknown = null
    const me = account(['pos.sell', 'sales.view_all', 'sales.void', 'sales.refund', 'sales.reprint'])
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: me } }
      if (req.url === '/api/v1/pos/setup') return { status: 200, data: { data: setup } }
      if (req.url === '/api/v1/sales/900') return { status: 200, data: { data: sale } }
      if (req.url === '/api/v1/sales/900/void') {
        body = req.data
        return { status: 200, data: { data: { ...sale, status: 'voided', void: { voided_at: '2026-11-15T10:00:00+08:00', reason: 'Wrong item', by: { id: 7, name: 'Arnel Santos' }, authorized_by: null }, can: { void: false, refund: false, reprint: true } } } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/sales/900')

    await user.click(await screen.findByRole('button', { name: 'Void' }, { timeout: 15000 }))
    const dialog = await screen.findByRole('dialog', { name: 'Void invoice 000000001' })
    await user.click(within(dialog).getByRole('button', { name: 'Void ₱60.00' }))
    expect(within(dialog).getByText('Say why the sale is voided.')).toBeInTheDocument()
    await user.type(within(dialog).getByLabelText(/Reason/), 'Wrong item')
    await user.click(within(dialog).getByRole('button', { name: 'Void ₱60.00' }))

    expect(await screen.findByText('This sale was voided')).toBeInTheDocument()
    expect(body).toEqual({ reason: 'Wrong item', override_token: null })
    expect(screen.queryByRole('button', { name: 'Void' })).not.toBeInTheDocument()
  }, 60000)

  it('refunds one item with a supervisor PIN (cashier)', async () => {
    let refundBody: Record<string, unknown> | null = null
    const me = account(['pos.sell', 'sales.view_own'])
    me.user.id = 7
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: me } }
      if (req.url === '/api/v1/pos/setup') return { status: 200, data: { data: setup } }
      if (req.url === '/api/v1/sales/900') return { status: 200, data: { data: sale } }
      if (req.url === '/api/v1/auth/override') return { status: 201, data: { data: { override_token: 'tok-r', expires_at: '', supervisor: { id: 30, name: 'Maria Reyes' } } } }
      if (req.url === '/api/v1/sales/900/refunds') {
        refundBody = req.data as Record<string, unknown>
        const refund = { id: 1, number: 'RF-000000001', reason: 'Expired', subtotal: '17.86', vat_amount: '2.14', total: '20.00', refunded_at: '2026-11-15T10:00:00+08:00', terminal: null, processed_by: { id: 7, name: 'Arnel Santos' }, authorized_by: { id: 30, name: 'Maria Reyes' }, items: [{ sale_item_id: 1, qty: '1.000', amount: '20.00', vat_amount: '2.14', restock_to: 'damaged' }], payments: [{ method: 'Cash', type: 'cash', amount: '20.00' }] }
        return { status: 201, data: { data: { refund, sale: { ...sale, status: 'partially_refunded', items: [{ ...item, qty_refunded: '1.000' }], refunds: [refund] } } } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/sales/900')

    await user.click(await screen.findByRole('button', { name: 'Return items' }, { timeout: 15000 }))
    const dialog = await screen.findByRole('dialog', { name: /Return items/ })
    // Charge to account is never a refund method.
    expect(within(dialog).queryByRole('option', { name: 'Charge to account' })).not.toBeInTheDocument()
    await user.type(within(dialog).getByLabelText('Quantity to return of Coke Mismo'), '1')
    await user.selectOptions(within(dialog).getByLabelText('Where Coke Mismo goes'), 'damaged')
    await user.type(within(dialog).getByLabelText(/Reason/), 'Expired')
    await user.click(within(dialog).getByRole('button', { name: 'Refund ₱20.00' }))

    const approval = await screen.findByRole('dialog', { name: 'Supervisor approval' })
    await user.type(within(approval).getByLabelText(/PIN/), '4321')
    await user.click(within(approval).getByRole('button', { name: 'Approve' }))

    expect(await screen.findByText(/RF-000000001 · ₱20.00/)).toBeInTheDocument()
    expect(refundBody).toMatchObject({ reason: 'Expired', items: [{ sale_item_id: 1, qty: '1', restock: 'damaged' }], payment_method_id: 1, override_token: 'tok-r' })
  }, 60000)

  it('runs the Z reading for a terminal and shows the report', async () => {
    const reading: RegisterReading = {
      id: 3, type: 'Z', reading_no: 1, business_date: '2026-11-15', generated_at: '2026-11-15T22:00:00+08:00', generated_by: { id: 7, name: 'Arnel Santos' },
      terminal: { id: 2, code: 'POS-2', name: 'Counter 2', min: '2511150001', serial_no: null, ptu_no: null }, cash_register_session_id: null,
      beginning_invoice_no: '000000001', ending_invoice_no: '000000002', transaction_count: 1, gross_sales: '80.00', sc_discount: '0.00', pwd_discount: '0.00',
      other_discount: '0.00', void_amount: '20.00', refund_amount: '20.00', net_sales: '40.00', vatable_sales: '35.71', vat_amount: '4.29', vat_exempt_sales: '0.00',
      zero_rated_sales: '0.00', accumulated_grand_total_before: '0.00', accumulated_grand_total_after: '40.00', void_count: 1, refund_count: 1,
      payments: [{ method: 'Cash', type: 'cash', received: '60.00', refunded: '20.00' }],
    }
    let done = false
    const me = account(['cash.z_reading', 'cash.view_all_sessions', 'pos.sell'])
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: me } }
      if (req.url === '/api/v1/pos/readings') return { status: 200, data: { data: done ? [reading] : [], meta: { pagination: { current_page: 1, last_page: 1, per_page: 25, total: done ? 1 : 0 } } } }
      if (req.url === '/api/v1/pos/terminals') return { status: 200, data: { data: { terminals: [{ id: 2, code: 'POS-2', name: 'Counter 2', in_use_by: null, drawers: [] }], my_session: null } } }
      if (req.url === '/api/v1/pos/z-readings') {
        done = true
        return { status: 201, data: { data: reading } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderRoutes(routes, '/readings')

    expect(await screen.findByText('No readings yet', {}, { timeout: 15000 })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Z reading (end of day)' }))
    const dialog = await screen.findByRole('dialog', { name: 'Z reading (end of day)' })
    await user.click(await within(dialog).findByRole('button', { name: 'Run Z reading' }))

    const report = await screen.findByRole('dialog', { name: 'Z reading #1' })
    const text = within(report).getAllByTestId('reading')[0]!
    expect(text).toHaveTextContent('NET SALES₱40.00')
    expect(text).toHaveTextContent('NEW GRAND TOTAL₱40.00')
    expect(text).toHaveTextContent('MIN: 2511150001')
  }, 60000)
})
