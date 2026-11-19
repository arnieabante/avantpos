import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Building2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDate, humanize } from '@/lib/format'
import { adminApi, adminKeys, type TenantRow } from '../api'
import { TENANT_TONE } from '../labels'

/** /admin/tenants — every business on the platform. */
export default function AdminTenantsPage() {
  const navigate = useNavigate()
  const [f, setF] = useState<{ page: number; search?: string; status?: string; due?: string }>({ page: 1 })
  const q = useQuery({ queryKey: adminKeys.tenants(f), queryFn: () => adminApi.tenants(f), placeholderData: (p) => p })

  const columns: Column<TenantRow>[] = [
    { key: 'name', header: 'Business', render: (t) => <span><span className="font-medium text-ink">{t.name}</span><span className="block text-xs text-ink-subtle">{t.owner?.email ?? t.email}</span></span> },
    { key: 'plan', header: 'Plan', render: (t) => `${t.plan ?? '—'}${t.subscription_status ? ` · ${humanize(t.subscription_status)}` : ''}` },
    {
      key: 'due',
      header: 'Due date',
      className: 'hidden md:table-cell',
      render: (t) =>
        t.due_at ? (
          <span className={t.overdue ? 'font-medium text-[#ff3e1d]' : undefined}>
            {formatDate(t.due_at)}
            {t.overdue && <span className="block text-xs">Overdue</span>}
          </span>
        ) : (
          '—'
        ),
    },
    { key: 'usage', header: 'Branches · users · POS', className: 'hidden lg:table-cell', render: (t) => `${t.usage.branches} · ${t.usage.users} · ${t.usage.terminals}` },
    { key: 'created', header: 'Joined', className: 'hidden xl:table-cell', render: (t) => formatDate(t.created_at) },
    { key: 'status', header: 'Status', render: (t) => <Badge tone={TENANT_TONE[t.status] ?? 'neutral'}>{humanize(t.status)}</Badge> },
  ]

  return (
    <>
      <PageHeader title="Businesses" />
      <Card>
        <FilterBar>
          <SearchInput value={f.search ?? ''} onChange={(search) => setF({ ...f, page: 1, search: search || undefined })} placeholder="Name, e-mail or address" />
          <Select aria-label="Status" className="sm:w-44" value={f.status ?? ''} onChange={(e) => setF({ ...f, page: 1, status: e.target.value || undefined })}>
            <option value="">All statuses</option>
            {Object.keys(TENANT_TONE).map((s) => (
              <option key={s} value={s}>
                {humanize(s)}
              </option>
            ))}
          </Select>
          <Select aria-label="Due" className="sm:w-44" value={f.due ?? ''} onChange={(e) => setF({ ...f, page: 1, due: e.target.value || undefined })}>
            <option value="">Any due date</option>
            <option value="overdue">Overdue</option>
            <option value="soon">Due in 7 days</option>
          </Select>
        </FilterBar>
        {q.isError ? (
          <ApiErrorAlert error={ApiError.from(q.error)} className="m-4" />
        ) : (
          <>
            <DataTable columns={columns} rows={q.data?.items} rowKey={(t) => t.id} loading={q.isFetching} onRowClick={(t) => navigate(`/admin/tenants/${t.id}`)} caption="Businesses"
              empty={<EmptyState icon={<Building2 className="size-5" />} title="No businesses found" />} />
            <Pagination meta={q.data?.pagination} onPageChange={(page) => setF({ ...f, page })} disabled={q.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
