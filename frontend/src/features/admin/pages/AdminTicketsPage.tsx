import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { ArrowLeft, LifeBuoy } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, ButtonLink, Card, CardBody, DataTable, EmptyState, FilterBar, FormField, PageHeader, PageLoader, Pagination, Select, Textarea, type Column } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, humanize } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useAdminAuth } from '../useAdminAuth'
import { adminApi, adminKeys, type AdminTicket } from '../api'

const TONE: Record<AdminTicket['status'], 'info' | 'warning' | 'success' | 'neutral'> = { open: 'info', pending: 'warning', resolved: 'success', closed: 'neutral' }

/** /admin/tickets and /admin/tickets/:ticketId — support requests from businesses. */
export default function AdminTicketsPage() {
  const id = Number(useParams().ticketId)
  return Number.isFinite(id) && id > 0 ? <Thread id={id} /> : <List />
}

function List() {
  const navigate = useNavigate()
  const [f, setF] = useState<{ page: number; status?: string }>({ page: 1, status: 'open' })
  const q = useQuery({ queryKey: adminKeys.tickets(f), queryFn: () => adminApi.tickets(f), placeholderData: (p) => p })
  const columns: Column<AdminTicket>[] = [
    { key: 'subject', header: 'Request', render: (t) => <span><span className="font-medium text-ink">{t.subject}</span><span className="block text-xs text-ink-subtle">{t.number} · {humanize(t.category)}</span></span> },
    { key: 'tenant', header: 'Business', render: (t) => <span>{t.tenant?.name}<span className="block text-xs text-ink-subtle">{t.opened_by?.email}</span></span> },
    { key: 'priority', header: 'Priority', render: (t) => <Badge tone={t.priority === 'high' || t.priority === 'urgent' ? 'danger' : 'neutral'}>{humanize(t.priority)}</Badge> },
    { key: 'assigned', header: 'Assigned', className: 'hidden lg:table-cell', render: (t) => t.assigned_to?.name ?? '—' },
    { key: 'updated', header: 'Updated', className: 'hidden md:table-cell', render: (t) => formatDateTime(t.updated_at) },
    { key: 'status', header: 'Status', render: (t) => <Badge tone={TONE[t.status]}>{t.status === 'pending' ? 'Waiting for business' : humanize(t.status)}</Badge> },
  ]
  return (
    <>
      <PageHeader title="Support" />
      <Card>
        <FilterBar>
          <Select aria-label="Status" className="sm:w-52" value={f.status ?? ''} onChange={(e) => setF({ page: 1, status: e.target.value || undefined })}>
            <option value="open">Needs a reply</option>
            <option value="pending">Waiting for business</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
            <option value="">All</option>
          </Select>
        </FilterBar>
        {q.isError ? <ApiErrorAlert error={ApiError.from(q.error)} className="m-4" /> : (
          <>
            <DataTable columns={columns} rows={q.data?.items} rowKey={(t) => t.id} loading={q.isFetching} onRowClick={(t) => navigate(`/admin/tickets/${t.id}`)} caption="Support tickets"
              empty={<EmptyState icon={<LifeBuoy className="size-5" />} title="No tickets" />} />
            <Pagination meta={q.data?.pagination} onPageChange={(page) => setF({ ...f, page })} disabled={q.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}

function Thread({ id }: { id: number }) {
  const { admin } = useAdminAuth()
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: adminKeys.ticket(id), queryFn: () => adminApi.ticket(id) })
  const [body, setBody] = useState('')
  const [status, setStatus] = useState<string>('pending')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const canReply = ['super_admin', 'support'].includes(admin?.role ?? '')

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const t = q.data

  const run = async (action: () => Promise<AdminTicket>) => {
    setBusy(true)
    setError(null)
    try {
      queryClient.setQueryData(adminKeys.ticket(id), await action())
      void queryClient.invalidateQueries({ queryKey: ['admin', 'tickets'] })
      setBody('')
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <div className="space-y-6">
      <ButtonLink to="/admin/tickets" variant="link" leftIcon={<ArrowLeft className="size-4" />}>Support</ButtonLink>
      <PageHeader
        title={t.subject}
        description={<>{t.number} · <Link to={`/admin/tenants/${t.tenant?.id}`} className="text-brand-500 hover:underline">{t.tenant?.name}</Link> · {t.opened_by?.name} ({t.opened_by?.email})</>}
        actions={
          <>
            <Badge tone={TONE[t.status]}>{humanize(t.status)}</Badge>
            {canReply && t.status !== 'closed' && <Button variant="outline" size="sm" onClick={() => run(() => adminApi.updateTicket(id, { status: 'closed' }))}>Close</Button>}
          </>
        }
      />
      <ApiErrorAlert error={error} />
      <div className="space-y-3">
        {(t.messages ?? []).map((m) => (
          <Card key={m.id} className={cn('max-w-3xl', m.from === 'support' ? 'ml-auto border-l-4 border-l-brand-500' : '')}>
            <CardBody>
              <p className="text-xs text-ink-subtle">{m.author} · {formatDateTime(m.created_at)}</p>
              <p className="mt-1 text-sm whitespace-pre-line text-ink">{m.body}</p>
            </CardBody>
          </Card>
        ))}
      </div>
      {canReply && (
        <Card className="max-w-3xl">
          <CardBody className="space-y-3">
            <FormField label="Reply to the business">
              <Textarea rows={5} maxLength={5000} value={body} onChange={(e) => setBody(e.target.value)} />
            </FormField>
            <div className="flex flex-wrap items-center justify-end gap-2">
              <Select aria-label="After replying" className="w-56" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="pending">Wait for the business</option>
                <option value="resolved">Mark resolved</option>
                <option value="closed">Close</option>
              </Select>
              <Button loading={busy} disabled={!body.trim()} onClick={() => run(() => adminApi.replyTicket(id, body.trim(), status))}>Send reply</Button>
            </div>
          </CardBody>
        </Card>
      )}
    </div>
  )
}
