import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import type { CashSession, PosTerminal } from '@/features/cash/types'
import { setActiveBranchId } from '@/lib/api/client'
import { localStore } from '@/lib/localStore'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import { addItem, emptyCart, setScPwd, updateLine } from '../cart'
import type { PaymentMethodOption, PosItem, PosSetup } from '../types'
import { offlineBlockers, offlineQuote } from './pricing'
import { buildOfflineSale } from './sale'
import { invoiceCounter, offlineQueue, scanCatalog, syncQueue, type OfflineItem, type QueuedSale } from './store'

const unit = { id: null, name: 'Piece', abbreviation: 'pc', conversion_factor: '1.0000', allows_decimal: false, price: '20.00' }
const base: Omit<OfflineItem, 'product_variant_id' | 'name' | 'sku' | 'price' | 'units' | 'tax_class'> = {
  product_id: 1, category_id: null, color: null, image_url: null, type: 'standard', sc_pwd_eligible: true, track_inventory: true,
  allow_negative_stock: false, qty_on_hand: '10.000', barcodes: [],
}
const coke: OfflineItem = { ...base, product_variant_id: 101, name: 'Coke', sku: 'COKE', tax_class: 'vatable', price: '20.00',
  units: [unit, { ...unit, id: 7, name: 'Box', abbreviation: 'box', conversion_factor: '12.0000', price: '220.00' }],
  barcodes: [{ code: '4800000000017', unit_id: null }, { code: '4800000000123', unit_id: 7 }] }
const rice: OfflineItem = { ...base, product_variant_id: 102, name: 'Rice', sku: 'RICE', tax_class: 'vat_exempt', price: '52.50',
  units: [{ ...unit, name: 'Kilogram', abbreviation: 'kg', allows_decimal: true, price: '52.50' }] }
const odd: OfflineItem = { ...base, product_variant_id: 103, name: 'Odd', sku: 'ODD', tax_class: 'vatable', price: '33.33', units: [{ ...unit, price: '33.33' }] }

const cash: PaymentMethodOption = { id: 1, type: 'cash', name: 'Cash', requires_reference: false }
const gcash: PaymentMethodOption = { id: 2, type: 'gcash', name: 'GCash', requires_reference: true }

describe('offline pricing (same rounding as the server)', () => {
  it('extracts VAT per line and keeps VAT-exempt sales apart', () => {
    let cart = addItem(addItem(addItem(addItem(emptyCart(), coke), coke), coke), rice, null, '1.5')
    cart = addItem(cart, odd)
    const q = offlineQuote(cart, '12.0000')
    // Coke 60.00 → net 53.57 + VAT 6.43; Odd 33.33 → net 29.76 + VAT 3.57; Rice 1.5 × 52.50 = 78.75 exempt.
    expect(q.lines.map((l) => [l.line_total, l.vat_amount, l.tax_class])).toEqual([
      ['60.00', '6.43', 'vatable'],
      ['78.75', '0.00', 'vat_exempt'],
      ['33.33', '3.57', 'vatable'],
    ])
    expect(q.totals).toMatchObject({ total: '172.08', vatable_sales: '83.33', vat_amount: '10.00', vat_exempt_sales: '78.75' })

    // A non-VAT business records non-VAT sales.
    expect(offlineQuote(addItem(emptyCart(), coke), null).lines[0]).toMatchObject({ tax_class: 'non_vat', vat_amount: '0.00' })
  })

  it('lists what cannot be done offline', () => {
    let cart = addItem(emptyCart(), coke)
    expect(offlineBlockers(cart)).toEqual([])
    cart = updateLine(cart, cart.lines[0]!.key, { priceOverride: '18.00' })
    cart = setScPwd(cart, { type: 'senior', name: 'Lola', id_no: '123', tin: '' })
    expect(offlineBlockers(cart)).toEqual(['the Senior/PWD discount', 'price changes'])
  })

  it('finds items by barcode (box barcode = box unit) and SKU', () => {
    expect(scanCatalog([coke, rice], '4800000000123')).toMatchObject({ product_variant_id: 101, scanned_unit_id: 7 })
    expect(scanCatalog([coke, rice], 'rice')).toMatchObject({ product_variant_id: 102, scanned_unit_id: null })
    expect(scanCatalog([coke, rice], '999')).toBeNull()
  })
})

