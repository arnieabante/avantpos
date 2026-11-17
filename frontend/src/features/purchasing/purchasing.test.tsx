import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import type { StockVariant } from '@/features/inventory/types'
import type { PurchasableItem, PurchaseOrder } from './types'

const variant: StockVariant = {
  id: 9, product_id: 4, name: 'Coke Mismo', product_name: 'Coke Mismo', variant_name: null, sku: 'COK-1',
  unit: 'pc', allows_decimal: false, track_expiry: false, is_active: true,
}
const item: PurchasableItem = {
  variant,
  units: [
    { product_unit_id: null, name: 'Piece', abbreviation: 'pc', factor: '1.0000' },
    { product_unit_id: 31, name: 'Box', abbreviation: 'box', factor: '12.0000' },
  ],
  default_unit_id: 31, // a box barcode was scanned
  last_cost: '16.6667',
  qty_on_hand: '10.000',
  reorder_level: '24.000',
  suggested_qty: null,
}

const plan = { code: 'business', name: 'Business', limits: null, features: { purchasing: true, inventory: true } }
const accountWith = (permissions: string[]) =>
  makeAccount({ permissions, subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan } })

describe('purchasing pages', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('builds an order from a scanned box barcode with an exact estimate, then saves a draft', async () => {
    let sent: Record<string, unknown> | null = null
    const account = accountWith(['po.view', 'po.create'])
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/suppliers/options') return { status: 200, data: { data: [{ id: 5, name: 'Coca-Cola PH', vat_registered: true, payment_terms_days: 30 }] } }
      if (req.url === '/api/v1/purchasing/items') return { status: 200, data: { data: [item] } }
      if (req.url === '/api/v1/purchase-orders' && req.method === 'POST') {
        sent = req.data as Record<string, unknown>
        return { status: 201, data: { data: { id: 40, number: 'PO-000000040' } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    const { router } = renderRoutes(routes, '/purchasing/orders/new')

    await screen.findByRole('option', { name: 'Coca-Cola PH' }, { timeout: 15000 })
    await user.selectOptions(screen.getByLabelText(/Supplier/), '5')
    await user.type(screen.getByRole('combobox', { name: 'Add item' }), 'BOX-COKE{Enter}')
    expect(await screen.findByLabelText('Unit of Coke Mismo')).toHaveValue('31')
    expect(screen.getByText('Last: ₱16.67/pc net')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Quantity of Coke Mismo'), '3')
    await user.type(screen.getByLabelText('Cost of Coke Mismo'), '224.10')
    expect(screen.getAllByText('₱672.30').length).toBeGreaterThan(0)

    // No po.approve: only "Save draft" is offered.
    expect(screen.queryByRole('button', { name: 'Save and approve' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Save draft' }))

    await waitFor(() => expect(sent).not.toBeNull())
    expect(sent).toMatchObject({ supplier_id: 5, approve: false, items: [{ product_variant_id: 9, product_unit_id: 31, qty: '3', unit_cost: '224.10' }] })
    expect(sent).not.toHaveProperty('total')
    await waitFor(() => expect(router.state.location.pathname).toBe('/purchasing/orders/40'))
  })

  it('receives against an order, leaving out lines that did not arrive', async () => {
    let sent: Record<string, unknown> | null = null
    const account = accountWith(['po.view', 'po.receive'])
    const order: PurchaseOrder = {
      id: 40, number: 'PO-000000040', status: 'approved', order_date: '2026-11-14', expected_date: null,
      supplier: { id: 5, name: 'Coca-Cola PH', vat_registered: true }, branch: { id: 11, name: 'Main Branch' },
      subtotal: '1000.00', tax_total: '120.00', total: '1120.00', notes: null, created_by: null, approved_by: null, approved_at: null,
      created_at: '2026-11-14T00:00:00+08:00', items_count: 2,
      can: { edit: false, approve: false, receive: true, cancel: false, close: false },
      items: [
        { id: 70, variant, unit: item.units[1]!, qty_ordered: '5.000', qty_received: '2.000', qty_remaining: '3.000', unit_cost: '224.0000', line_total: '1120.00', tax_amount: '120.00' },
        { id: 71, variant: { ...variant, id: 10, name: 'Sprite', sku: 'SPR-1' }, unit: item.units[0]!, qty_ordered: '6.000', qty_received: '0.000', qty_remaining: '6.000', unit_cost: '18.0000', line_total: '108.00', tax_amount: '11.57' },
      ],
      receipts: [],
    }
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/purchase-orders/40') return { status: 200, data: { data: order } }
      if (req.url === '/api/v1/goods-receipts' && req.method === 'POST') {
        sent = req.data as Record<string, unknown>
        return { status: 201, data: { data: { id: 90, number: 'GR-000000090' } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/purchasing/receipts/new?po=40')

    const cokeQty = await screen.findByLabelText('Received quantity of Coke Mismo', {}, { timeout: 15000 })
    expect(cokeQty).toHaveValue('3')
    await user.clear(screen.getByLabelText('Received quantity of Sprite'))
    await user.type(screen.getByLabelText('Received quantity of Sprite'), '0')
    await user.type(screen.getByLabelText('Supplier invoice / DR no.'), 'SI-1001')

    await user.click(screen.getByRole('button', { name: 'Receive stock' }))
    const dialog = await screen.findByRole('dialog', { name: 'Receive these goods?' })
    expect(within(dialog).getByText(/1 item from Coca-Cola PH, about ₱672.00/)).toBeInTheDocument()
    await user.click(within(dialog).getByRole('button', { name: 'Receive stock' }))

    await waitFor(() => expect(sent).not.toBeNull())
    expect(sent).toEqual({
      purchase_order_id: 40, supplier_id: null, supplier_invoice_no: 'SI-1001', supplier_invoice_date: null, notes: null,
      items: [{ purchase_order_item_id: 70, qty: '3', unit_cost: '224' }],
    })
  })
})
