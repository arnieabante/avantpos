import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import type { Branch } from './types'

const OWNER_PERMISSIONS = [
  'business.view', 'business.manage', 'branches.view', 'branches.manage', 'terminals.manage',
  'settings.tax', 'settings.payment_methods', 'settings.discounts', 'settings.receipt',
]

const mainBranch: Branch = {
  id: 11, code: 'MAIN', name: 'Main Branch', is_main: true, is_active: true, phone: null, email: null,
  bir_branch_code: null, operating_hours: null, terminals_count: 1, users_count: 3,
  address: { address_line: null, region_code: null, region_name: null, province_code: null, province_name: null, city_code: null, city_name: null, barangay_code: null, barangay_name: null, postal_code: null, formatted: null },
}

type Handler = (req: FakeRequest) => { status: number; data?: unknown } | undefined

function backendFor(permissions: string[], extra: Handler = () => undefined) {
  const account = makeAccount({ permissions })
  return fakeBackend((req) => {
    if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: account } }
    return extra(req)
  })
}

describe('settings', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })

  afterEach(() => backend?.restore())

  it('shows only the settings pages the role may open and redirects /settings to the first one', async () => {
    // Inventory manager: business.view + branches.view only.
    backend = backendFor(['business.view', 'branches.view'], (req) =>
      req.url === '/api/v1/business' ? { status: 403, data: { message: 'x', code: 'FORBIDDEN' } } : undefined,
    )
    const { router } = renderRoutes(routes, '/settings')

    // The Settings group in the sidebar is expanded while a settings page is open.
    const nav = await screen.findByRole('list', { name: 'Settings pages' }, { timeout: 15000 })
    await waitFor(() => expect(router.state.location.pathname).toBe('/settings/business'), { timeout: 15000 })

    const labels = within(nav).getAllByRole('link').map((l) => l.textContent)
    expect(labels).toEqual(['Business profile', 'Branches', 'Taxes', 'Payment methods', 'Discounts', 'Loyalty points', 'Receipts'])
    expect(labels).not.toContain('POS terminals')
  })

  it('blocks a settings page the role cannot open', async () => {
    backend = backendFor(['business.view'])
    renderRoutes(routes, '/settings/terminals')

    expect(await screen.findByText("You don't have access to this page")).toBeInTheDocument()
  })

  it('creates a branch: uppercases the code and sends empty optional fields as null', async () => {
    let created: unknown = null
    backend = backendFor(OWNER_PERMISSIONS, (req) => {
      if (req.url === '/api/v1/branches' && req.method === 'GET') return { status: 200, data: { data: [mainBranch] } }
      if (req.url === '/api/v1/branches' && req.method === 'POST') {
        created = req.data
        return { status: 201, data: { data: { ...mainBranch, id: 12, code: 'QC-01', name: 'Quezon City', is_main: false } } }
      }
      if (req.url === '/api/v1/ph/regions') return { status: 200, data: { data: [] } }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/settings/branches')

    await user.click(await screen.findByRole('button', { name: 'Add branch' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add branch' })
    await user.type(within(dialog).getByLabelText(/^Code/), 'qc-01')
    await user.type(within(dialog).getByLabelText(/^Branch name/), 'Quezon City')
    // Close on Mondays (first row).
    await user.click(within(dialog).getAllByLabelText('Closed')[0]!)
    await user.click(within(dialog).getByRole('button', { name: 'Create branch' }))

    await waitFor(() => expect(created).not.toBeNull())
    expect(created).toMatchObject({ code: 'QC-01', name: 'Quezon City', phone: null, email: null, region_code: null, bir_branch_code: null })
    expect((created as { operating_hours: Record<string, unknown> }).operating_hours.mon).toEqual({ closed: true })
    expect(await screen.findByText('Branch created')).toBeInTheDocument()
  })

  it('loads cities without a province for NCR', async () => {
    backend = backendFor(OWNER_PERMISSIONS, (req) => {
      if (req.url === '/api/v1/branches') return { status: 200, data: { data: [mainBranch] } }
      if (req.url === '/api/v1/ph/regions') return { status: 200, data: { data: [{ code: '1300', name: 'National Capital Region', region_name: 'NCR' }] } }
      if (req.url === '/api/v1/ph/provinces') return { status: 200, data: { data: [] } }
      if (req.url === '/api/v1/ph/cities') return { status: 200, data: { data: [{ code: '1339', name: 'City of Manila', type: 'city' }] } }
      if (req.url === '/api/v1/ph/barangays') return { status: 200, data: { data: [{ code: '1339001', name: 'Barangay 1' }] } }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/settings/branches')

    await user.click(await screen.findByRole('button', { name: 'Add branch' }))
    const dialog = await screen.findByRole('dialog')
    const region = within(dialog).getByLabelText('Region')
    await waitFor(() => expect(within(region).getByRole('option', { name: /NCR/ })).toBeInTheDocument())
    await user.selectOptions(region, '1300')

    const province = within(dialog).getByLabelText('Province')
    await waitFor(() => expect(within(province).getByRole('option', { name: 'None (NCR / no province)' })).toBeInTheDocument())
    await user.selectOptions(province, '__none__')

    const city = within(dialog).getByLabelText('City / municipality')
    await waitFor(() => expect(within(city).getByRole('option', { name: 'City of Manila' })).toBeInTheDocument())

    const citiesRequest = backend.requests.find((r) => r.url === '/api/v1/ph/cities')
    expect(citiesRequest).toBeDefined()
  })

  it('locks the rate of statutory discounts in the form', async () => {
    backend = backendFor(OWNER_PERMISSIONS, (req) =>
      req.url === '/api/v1/discount-presets'
        ? {
            status: 200,
            data: { data: [{ id: 1, name: 'Senior Citizen (20%)', type: 'percent', value: '20.0000', scope: 'line', statutory: 'senior', requires_permission: false, max_amount: null, is_active: true }] },
          }
        : undefined,
    )

    const user = userEvent.setup()
    renderRoutes(routes, '/settings/discounts')

    expect(await screen.findByText('20%')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByLabelText(/^Value/)).toBeDisabled()
    expect(within(dialog).getByLabelText(/^Name/)).toBeEnabled()
    expect(within(dialog).getByLabelText(/Requires manager approval/)).toBeEnabled()
  })
})