describe('offline sales queue', () => {
  beforeEach(() => {
    localStore.resetMemory()
    document.cookie = 'XSRF-TOKEN=t; path=/'
  })

  it('continues the terminal invoice series and never goes back', async () => {
    expect(await invoiceCounter.peek(2, '000000005')).toBe('000000005')
    await invoiceCounter.use(2, '000000005')
    await invoiceCounter.use(2, '000000006')
    expect(await invoiceCounter.peek(2, '000000005')).toBe('000000007')
    // The server is ahead (e.g. sales from before this device): its number wins.
    expect(await invoiceCounter.peek(2, '000000010')).toBe('000000010')
  })

  it('builds the queued sale and the receipt: change from the first cash payment', () => {
    const cart = addItem(addItem(emptyCart(), coke), coke)
    const sale = buildOfflineSale({
      clientUuid: 'u-1', invoiceNo: '000000007', sessionId: 5, cart, quote: offlineQuote(cart, '12.0000'),
      tenders: [{ key: 'a', method: gcash, amount: '15', reference: 'GC-1' }, { key: 'b', method: cash, amount: '50', reference: '' }],
      setup: { branch: { id: 11, name: 'Main' }, terminal: null } as unknown as PosSetup,
      cashier: { id: 7, name: 'Arnel' }, pricingVersion: 'v1', now: new Date('2026-11-15T01:00:00Z'),
    })
    expect(sale).toMatchObject({
      invoice_no: '000000007', total: '40.00', sold_at: '2026-11-15T01:00:00.000Z', cash_register_session_id: 5,
      items: [{ product_variant_id: 101, product_unit_id: null, qty: '2', unit_price: '20.00' }],
      payments: [{ payment_method_id: 2, amount: '15', reference_no: 'GC-1' }, { payment_method_id: 1, amount: '50', reference_no: null }],
    })
    expect(sale.receipt.totals).toMatchObject({ total: '40.00', vat_amount: '4.29', change: '25.00', amount_paid: '65.00' })
    expect(sale.receipt.payments[1]).toMatchObject({ amount: '25.00', tendered: '50.00', change: '25.00' })
  })

  it('uploads in order; refused sales stay on the device with the reason', async () => {
    const make = (n: string): QueuedSale => ({ client_uuid: `u-${n}`, invoice_no: n, sold_at: '', cash_register_session_id: 5, total: '20.00', pricing_version: null, items: [], payments: [], receipt: {} as QueuedSale['receipt'] })
    await offlineQueue.add(11, 7, make('000000001'))
    await offlineQueue.add(11, 7, make('000000002'))
    let sent: unknown = null
    const backend = fakeBackend((req) => {
      if (req.url !== '/api/v1/pos/sync') return undefined
      sent = req.data
      return { status: 200, data: { data: { next_invoice_no: '000000003', results: [
        { client_uuid: 'u-000000001', status: 'accepted', invoice_no: '000000001' },
        { client_uuid: 'u-000000002', status: 'rejected', message: 'This sale was made on a shift that is not yours in this branch.' },
      ] } } }
    })
    try {
      const { remaining, results } = await syncQueue(11, 7, 2)
      expect((sent as { sales: Array<Record<string, unknown>> }).sales[0]).not.toHaveProperty('receipt')
      expect(remaining.map((s) => [s.invoice_no, s.error])).toEqual([['000000002', 'This sale was made on a shift that is not yours in this branch.']])
      expect(results[0]).toMatchObject({ printed: '000000001' })
      expect(await invoiceCounter.peek(2, '000000001')).toBe('000000003')
    } finally {
      backend.restore()
    }
  })
})

/* ------------------------------------------------------------------ screen */

const setup: PosSetup = {
  business: { name: 'Arnel Motoshop', registered_name: null, business_style: null, tin: '123456789', vat_registered: true, address: null, phone: null, logo_url: null },
  branch: { id: 11, code: 'MAIN', name: 'Main Branch', is_main: true, tin_branch_code: '00000', address: null, phone: null },
  session: { id: 5, opened_at: '2026-11-15T08:00:00+08:00' },
  terminal: { id: 2, code: 'POS-2', name: 'Counter 2', min: '2511150001', serial_no: null, ptu_no: null, ptu_issued_at: null, ptu_valid_until: null },
  receipt: { id: null, name: 'Standard', paper: '80mm', header_text: null, footer_text: null, show_logo: false, layout: {} },
  payment_methods: [cash, gcash, { id: 9, type: 'credit', name: 'Charge to account', requires_reference: false }],
  discount_presets: [], categories: [], loyalty: null, features: { customers: true, credit_sales: true, loyalty: false },
  max_discount_percent: '20', vat_rate: '12.0000', next_invoice_no: '000000004', approvers: [],
}
const session = { id: 5, status: 'open', branch: { id: 11, name: 'Main Branch' }, terminal: { id: 2, code: 'POS-2', name: 'Counter 2' } } as CashSession
const terminals: PosTerminal[] = [{ id: 2, code: 'POS-2', name: 'Counter 2', in_use_by: { id: 7, name: 'Arnel Santos' }, drawers: [] }]

