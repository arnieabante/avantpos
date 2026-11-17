import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Mail, UserPlus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, CardHeader, DataTable, EmptyState, Pagination, useConfirm, type Column, type Tone } from '@/components/ui'
import { useAccount } from '@/features/auth/useAuth'
import { useAction } from '@/hooks/useAction'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, humanize } from '@/lib/format'
import { usersApi, usersKeys } from '../api'
import { InviteModal } from '../components/UserModals'
import type { Invitation, InvitationStatus } from '../types'

const STATUS_TONE: Record<InvitationStatus, Tone> = { pending: 'info', accepted: 'success', revoked: 'neutral', expired: 'warning' }

export default function InvitationsPage() {
  const { tenant, branches } = useAccount()
  const confirm = useConfirm()
  const { run, isPending } = useAction()
  const [page, setPage] = useState(1)
  const [inviting, setInviting] = useState(false)

  const invitations = useQuery({ queryKey: usersKeys.invitations(page), queryFn: () => usersApi.invitations(page), placeholderData: (prev) => prev })
  const branchName = (id: number) => branches.find((b) => b.id === id)?.name ?? `#${id}`
  const refresh = { invalidate: [['invitations']] }

  async function revoke(i: Invitation) {
    if (!(await confirm({ title: `Cancel the invitation for ${i.email}?`, description: 'The link in the e-mail will stop working.', confirmLabel: 'Cancel invitation', danger: true }))) return
    await run(`revoke-${i.id}`, () => usersApi.revokeInvitation(i.id), { ...refresh, success: 'Invitation cancelled' })
  }

  const columns: Column<Invitation>[] = [
    {
      key: 'email',
      header: 'Invited',
      render: (i) => (
        <div>
          <p className="font-medium text-ink">{i.email}</p>
          {i.name && <p className="text-xs text-ink-subtle">{i.name}</p>}
        </div>
      ),
    },
    { key: 'role', header: 'Role', render: (i) => i.role?.name ?? '—' },
    { key: 'branches', header: 'Branches', className: 'hidden md:table-cell', render: (i) => i.branch_ids.map(branchName).join(', ') || '—' },
    { key: 'expires', header: 'Expires', className: 'hidden sm:table-cell', render: (i) => (i.status === 'pending' ? formatDateTime(i.expires_at, tenant.timezone) : '—') },
    { key: 'status', header: 'Status', render: (i) => <Badge tone={STATUS_TONE[i.status]}>{humanize(i.status)}</Badge> },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (i) =>
        (i.status === 'pending' || i.status === 'expired') && (
          <div className="flex justify-end gap-1">
            <Button
              size="sm"
              variant="ghost"
              loading={isPending(`resend-${i.id}`)}
              onClick={() => run(`resend-${i.id}`, () => usersApi.resendInvitation(i.id), { ...refresh, success: `Invitation re-sent to ${i.email}` })}
            >
              Resend
            </Button>
            <Button size="sm" variant="ghost" loading={isPending(`revoke-${i.id}`)} onClick={() => revoke(i)}>
              Cancel
            </Button>
          </div>
        ),
    },
  ]

  return (
    <>
      <Card>
        <CardHeader
          title="Invitations"
          description="Pending invitations reserve a user seat on your plan until they are accepted, cancelled or expire."
          actions={<Button leftIcon={<UserPlus className="size-4" />} onClick={() => setInviting(true)}>Invite</Button>}
        />
        {invitations.isError ? (
          <ApiErrorAlert error={ApiError.from(invitations.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={invitations.data?.items}
              rowKey={(i) => i.id}
              loading={invitations.isFetching}
              caption="Invitations"
              empty={<EmptyState icon={<Mail className="size-5" />} title="No invitations yet" description="Invite cashiers, managers and other staff to work in AvantPOS." />}
            />
            <Pagination meta={invitations.data?.pagination} onPageChange={setPage} disabled={invitations.isFetching} className="border-t border-line" />
          </>
        )}
      </Card>
      {inviting && <InviteModal onClose={() => setInviting(false)} />}
    </>
  )
}
