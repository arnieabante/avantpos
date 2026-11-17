import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ScrollText } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Card, DataTable, EmptyState, FilterBar, Input, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, humanize } from '@/lib/format'
import { adminApi, adminKeys, type ActivityRow } from '../api'

/** /admin/activity — the audit log across the platform. */
export default function AdminActivityPage() {
  const [f, setF] = useState<{ page: number; event?: string; scope?: string; from?: string; to?: string }>({ page: 1 })
  const q = useQuery({ queryKey: adminKeys.activity(f), queryFn: () => adminApi.activity(f), placeholderData: (p) => p })
  const columns: Column<ActivityRow>[] = [
    { key: 'when', header: 'When', render: (r) => <span className="whitespace-nowrap">{formatDateTime(r.created_at)}</span> },
    { key: 'event', header: 'Event', render: (r) => <span className="font-medium text-ink">{r.event}</span> },
    { key: 'who', header: 'By', render: (r) => <span>{r.actor.name ?? '—'}<span className="block text-xs text-ink-subtle">{humanize(r.actor.type)}</span></span> },
    { key: 'tenant', header: 'Business', className: 'hidden md:table-cell', render: (r) => r.tenant?.name ?? 'Platform' },
    { key: 'subject', header: 'Record', className: 'hidden lg:table-cell', render: (r) => r.subject ?? '—' },
    { key: 'ip', header: 'IP', className: 'hidden xl:table-cell', render: (r) => r.ip ?? '—' },
  ]
  return (
    <>
      <PageHeader title="Activity log" description="Sign-ins, settings, billing, voids, refunds and other important actions on the platform." />
      <Card>
        <FilterBar>
          <SearchInput value={f.event ?? ''} onChange={(event) => setF({ ...f, page: 1, event: event || undefined })} placeholder="Event, e.g. sale.voided" />
          <Select aria-label="Scope" className="sm:w-44" value={f.scope ?? ''} onChange={(e) => setF({ ...f, page: 1, scope: e.target.value || undefined })}>
            <option value="">Everything</option>
            <option value="platform">Platform admins</option>
            <option value="tenants">Businesses</option>
          </Select>
          <Input type="date" aria-label="From date" className="sm:w-40" value={f.from ?? ''} onChange={(e) => setF({ ...f, page: 1, from: e.target.value || undefined })} />
          <Input type="date" aria-label="To date" className="sm:w-40" value={f.to ?? ''} onChange={(e) => setF({ ...f, page: 1, to: e.target.value || undefined })} />
        </FilterBar>
        {q.isError ? <ApiErrorAlert error={ApiError.from(q.error)} className="m-4" /> : (
          <>
            <DataTable columns={columns} rows={q.data?.items} rowKey={(r) => r.id} loading={q.isFetching} caption="Activity log" empty={<EmptyState icon={<ScrollText className="size-5" />} title="No activity" />} />
            <Pagination meta={q.data?.pagination} onPageChange={(page) => setF({ ...f, page })} disabled={q.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
