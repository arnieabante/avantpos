import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import type { CashSession, PosTerminal } from '@/features/cash/types'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import { addItem, cartRequest, emptyCart, heldCartPayload, parseEntry, restoreCart, setScPwd } from './cart'
import { cashSuggestions, summarize, type Tender } from './payments'
import { formatTin } from './receipt'
import type { CartRequest, PaymentMethodOption, PosItem, PosSetup, Quote, Sale } from './types'

/* ------------------------------------------------------------------ fixtures */

const coke: PosItem = {
  product_variant_id: 101, product_id: 50, name: 'Coke Mismo', sku: 'COKE-290', category_id: 3, color: '#e53935', image_url: null,
  type: 'standard', tax_class: 'vatable', sc_pwd_eligible: false, track_inventory: true, allow_negative_stock: false, qty_on_hand: '48.000', price: '20.00',
  units: [
    { id: null, name: 'Piece', abbreviation: 'pc', conversion_factor: '1.0000', allows_decimal: false, price: '20.00' },
    { id: 7, name: 'Box', abbreviation: 'box', conversion_factor: '12.0000', allows_decimal: false, price: '220.00' },
  ],
}
const biogesic: PosItem = { ...coke, product_variant_id: 102, name: 'Biogesic', sku: 'BIO', sc_pwd_eligible: true, price: '5.60', units: [{ ...coke.units[0]!, price: '5.60' }] }

const cash: PaymentMethodOption = { id: 1, type: 'cash', name: 'Cash', requires_reference: false }
const gcash: PaymentMethodOption = { id: 2, type: 'gcash', name: 'GCash', requires_reference: true }

const setup: PosSetup = {
  business: { name: 'Arnel Motoshop', registered_name: 'Arnel Santos', business_style: null, tin: '123456789', vat_registered: true, address: 'Rizal St., Cebu City', phone: null, logo_url: null },
  branch: { id: 11, code: 'MAIN', name: 'Main Branch', is_main: true, tin_branch_code: '00000', address: 'Rizal St., Cebu City', phone: null },
  session: { id: 5, opened_at: '2026-11-15T08:00:00+08:00' },
  terminal: { id: 2, code: 'POS-2', name: 'Counter 2', min: '2511150001', serial_no: 'SN-001', ptu_no: 'FP112025-001', ptu_issued_at: null, ptu_valid_until: null },
  receipt: { id: 1, name: 'Standard', paper: '80mm', header_text: null, footer_text: 'Salamat po!', show_logo: false, layout: {} },
  payment_methods: [cash, gcash],
  discount_presets: [],
  categories: [{ id: 3, parent_id: null, name: 'Drinks', color: '#e53935' }],
  loyalty: null,
  features: { customers: true, credit_sales: true, loyalty: false },
  max_discount_percent: '20',
  vat_rate: '12.0000',
  next_invoice_no: '000000001',
  approvers: [{ id: 30, name: 'Maria Reyes', permissions: ['pos.discount_transaction', 'pos.price_override'] }],
}

const session: CashSession = {
  id: 5, status: 'open', branch: { id: 11, name: 'Main Branch' }, terminal: { id: 2, code: 'POS-2', name: 'Counter 2' }, drawer: { id: 2, name: 'Drawer 2' },
  cashier: { id: 7, name: 'Arnel Santos' }, opened_at: '2026-11-15T08:00:00+08:00', opening_float: '2000.00', closed_at: null, closed_by: null,
  counted_cash: null, expected_cash: null, variance: null, closing_notes: null, can: { operate: true, close: true },
}
const terminals: PosTerminal[] = [{ id: 2, code: 'POS-2', name: 'Counter 2', in_use_by: { id: 7, name: 'Arnel Santos' }, drawers: [] }]

