import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { routes } from '@/app/router'
import { setActiveBranchId } from '@/lib/api/client'
import { clearCookies, fakeBackend, makeAccount, renderRoutes, type FakeRequest } from '@/test/utils'
import type { PermissionGroup, Role, TeamUser } from './types'

type Handler = (req: FakeRequest) => { status: number; data?: unknown } | undefined

function backendFor(permissions: string[], extra: Handler) {
  const account = makeAccount({ permissions })
  return fakeBackend((req) => (req.url === '/api/v1/auth/me' ? { status: 200, data: { data: account } } : extra(req)))
}

const baseUser: TeamUser = {
  id: 0, name: '', email: '', phone: null, status: 'active', email_verified: true, has_pin: true, is_owner: false,
  role: { id: 3, key: 'cashier', name: 'Cashier' }, all_branches: false, branches: [{ id: 11, code: 'MAIN', name: 'Main Branch' }],
  last_login_at: null, created_at: null,
}

const team: TeamUser[] = [
  { ...baseUser, id: 5, name: 'Arnel Santos', email: 'owner@motoshop.test', is_owner: true, role: { id: 1, key: 'owner', name: 'Owner' }, all_branches: true },
  { ...baseUser, id: 20, name: 'Maria Cruz', email: 'maria@motoshop.test' },
]

const page = (items: unknown[]) => ({ status: 200, data: { data: items, meta: { pagination: { current_page: 1, per_page: 25, total: items.length, last_page: 1 } } } })

