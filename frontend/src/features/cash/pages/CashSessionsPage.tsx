import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Wallet } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Card, DataTable, EmptyState, FilterBar, Input, PageHeader, Pagination, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { cashApi, cashKeys, type SessionFilters } from '../api'
import { VarianceLabel } from '../components/SessionReport'
import type { CashSession } from '../types'

export default function CashSessionsPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<SessionFilters>({ page: 1 })
  const setFilter = (patch: Partial<SessionFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const all = can('cash.view_all_sessions')

  const list = useQuery({ queryKey: cashKeys.sessions(filters), queryFn: () => cashApi.sessions(filters), placeholderData: (prev) => prev })

  const columns: Column<CashSession>[] = [
    { key: 'opened', header: 'Started', render: (s) => <span className="whitespace-nowrap">{formatDateTime(s.opened_at)}</span> },
    ...(all ? [{ key: 'cashier', header: 'Cashier', render: (s: CashSession) => s.cashier?.name ?? '—' }] : []),
    { key: 'terminal', header: 'Terminal', className: 'hidden md:table-cell', render: (s) => s.terminal?.name ?? '—' },
    { key: 'float', header: 'Float', align: 'right', className: 'hidden lg:table-cell', render: (s) => formatMoney(s.opening_float) },
    { key: 'counted', header: 'Counted', align: 'right', render: (s) => (s.counted_cash ? formatMoney(s.counted_cash) : '—') },
    { key: 'variance', header: 'Over / short', align: 'right', render: (s) => (s.status === 'open' ? <Badge tone="info">Open</Badge> : <VarianceLabel variance={s.variance} />) },
  ]

  return (
    <>
      <PageHeader title="Cash shifts" description={all ? `All register shifts at ${activeBranch?.name ?? 'this branch'}.` : 'Your register shifts.'} />
      <Card>
        <FilterBar>
          <Select aria-label="Status" className="sm:w-36" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as SessionFilters['status'] })}>
            <option value="">All shifts</option>
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </Select>
          <Input type="date" aria-label="From date" className="sm:w-40" value={filters.from ?? ''} max={filters.to} onChange={(e) => setFilter({ from: e.target.value || undefined })} />
          <Input type="date" aria-label="To date" className="sm:w-40" value={filters.to ?? ''} min={filters.from} onChange={(e) => setFilter({ to: e.target.value || undefined })} />
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(s) => s.id}
              loading={list.isFetching}
              onRowClick={(s) => navigate(`/cash-sessions/${s.id}`)}
              caption="Cash shifts"
              empty={<EmptyState icon={<Wallet className="size-5" />} title="No shifts yet" description="Shifts appear here once a cashier starts one at the POS." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