/** A quote like the server's: ₱20 a piece, VAT 12/112, a 10% sale discount when asked. */
function quoteFor(body: CartRequest): Quote {
  const qty = Number(body.items[0]?.qty ?? '0')
  const gross = (qty * 20).toFixed(2)
  const discount = body.discount ? (qty * 2).toFixed(2) : '0.00'
  const total = (qty * 20 - Number(discount)).toFixed(2)
  const vat = ((Number(total) * 12) / 112).toFixed(2)
  return {
    lines: body.items.map((item, index) => ({
      index, product_variant_id: item.product_variant_id, product_unit_id: null, name: 'Coke Mismo', sku: 'COKE-290', unit: 'pc', qty: item.qty,
      list_price: '20.00', unit_price: '20.00', price_overridden: false, gross, line_discount: '0.00', allocated_discount: discount, sc_pwd: false,
      sc_pwd_discount: '0.00', tax_class: 'vatable', vat_amount: vat, line_total: total, sc_pwd_eligible: false,
    })),
    totals: { gross, line_discount: '0.00', transaction_discount: discount, sc_pwd_discount: '0.00', loyalty_discount: '0.00', vatable_sales: (Number(total) - Number(vat)).toFixed(2), vat_amount: vat, vat_exempt_sales: '0.00', zero_rated_sales: '0.00', total },
    discounts: [], customer: null, points_redeemed: '0', vat_registered: true,
    approvals_required: body.discount ? ['pos.discount_transaction'] : [],
    approvals_missing: body.discount ? ['pos.discount_transaction'] : [],
  }
}

function saleFor(body: CartRequest & { payments: Array<{ amount: string }> }): Sale {
  const q = quoteFor(body)
  const paid = body.payments[0]!.amount
  const change = (Number(paid) - Number(q.totals.total)).toFixed(2)
  return {
    id: 900, invoice_no: '000000001', status: 'completed', sold_at: '2026-11-15T09:30:00+08:00', branch: { id: 11, name: 'Main Branch' },
    terminal: { id: 2, code: 'POS-2', name: 'Counter 2' }, cashier: { id: 7, name: 'Arnel Santos' }, customer: null,
    totals: { ...q.totals, amount_paid: paid, change }, points: { redeemed: '0.00', earned: '0.00' },
    items: q.lines.map((l, i) => ({ id: i + 1, product_variant_id: l.product_variant_id, name: l.name, sku: l.sku, unit: 'pc', qty: `${l.qty}.000`, unit_price: '20.00', line_discount: '0.00', allocated_discount: l.allocated_discount, sc_pwd_discount: '0.00', tax_class: 'vatable', vat_amount: l.vat_amount, line_total: l.line_total, qty_refunded: '0.000' })),
    discounts: [], payments: [{ method: 'Cash', type: 'cash', amount: q.totals.total, tendered: paid, change, reference_no: null }], notes: null,
  }
}

const cashier = () =>
  makeAccount({
    permissions: ['pos.sell', 'pos.hold', 'pos.cancel_cart', 'cash.open_session', 'customers.view'],
    subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan: { code: 'business', name: 'Business', limits: null, features: { pos: true, customers: true } } },
  })

/* ------------------------------------------------------------------ helpers */

