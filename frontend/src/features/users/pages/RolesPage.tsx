import { useQuery } from '@tanstack/react-query'
import { Lock, Plus, ShieldCheck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, ButtonLink, Card, DataTable, EmptyState, type Column } from '@/components/ui'
import { useAccount } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatNumber } from '@/lib/format'
import { usersApi, usersKeys } from '../api'
import type { Role } from '../types'

export default function RolesPage() {
  const { subscription } = useAccount()
  const customRolesAllowed = Boolean(subscription?.plan.features?.custom_roles)
  const roles = useQuery({ queryKey: usersKeys.roles, queryFn: usersApi.roles })

  const columns: Column<Role>[] = [
    {
      key: 'name',
      header: 'Role',
      render: (r) => (
        <div>
          <p className="flex items-center gap-1.5 font-medium text-ink">
            {r.name}
            {r.is_locked && <Lock className="size-3.5 text-ink-subtle" aria-label="Locked" />}
          </p>
          {r.description && <p className="text-xs text-ink-subtle">{r.description}</p>}
        </div>
      ),
    },
    { key: 'type', header: 'Type', render: (r) => <Badge tone={r.is_system ? 'neutral' : 'brand'}>{r.is_system ? 'Default' : 'Custom'}</Badge> },
    { key: 'permissions', header: 'Permissions', align: 'right', className: 'hidden sm:table-cell', render: (r) => formatNumber(r.permissions?.length ?? 0) },
    { key: 'users', header: 'Users', align: 'right', render: (r) => formatNumber(r.users_count ?? 0) },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (r) => (
        <ButtonLink to={`/users/roles/${r.id}`} size="sm" variant="ghost">
          {r.is_locked ? 'View' : 'Edit'}
        </ButtonLink>
      ),
    },
  ]

  return (
    <div className="space-y-4">
      {!customRolesAllowed && (
        <Alert tone="info">
          You can adjust the default roles. Creating your own custom roles is available on higher plans.
        </Alert>
      )}
      <Card>
        <div className="flex items-center justify-between gap-3 border-b border-line px-5 py-4">
          <div>
            <h2 className="text-base font-semibold text-ink">Roles</h2>
            <p className="text-sm text-ink-subtle">A role is a set of permissions. Each user has one role.</p>
          </div>
          {customRolesAllowed && (
            <ButtonLink to="/users/roles/new" leftIcon={<Plus className="size-4" />}>
              New role
            </ButtonLink>
          )}
        </div>
        {roles.isError ? (
          <ApiErrorAlert error={ApiError.from(roles.error)} className="m-4" />
        ) : (
          <DataTable
            columns={columns}
            rows={roles.data}
            rowKey={(r) => r.id}
            loading={roles.isFetching}
            caption="Roles"
            empty={<EmptyState icon={<ShieldCheck className="size-5" />} title="No roles" />}
          />
        )}
      </Card>
    </div>
  )
}
