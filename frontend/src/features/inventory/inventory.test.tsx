import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { navigation, visibleNavigation } from '@/components/layout/navigation'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import { formatQty, formatSignedQty, lineDirection, qtyError } from './format'
import type { StockVariant } from './types'

const variant: StockVariant = {
  id: 9, product_id: 4, name: 'Coke Mismo 290ml', product_name: 'Coke Mismo 290ml', variant_name: null, sku: 'COK-1',
  unit: 'pc', allows_decimal: false, track_expiry: false, is_active: true,
}

describe('inventory helpers', () => {
  it('formats quantities from decimal strings without float maths', () => {
    expect(formatQty('1250.500', 'kg')).toBe('1,250.5 kg')
    expect(formatQty('24.000')).toBe('24')
    expect(formatSignedQty('-3.000', 'pc')).toBe('−3 pc')
    expect(formatSignedQty('5.000', 'pc')).toBe('+5 pc')
  })

  it('validates typed quantities and works out the direction of adjustment lines', () => {
    expect(qtyError('1.5', false)).toBe('Whole units only.')
    expect(qtyError('1.5', true)).toBeUndefined()
    expect(qtyError('-2', false)).toMatch(/Enter a number/)
    expect(qtyError('-2', false, { allowNegative: true })).toBeUndefined()
    expect(qtyError('0', true)).toBe('Enter a quantity other than zero.')

    expect(lineDirection('damaged', '3')).toBe('out')
    expect(lineDirection('opening', '-3')).toBe('in')
    expect(lineDirection('correction', '-3')).toBe('out')
    expect(lineDirection('correction', '3')).toBe('in')
    expect(lineDirection('correction', '')).toBeNull()
  })

  it('shows Transfers only on plans with stock transfers', () => {
    const can = () => true
    const children = (features: Record<string, boolean>) =>
      visibleNavigation(navigation, can, features).flatMap((s) => s.items).find((i) => i.label === 'Inventory')?.children?.map((c) => c.label)

    expect(children({ inventory: true, stock_transfers: true })).toContain('Transfers')
    expect(children({ inventory: true })).not.toContain('Transfers')
    expect(children({})).toBeUndefined()
  })
})

describe('adjustment page', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })
  afterEach(() => backend?.restore())

  it('adds a scanned item and posts a damage write-off after confirmation', async () => {
    let posted: Record<string, unknown> | null = null
    const account = makeAccount({
      permissions: ['inventory.view', 'inventory.adjust'],
      subscription: { status: 'active', billing_cycle: 'monthly', trial_ends_at: null, current_period_end: null, plan: { code: 'business', name: 'Business', limits: null, features: { inventory: true } } },
    })
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/inventory/picker') return { status: 200, data: { data: [{ variant, qty_on_hand: '24.000' }] } }
      if (req.url === '/api/v1/stock-adjustments' && req.method === 'POST') {
        posted = req.data as Record<string, unknown>
        return { status: 201, data: { data: { id: 31, number: 'ADJ-000031', status: 'posted' } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    const { router } = renderRoutes(routes, '/inventory/adjustments/new')

    await user.selectOptions(await screen.findByLabelText(/Reason/, {}, { timeout: 15000 }), 'damaged')
    // A scanner types the code and presses Enter: the item is added, the form is not submitted.
    await user.type(screen.getByRole('combobox', { name: 'Add item' }), '4801981118502{Enter}')
    const qty = await screen.findByLabelText('Quantity of Coke Mismo 290ml')
    expect(screen.getByText('24 pc')).toBeInTheDocument()
    expect(posted).toBeNull()

    await user.type(qty, '1.5')
    await user.click(screen.getByRole('button', { name: 'Post adjustment' }))
    expect(await screen.findByText('Whole units only.')).toBeInTheDocument()

    await user.clear(qty)
    await user.type(qty, '2')
    expect(screen.getByText('Removes stock')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Post adjustment' }))
    const dialog = await screen.findByRole('dialog', { name: 'Post this adjustment?' })
    expect(posted).toBeNull()
    await user.click(within(dialog).getByRole('button', { name: 'Post adjustment' }))

    await waitFor(() => expect(posted).not.toBeNull())
    // No inventory.valuation: no cost is sent.
    expect(posted).toEqual({ reason_code: 'damaged', notes: null, post: true, items: [{ product_variant_id: 9, qty: '2' }] })
    await waitFor(() => expect(router.state.location.pathname).toBe('/inventory/adjustments/31'))
  })
})