describe('users & roles', () => {
  let backend: ReturnType<typeof fakeBackend> | undefined

  beforeEach(() => {
    clearCookies()
    document.cookie = 'XSRF-TOKEN=t; path=/'
    setActiveBranchId(null)
    window.localStorage.clear()
  })

  afterEach(() => backend?.restore())

  it('lists the team; the owner row has no actions; deactivating asks for confirmation', async () => {
    let deactivated = false
    backend = backendFor(['users.view', 'users.manage', 'users.invite'], (req) => {
      if (req.url === '/api/v1/users') return page(team)
      if (req.url === '/api/v1/roles/assignable') return { status: 200, data: { data: [] } }
      if (req.url === '/api/v1/users/20/deactivate') {
        deactivated = true
        return { status: 200, data: { data: { ...team[1], status: 'inactive' } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/users')

    expect(await screen.findByText('Maria Cruz', {}, { timeout: 15000 })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Actions for Arnel Santos' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Actions for Maria Cruz' }))
    await user.click(screen.getByRole('menuitem', { name: 'Deactivate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Deactivate Maria Cruz?' })
    expect(deactivated).toBe(false)

    await user.click(within(dialog).getByRole('button', { name: 'Deactivate' }))
    await waitFor(() => expect(deactivated).toBe(true))
    expect(await screen.findByText('Maria Cruz deactivated')).toBeInTheDocument()
  })

  it('sends someone who may only invite to the Invitations tab', async () => {
    backend = backendFor(['users.invite'], (req) => (req.url === '/api/v1/invitations' ? page([]) : undefined))
    const { router } = renderRoutes(routes, '/users')

    await waitFor(() => expect(router.state.location.pathname).toBe('/users/invitations'), { timeout: 15000 })
    expect(await screen.findByText('No invitations yet')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Roles' })).not.toBeInTheDocument()
  })

  it('invites with role and branch, pre-selecting the only branch', async () => {
    let sent: unknown = null
    const singleBranch = makeAccount({ permissions: ['users.invite'], branches: [{ id: 11, code: 'MAIN', name: 'Main Branch', is_main: true }] })
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: singleBranch } }
      if (req.url === '/api/v1/invitations' && req.method === 'GET') return page([])
      if (req.url === '/api/v1/roles/assignable') return { status: 200, data: { data: [{ id: 3, key: 'cashier', name: 'Cashier', description: null, is_system: true, is_locked: false }] } }
      if (req.url === '/api/v1/invitations' && req.method === 'POST') {
        sent = req.data
        return { status: 201, data: { data: {} } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/users/invitations')

    await user.click(await screen.findByRole('button', { name: 'Invite' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText(/E-mail address/), 'juan@example.com')
    const role = within(dialog).getByLabelText(/Role/)
    await waitFor(() => expect(within(role).getByRole('option', { name: 'Cashier' })).toBeInTheDocument())
    await user.selectOptions(role, '3')
    expect(within(dialog).getByLabelText(/Main Branch/)).toBeChecked()
    await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }))

    await waitFor(() => expect(sent).toEqual({ email: 'juan@example.com', name: null, role_id: 3, branch_ids: [11] }))
  })

  it('lets an owner or administrator add a user directly with a starting password', async () => {
    let sent: unknown = null
    const admin = makeAccount({ permissions: ['users.view', 'users.invite', 'users.manage'], branches: [{ id: 11, code: 'MAIN', name: 'Main Branch', is_main: true }] })
    backend = fakeBackend((req) => {
      if (req.url === '/api/v1/auth/me') return { status: 200, data: { data: admin } }
      if (req.url === '/api/v1/users' && req.method === 'GET') return page(team)
      if (req.url === '/api/v1/roles/assignable') return { status: 200, data: { data: [{ id: 3, key: 'cashier', name: 'Cashier', description: null, is_system: true, is_locked: false }] } }
      if (req.url === '/api/v1/users' && req.method === 'POST') {
        sent = req.data
        return { status: 201, data: { data: { ...baseUser, id: 30, name: 'Ana Cruz', email: 'ana@example.com' } } }
      }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/users')

    await user.click(await screen.findByRole('button', { name: 'Add user' }, { timeout: 15000 }))
    const dialog = await screen.findByRole('dialog', { name: 'Add a user' })
    await user.type(within(dialog).getByLabelText(/^Name/), 'Ana Cruz')
    await user.type(within(dialog).getByLabelText(/E-mail address/), 'ana@example.com')
    await user.type(within(dialog).getByLabelText(/Starting password/), 'Secret-pass1')
    await user.type(within(dialog).getByLabelText(/Confirm password/), 'Secret-pass2')
    const role = within(dialog).getByLabelText(/Role/)
    await waitFor(() => expect(within(role).getByRole('option', { name: 'Cashier' })).toBeInTheDocument())
    await user.selectOptions(role, '3')
    await user.click(within(dialog).getByRole('button', { name: 'Add user' }))
    expect(await within(dialog).findByText('The passwords do not match.')).toBeInTheDocument()
    expect(sent).toBeNull()

    await user.clear(within(dialog).getByLabelText(/Confirm password/))
    await user.type(within(dialog).getByLabelText(/Confirm password/), 'Secret-pass1')
    await user.click(within(dialog).getByRole('button', { name: 'Add user' }))
    await waitFor(() => expect(sent).toEqual({ name: 'Ana Cruz', email: 'ana@example.com', phone: null, role_id: 3, branch_ids: [11], password: 'Secret-pass1', password_confirmation: 'Secret-pass1' }))
  }, 60000)

  it('role editor: permissions you do not hold are locked; saving sends the selection', async () => {
    let saved: unknown = null
    const groups: PermissionGroup[] = [
      { key: 'pos', label: 'Point of sale', permissions: [{ name: 'pos.sell', label: 'Use the POS' }, { name: 'pos.hold', label: 'Hold and resume transactions' }] },
      { key: 'billing', label: 'Billing', permissions: [{ name: 'billing.manage', label: 'Change plan and pay invoices' }] },
    ]
    const role: Role = { id: 3, key: 'cashier', name: 'Cashier', description: null, is_system: true, is_locked: false, permissions: ['pos.sell'], users_count: 2 }

    backend = backendFor(['roles.manage', 'pos.sell', 'pos.hold'], (req) => {
      if (req.url === '/api/v1/permissions') return { status: 200, data: { data: groups } }
      if (req.url === '/api/v1/roles/3' && req.method === 'GET') return { status: 200, data: { data: role } }
      if (req.url === '/api/v1/roles/3' && req.method === 'PUT') {
        saved = req.data
        return { status: 200, data: { data: role } }
      }
      if (req.url === '/api/v1/roles') return { status: 200, data: { data: [role] } }
      return undefined
    })

    const user = userEvent.setup()
    renderRoutes(routes, '/users/roles/3')

    const billing = await screen.findByRole('checkbox', { name: /Change plan and pay invoices/ })
    expect(billing).toBeDisabled()
    expect(screen.getByRole('checkbox', { name: 'Use the POS' })).toBeChecked()

    await user.click(screen.getByRole('checkbox', { name: 'Hold and resume transactions' }))
    await user.click(screen.getByRole('button', { name: 'Save role' }))

    await waitFor(() => expect(saved).toEqual({ name: 'Cashier', description: null, permissions: ['pos.hold', 'pos.sell'] }))
  })
})
