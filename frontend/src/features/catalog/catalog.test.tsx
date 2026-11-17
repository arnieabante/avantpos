import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import { toPayload } from './api'
import { BarcodeInput } from './components/BarcodeInput'
import { emptyProduct, emptyVariant, fromProduct, trimDecimal } from './productForm'
import type { Product } from './types'

describe('product form helpers', () => {
  it('sends blanks as null and leaves out costs for users who may not see them', () => {
    const input = { ...emptyProduct(1), name: 'Coke', variants: [{ ...emptyVariant(), selling_price: '20', cost_price: '15' }] }

    const withCost = toPayload(input, true)
    expect(withCost.category_id).toBeNull()
    expect(withCost.description).toBeNull()
    expect(withCost.variants[0]).toMatchObject({ sku: null, name: null, wholesale_price: null, cost_price: '15', selling_price: '20' })

    const withoutCost = toPayload(input, false)
    expect(withoutCost.variants[0]).not.toHaveProperty('cost_price')
  })

  it('round-trips an API product into form state', () => {
    const product: Product = {
      id: 1, name: 'Coke', description: null, type: 'standard', tax_class: 'vatable', sc_pwd_eligible: true, track_inventory: true,
      track_expiry: false, allow_negative_stock: false, is_active: true, meta: {}, category: null, brand: null,
      base_unit: { id: 1, name: 'Piece', abbreviation: 'pc', allows_decimal: false }, image_url: null, created_at: null, updated_at: null,
      variants: [{
        id: 9, name: null, sku: 'COKE', attributes: null, selling_price: '20.00', wholesale_price: null, reorder_level: '24.000',
        is_default: true, is_active: true, barcodes: ['480'], branch_prices: [],
        units: [{ id: 3, unit_id: 2, unit: { name: 'Box', abbreviation: 'box' }, conversion_factor: '12.0000', selling_price: '228.00', wholesale_price: null, is_sellable: true, is_purchasable: true, barcodes: [] }],
      }],
    }

    const form = fromProduct(product)
    expect(form.variants[0]).toMatchObject({ id: 9, name: '', selling_price: '20.00', reorder_level: '24', barcodes: ['480'] })
    expect(form.variants[0]).not.toHaveProperty('cost_price')
    expect(form.variants[0]!.units[0]).toMatchObject({ unit_id: 2, conversion_factor: '12', selling_price: '228.00' })
    expect(trimDecimal('12.5000')).toBe('12.5')
    expect(trimDecimal('100')).toBe('100')
  })
})

describe('BarcodeInput', () => {
  it('adds scanned codes on Enter without submitting the form, ignores duplicates and removes chips', async () => {
    const submit = vi.fn((e: { preventDefault: () => void }) => e.preventDefault())
    function Harness() {
      const [codes, setCodes] = useState<string[]>([])
      return (
        <form onSubmit={submit}>
          <BarcodeInput value={codes} onChange={setCodes} />
          <output data-testid="codes">{codes.join(',')}</output>
        </form>
      )
    }

    const user = userEvent.setup()
    render(<Harness />)
    const field = screen.getByLabelText('Barcodes')

    await user.type(field, '4801981118502{Enter}')
    await user.type(field, '4801981118502{Enter}')
    expect(screen.getByText('4801981118502 is already listed.')).toBeInTheDocument()
    await user.type(field, 'BOX-12{Enter}')

    expect(submit).not.toHaveBeenCalled()
    expect(screen.getByTestId('codes')).toHaveTextContent('4801981118502,BOX-12')

    await user.click(screen.getByRole('button', { name: 'Remove barcode BOX-12' }))
    expect(screen.getByTestId('codes')).toHaveTextContent(/^4801981118502$/)
  })
})

describe('product form page', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })

  afterEach(() => backend?.restore())

  it('creates a product and opens it', async () => {
    let created: Record<string, unknown> | null = null
    const account = makeAccount({ permissions: ['products.view', 'products.manage', 'prices.manage'] })
    backend = fakeBackend((req: FakeRequest) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
      if (req.url === '/api/v1/units') return { status: 200, data: { data: [{ id: 1, name: 'Piece', abbreviation: 'pc', allows_decimal: false }] } }
      if (req.url === '/api/v1/product-categories' || req.url === '/api/v1/brands') return { status: 200, data: { data: [] } }
      if (req.url === '/api/v1/products' && req.method === 'POST') {
        created = req.data as Record<string, unknown>
        return { status: 201, data: { data: { id: 55 } } }
      }
      if (req.url === '/api/v1/products/55') return { status: 404, data: { message: 'Resource not found.', code: 'NOT_FOUND' } }
      return undefined
    })

    const user = userEvent.setup()
    const { router } = renderRoutes(routes, '/products/new')

    await user.type(await screen.findByLabelText(/Product name/), 'Coke Mismo 290ml')
    await user.type(screen.getByLabelText(/Selling price/), '20')
    // A scan in the barcode field must not submit the form.
    await user.type(screen.getByLabelText('Barcodes'), '4801981118502{Enter}')
    expect(created).toBeNull()

    await user.click(screen.getByRole('button', { name: 'Create product' }))

    await waitFor(() => expect(created).not.toBeNull())
    expect(created).toMatchObject({ name: 'Coke Mismo 290ml', base_unit_id: 1, type: 'standard', tax_class: 'vatable' })
    expect((created!.variants as Array<Record<string, unknown>>)[0]).toMatchObject({ selling_price: '20', barcodes: ['4801981118502'], sku: null })
    // No products.view_cost: the cost field is not shown and not sent.
    expect((created!.variants as Array<Record<string, unknown>>)[0]).not.toHaveProperty('cost_price')
    await waitFor(() => expect(router.state.location.pathname).toBe('/products/55'))
  })
})