describe('POS cart and payment helpers', () => {
  it('rings the same item onto one line and reads "3*code"', () => {
    let cart = addItem(emptyCart(), coke)
    cart = addItem(cart, coke)
    cart = addItem(cart, coke, 7)
    expect(cart.lines.map((l) => [l.unitId, l.qty])).toEqual([
      [null, '2'],
      [7, '1'],
    ])
    expect(parseEntry('3*4800016')).toEqual({ qty: '3', code: '4800016' })
    expect(parseEntry(' 1.5 x RICE ')).toEqual({ qty: '1.5', code: 'RICE' })
    expect(parseEntry('4800016')).toEqual({ qty: '1', code: '4800016' })
  })

  it('sends only items, quantities and requested discounts; Senior/PWD marks eligible items only', () => {
    let cart = addItem(addItem(emptyCart(), coke), biogesic)
    cart = setScPwd(cart, { type: 'senior', name: 'Lola Remedios', id_no: 'SC-12345', tin: '' })
    const body = cartRequest(cart)
    expect(body.items).toEqual([
      { product_variant_id: 101, product_unit_id: null, qty: '1' },
      { product_variant_id: 102, product_unit_id: null, qty: '1', sc_pwd: true },
    ])
    expect(body.sc_pwd).toEqual({ type: 'senior', name: 'Lola Remedios', id_no: 'SC-12345', tin: null })
    expect(JSON.stringify(body)).not.toMatch(/price|total|amount/)

    // A held / saved cart never keeps the ID number.
    const saved = JSON.parse(JSON.stringify(heldCartPayload(cart, '25.60')))
    expect(JSON.stringify(saved)).not.toContain('SC-12345')
    const restored = restoreCart(saved)
    expect(restored.scPwd).toEqual({ type: 'senior', name: 'Lola Remedios', id_no: '', tin: '' })
    expect(restored.lines.map((l) => [l.item.name, l.scPwd])).toEqual([
      ['Coke Mismo', false],
      ['Biogesic', true],
    ])
  })

  it('checks tenders: change only from cash, e-wallets not above the amount due, references required', () => {
    const t = (method: PaymentMethodOption, amount: string, reference = ''): Tender => ({ key: amount + method.id, method, amount, reference })
    expect(summarize([t(cash, '100')], '60.00')).toMatchObject({ paid: '100.00', change: '40.00', problem: null })
    expect(summarize([t(gcash, '30', 'REF1'), t(cash, '50')], '60.00')).toMatchObject({ change: '20.00', problem: null })
    expect(summarize([t(gcash, '70', 'REF1')], '60.00').problem).toMatch(/cannot be more than the amount due/)
    expect(summarize([t(gcash, '60')], '60.00').problem).toBe('Enter the GCash reference number.')
    expect(summarize([t(cash, '50.5')], '60.00').problem).toBe('₱9.50 still to be paid.')
    expect(cashSuggestions('137.25')).toEqual(['137.25', '140.00', '150.00', '200.00', '500.00'])
  })

  it('formats the TIN with the branch code', () => {
    expect(formatTin('123456789', '00000')).toBe('123-456-789-00000')
    expect(formatTin('123-456-789-00002', '00000')).toBe('123-456-789-00002')
    expect(formatTin(null)).toBeNull()
  })
})

/* ------------------------------------------------------------------ screen */