describe('selling offline', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined
  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
    localStore.resetMemory()
  })
  afterEach(() => backend?.restore())

  it('keeps selling when the server is unreachable and uploads the sales when it is back', async () => {
    const account = makeAccount({ permissions: ['pos.sell', 'cash.open_session'], subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan: { code: 'business', name: 'Business', limits: null, features: { pos: true } } } })
    let down = false
    let uploaded: Record<string, unknown> | null = null
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/pos/terminals') return { status: 200, data: { data: { terminals, my_session: session } } }
      if (req.url === '/api/v1/pos/setup') return { status: 200, data: { data: setup } }
      if (req.url === '/api/v1/pos/catalog') return down ? { status: 0 } : { status: 200, data: { data: { version: 'v1', unchanged: false, items: [coke, rice] } } }
      if (req.url === '/api/v1/pos/sync') {
        if (down) return { status: 0 }
        uploaded = req.data as Record<string, unknown>
        return { status: 200, data: { data: { next_invoice_no: '000000005', results: [{ client_uuid: (uploaded.sales as Array<{ client_uuid: string }>)[0]!.client_uuid, status: 'accepted', invoice_no: '000000004' }] } } }
      }
      if (down) return { status: 0 }
      if (req.url === '/api/v1/pos/items') return { status: 200, data: { data: [coke as PosItem] } }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/pos')
    const search = await screen.findByLabelText('Scan or search items', {}, { timeout: 15000 })
    // The catalog is saved on the device in the background.
    await waitFor(async () => expect(await localStore.get('catalog.11')).not.toBeNull())

    // The connection drops; the scan is answered from the saved catalog.
    down = true
    await user.type(search, '4800000000017{Enter}')
    expect(await screen.findByText('Offline — keep selling')).toBeInTheDocument()
    expect(await screen.findByText('₱20.00', { selector: '[data-testid=cart-total]' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Discount' })).toBeDisabled()

    await user.keyboard('{F12}')
    const pay = await screen.findByRole('dialog', { name: 'Payment' })
    expect(within(pay).queryByRole('button', { name: /Charge to account/ })).not.toBeInTheDocument()
    await user.click(within(pay).getByRole('button', { name: '₱50.00' }))
    await user.click(within(pay).getByRole('button', { name: 'Complete sale' }))

    const done = await screen.findByRole('dialog', { name: 'Sale complete' })
    expect(within(done).getByText(/Saved on this device/)).toBeInTheDocument()
    expect(within(done).getAllByTestId('receipt')[0]).toHaveTextContent('000000004') // continues the terminal series
    expect(within(done).getByTestId('change-due')).toHaveTextContent('₱30.00')
    await user.click(within(done).getByRole('button', { name: 'New sale' }))
    expect(await screen.findByText(/1 sale waiting to upload/)).toBeInTheDocument()

    // Back online: the queue is uploaded.
    down = false
    await act(async () => {
      window.dispatchEvent(new Event('online'))
    })
    expect(await screen.findByText(/1 offline sale uploaded/, {}, { timeout: 10000 })).toBeInTheDocument()
    expect(uploaded).toMatchObject({ sales: [{ invoice_no: '000000004', cash_register_session_id: 5, total: '20.00',
      items: [{ product_variant_id: 101, qty: '1', unit_price: '20.00' }], payments: [{ payment_method_id: 1, amount: '50.00' }] }] })
    expect(screen.queryByText(/waiting to upload/)).not.toBeInTheDocument()
  }, 60000)
})

describe('starting offline', () => {
  it('uses the last signed-in account when the server cannot be reached, never after a sign-out', async () => {
    const { accountCache, fetchSession } = await import('@/features/auth/session')
    const account = makeAccount()
    let down = false
    const backend = fakeBackend((req) => (req.url === '/api/v1/auth/me' ? (down ? { status: 0 } : { status: 200, data: { data: account } }) : undefined))
    try {
      window.localStorage.clear()
      expect(await fetchSession()).toMatchObject({ status: 'authenticated' })
      down = true
      expect(await fetchSession()).toMatchObject({ status: 'authenticated', account: { user: { id: account.user.id } } })
      accountCache.clear()
      await expect(fetchSession()).rejects.toMatchObject({ code: 'NETWORK_ERROR' })
    } finally {
      backend.restore()
    }
  })
})
