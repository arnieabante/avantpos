import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MoreHorizontal, Send, UserPlus, Users } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Badge,
  Button,
  Card,
  DataTable,
  Dropdown,
  EmptyState,
  FilterBar,
  Pagination,
  SearchInput,
  Select,
  useConfirm,
  type Column,
  type DropdownItem,
} from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { useAction } from '@/hooks/useAction'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, initials } from '@/lib/format'
import { usersApi, usersKeys, type UserFilters } from '../api'
import { AddUserModal, EditUserModal, InviteModal } from '../components/UserModals'
import type { TeamUser } from '../types'

export default function TeamPage() {
  const account = useAccount()
  const { can } = useAuth()
  const canManage = can('users.manage')
  const canInvite = can('users.invite')
  const confirm = useConfirm()
  const { run } = useAction()

  const [filters, setFilters] = useState<UserFilters>({ page: 1 })
  const [editing, setEditing] = useState<TeamUser | null>(null)
  const [inviting, setInviting] = useState(false)
  const [adding, setAdding] = useState(false)
  const setFilter = (patch: Partial<UserFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))

  const users = useQuery({ queryKey: usersKeys.list(filters), queryFn: () => usersApi.list(filters), placeholderData: (prev) => prev })
  const roles = useQuery({ queryKey: usersKeys.assignableRoles, queryFn: usersApi.assignableRoles, enabled: canManage || canInvite })
  const refresh = { invalidate: [['users'], usersKeys.roles] }

  async function act(user: TeamUser, kind: 'deactivate' | 'activate' | 'pin' | 'reset') {
    const dialogs = {
      deactivate: { title: `Deactivate ${user.name}?`, description: 'They are signed out on all devices immediately and can no longer sign in. Their sales history is kept.', confirmLabel: 'Deactivate', danger: true },
      pin: { title: `Clear ${user.name}'s POS PIN?`, description: 'They will need to set a new PIN under My account before using the POS.', confirmLabel: 'Clear PIN', danger: true },
      reset: { title: `Send a password reset link to ${user.email}?`, confirmLabel: 'Send link' },
    }
    if (kind !== 'activate' && !(await confirm(dialogs[kind]))) return

    const actions = {
      deactivate: () => usersApi.action(user.id, 'deactivate'),
      activate: () => usersApi.action(user.id, 'activate'),
      pin: () => usersApi.clearPin(user.id),
      reset: () => usersApi.sendPasswordReset(user.id),
    }
    const messages = { deactivate: `${user.name} deactivated`, activate: `${user.name} activated`, pin: 'PIN cleared', reset: 'Password reset link sent' }
    await run(`${kind}-${user.id}`, actions[kind], { ...refresh, success: messages[kind] })
  }

  const columns: Column<TeamUser>[] = [
    {
      key: 'user',
      header: 'Name',
      render: (u) => (
        <div className="flex items-center gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-500" aria-hidden="true">
            {initials(u.name)}
          </span>
          <div className="min-w-0">
            <p className="truncate font-medium text-ink">
              {u.name}
              {u.id === account.user.id && <span className="ml-1.5 text-xs font-normal text-ink-subtle">(you)</span>}
            </p>
            <p className="truncate text-xs text-ink-subtle">{u.email}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      render: (u) => (
        <div className="flex flex-wrap items-center gap-1">
          <span className="text-ink">{u.role?.name ?? '—'}</span>
          {u.is_owner && <Badge tone="brand">Owner</Badge>}
        </div>
      ),
    },
    {
      key: 'branches',
      header: 'Branches',
      className: 'hidden md:table-cell',
      render: (u) => (u.all_branches ? <span className="text-ink-muted">All branches</span> : u.branches.map((b) => b.name).join(', ') || '—'),
    },
    { key: 'login', header: 'Last sign-in', className: 'hidden lg:table-cell', render: (u) => formatDateTime(u.last_login_at, account.tenant.timezone) },
    {
      key: 'status',
      header: 'Status',
      render: (u) => (
        <div className="flex flex-wrap gap-1">
          <Badge tone={u.status === 'active' ? 'success' : 'neutral'}>{u.status === 'active' ? 'Active' : 'Inactive'}</Badge>
          {!u.email_verified && <Badge tone="warning">Unverified</Badge>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (u) => {
        if (!canManage) return null
        const isSelf = u.id === account.user.id
        if (u.is_owner && !isSelf) return null // only the owner manages the owner account
        const items: DropdownItem[] = [{ key: 'edit', label: 'Edit', onSelect: () => setEditing(u) }]
        if (!isSelf) {
          if (u.status === 'active') {
            items.push({ key: 'reset', label: 'Send password reset', onSelect: () => act(u, 'reset') })
            if (u.has_pin) items.push({ key: 'pin', label: 'Clear POS PIN', onSelect: () => act(u, 'pin') })
            items.push({ key: 'deactivate', label: 'Deactivate', danger: true, onSelect: () => act(u, 'deactivate') })
          } else {
            items.push({ key: 'activate', label: 'Activate', onSelect: () => act(u, 'activate') })
          }
        }
        return (
          <Dropdown
            items={items}
            trigger={(props) => (
              <button type="button" {...props} className="rounded-md p-2 text-ink-subtle hover:bg-brand-500/10 hover:text-brand-500" aria-label={`Actions for ${u.name}`}>
                <MoreHorizontal className="size-4" />
              </button>
            )}
          />
        )
      },
    },
  ]

  return (
    <>
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Search name or e-mail…" />
          <Select
            aria-label="Role"
            className="sm:w-48"
            value={filters.role_id ?? ''}
            onChange={(e) => setFilter({ role_id: e.target.value ? Number(e.target.value) : undefined })}
          >
            <option value="">All roles</option>
            {roles.data?.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </Select>
          <Select
            aria-label="Status"
            className="sm:w-40"
            value={filters.status ?? ''}
            onChange={(e) => setFilter({ status: (e.target.value || undefined) as UserFilters['status'] })}
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
          {(canInvite || canManage) && (
            <div className="flex gap-2 sm:ml-auto">
              {canInvite && (
                <Button variant={canManage ? 'outline' : 'primary'} leftIcon={<Send className="size-4" />} onClick={() => setInviting(true)}>
                  Invite
                </Button>
              )}
              {canManage && (
                <Button leftIcon={<UserPlus className="size-4" />} onClick={() => setAdding(true)}>
                  Add user
                </Button>
              )}
            </div>
          )}
        </FilterBar>

        {users.isError ? (
          <ApiErrorAlert error={ApiError.from(users.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={users.data?.items}
              rowKey={(u) => u.id}
              loading={users.isFetching}
              caption="Team members"
              empty={<EmptyState icon={<Users className="size-5" />} title="No users found" description="Try another search or filter." />}
            />
            <Pagination meta={users.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={users.isFetching} className="border-t border-line" />
          </>
        )}
      </Card>

      {editing && <EditUserModal user={editing} isSelf={editing.id === account.user.id} onClose={() => setEditing(null)} />}
      {inviting && <InviteModal onClose={() => setInviting(false)} />}
      {adding && <AddUserModal onClose={() => setAdding(false)} />}
    </>
  )
}