describe('selling screen', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  const serve = (extra?: (req: FakeRequest) => { status: number; data?: unknown } | undefined) => {
    const account = cashier()
    return fakeBackend((req) => {
      const custom = extra?.(req)
      if (custom) return custom
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/pos/terminals') return { status: 200, data: { data: { terminals, my_session: session } } }
      if (req.url === '/api/v1/pos/setup') return { status: 200, data: { data: setup } }
      if (req.url === '/api/v1/pos/items') return { status: 200, data: { data: [coke] } }
      if (req.url === '/api/v1/pos/items/scan') {
        return req.params.code === '4800000000017'
          ? { status: 200, data: { data: { ...coke, scanned_unit_id: null } } }
          : { status: 404, data: { message: 'No item for sale has the code.', code: 'PRODUCT_NOT_FOUND' } }
      }
      if (req.url === '/api/v1/pos/quote') return { status: 200, data: { data: quoteFor(req.data as CartRequest) } }
      return undefined
    })
  }

  it('scans items, takes cash, and shows the change and the BIR sales invoice', async () => {
    let checkout: Record<string, unknown> | null = null
    backend = serve((req) => {
      if (req.url === '/api/v1/pos/sales') {
        checkout = req.data as Record<string, unknown>
        return { status: 201, data: { data: saleFor(req.data as CartRequest & { payments: Array<{ amount: string }> }) } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/pos')
    const search = await screen.findByLabelText('Scan or search items', {}, { timeout: 15000 })

    await user.type(search, '4800000000017{Enter}')
    await user.type(await screen.findByLabelText('Scan or search items'), '4800000000017{Enter}')

    expect(await screen.findByText('₱40.00', { selector: '[data-testid=cart-total]' })).toBeInTheDocument()
    expect(screen.getByText(/2 pc ×/)).toBeInTheDocument()

    await user.keyboard('{F12}')
    const pay = await screen.findByRole('dialog', { name: 'Payment' })
    await user.click(within(pay).getByRole('button', { name: '₱50.00' }))
    expect(within(pay).getByTestId('change')).toHaveTextContent('₱10.00')
    await user.click(within(pay).getByRole('button', { name: 'Complete sale' }))

    const done = await screen.findByRole('dialog', { name: 'Sale complete' })
    expect(within(done).getByTestId('change-due')).toHaveTextContent('₱10.00')
    const receipt = within(done).getByTestId('receipt')
    expect(receipt).toHaveTextContent('VAT REG TIN: 123-456-789-00000')
    expect(receipt).toHaveTextContent('MIN: 2511150001')
    expect(receipt).toHaveTextContent('000000001')
    expect(receipt).toHaveTextContent('VAT Amount (12%)4.29')

    // Only the cart as entered and the tenders went to the server.
    expect(checkout).toMatchObject({
      items: [{ product_variant_id: 101, product_unit_id: null, qty: '2' }],
      payments: [{ payment_method_id: 1, amount: '50.00', reference_no: null }],
    })
    expect(String(checkout!.client_uuid)).toMatch(/^[0-9a-f-]{36}$/)

    // Next sale starts empty.
    await user.click(within(done).getByRole('button', { name: 'New sale' }))
    expect(await screen.findByText('Scan or tap an item to start a sale.')).toBeInTheDocument()
  }, 60000)

  it('asks a supervisor PIN for a sale discount and sends the approval with the sale', async () => {
    let override: Record<string, unknown> | null = null
    let checkout: Record<string, unknown> | null = null
    backend = serve((req) => {
      if (req.url === '/api/v1/auth/override') {
        override = req.data as Record<string, unknown>
        return { status: 201, data: { data: { override_token: 'tok-1', expires_at: '2026-11-15T09:32:00+08:00', supervisor: { id: 30, name: 'Maria Reyes' } } } }
      }
      if (req.url === '/api/v1/pos/sales') {
        checkout = req.data as Record<string, unknown>
        return { status: 201, data: { data: saleFor(req.data as CartRequest & { payments: Array<{ amount: string }> }) } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/pos')
    await user.click(await screen.findByRole('button', { name: /^Coke Mismo.*left$/ }, { timeout: 15000 }))

    await user.click(await screen.findByRole('button', { name: 'Discount' }))
    const discount = await screen.findByRole('dialog', { name: 'Sale discount' })
    await user.click(within(discount).getByRole('radio', { name: 'Amount or %' }))
    await user.type(within(discount).getByLabelText('Discount value'), '10')
    await user.click(within(discount).getByRole('button', { name: 'Apply' }))

    expect(await screen.findByText('Needs approval: Sale discount')).toBeInTheDocument()
    expect(await screen.findByText('₱18.00', { selector: '[data-testid=cart-total]' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /^Pay/ }))
    const pay = await screen.findByRole('dialog', { name: 'Payment' })
    await user.click(within(pay).getByRole('button', { name: '₱20.00' }))
    await user.click(within(pay).getByRole('button', { name: 'Complete sale' }))

    const approval = await screen.findByRole('dialog', { name: 'Supervisor approval' })
    // The only supervisor who can approve is pre-selected.
    await user.type(within(approval).getByLabelText(/PIN/), '4321')
    await user.click(within(approval).getByRole('button', { name: 'Approve' }))

    await screen.findByRole('dialog', { name: 'Sale complete' })
    expect(override).toEqual({ supervisor_id: 30, pin: '4321', permission: 'pos.discount_transaction' })
    expect(checkout).toMatchObject({ discount: { type: 'percent', value: '10' }, overrides: { 'pos.discount_transaction': 'tok-1' } })
  }, 60000)

  it('keeps the cart across a page reload, without amounts from the browser', async () => {
    backend = serve()
    const user = userEvent.setup()
    const first = renderRoutes(routes, '/pos')
    await user.click(await screen.findByRole('button', { name: /^Coke Mismo.*left$/ }, { timeout: 15000 }))
    await waitFor(() => expect(window.localStorage.getItem('avantpos.pos.cart.5')).toContain('"product_variant_id":101'))
    first.unmount()

    renderRoutes(routes, '/pos')
    expect(await screen.findByText('₱20.00', { selector: '[data-testid=cart-total]' }, { timeout: 15000 })).toBeInTheDocument()
  }, 60000)
})
