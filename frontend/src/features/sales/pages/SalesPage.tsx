import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Receipt } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Card, DataTable, EmptyState, FilterBar, Input, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import type { Sale, SaleStatus } from '@/features/pos/types'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { salesApi, salesKeys, type SaleFilters } from '../api'
import { SaleStatusBadge } from '../components/SaleStatusBadge'

/** /sales — sales history of the branch (cashiers: their own). */
export default function SalesPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<SaleFilters>({ page: 1 })
  const setFilter = (patch: Partial<SaleFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const all = can('sales.view_all')
  const list = useQuery({ queryKey: salesKeys.list(filters), queryFn: () => salesApi.list(filters), placeholderData: (prev) => prev })

  const columns: Column<Sale>[] = [
    { key: 'invoice', header: 'Invoice', render: (s) => <span className="font-medium whitespace-nowrap text-ink">{s.invoice_no}</span> },
    { key: 'date', header: 'Date', render: (s) => <span className="whitespace-nowrap">{formatDateTime(s.sold_at)}</span> },
    { key: 'terminal', header: 'Terminal', className: 'hidden md:table-cell', render: (s) => s.terminal?.name ?? '—' },
    ...(all ? [{ key: 'cashier', header: 'Cashier', className: 'hidden lg:table-cell', render: (s: Sale) => s.cashier?.name ?? '—' }] : []),
    { key: 'customer', header: 'Customer', className: 'hidden lg:table-cell', render: (s) => s.customer?.name ?? 'Walk-in' },
    { key: 'payment', header: 'Paid by', className: 'hidden xl:table-cell', render: (s) => [...new Set(s.payments.map((p) => p.method))].join(', ') || '—' },
    { key: 'total', header: 'Total', align: 'right', render: (s) => <span className="tabular-nums">{formatMoney(s.totals.total)}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (s) => (
        <span className="flex flex-wrap gap-1">
          <SaleStatusBadge status={s.status} />
          {s.origin === 'offline' && <Badge tone={s.sync_status === 'flagged' ? 'warning' : 'neutral'}>{s.sync_status === 'flagged' ? 'Needs review' : 'Offline'}</Badge>}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader title="Sales" description={all ? `Every sale at ${activeBranch?.name ?? 'this branch'}.` : 'Your sales.'} />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search: search || undefined })} placeholder="Invoice no. or customer" />
          <Select aria-label="Status" className="sm:w-44" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as SaleStatus | undefined })}>
            <option value="">All sales</option>
            <option value="completed">Completed</option>
            <option value="partially_refunded">Part refunded</option>
            <option value="refunded">Refunded</option>
            <option value="voided">Voided</option>
          </Select>
          <Select aria-label="Offline sales" className="sm:w-44" value={filters.sync_status ?? ''} onChange={(e) => setFilter({ sync_status: (e.target.value || undefined) as SaleFilters['sync_status'] })}>
            <option value="">Online and offline</option>
            <option value="flagged">Needs review</option>
            <option value="reviewed">Reviewed</option>
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
              onRowClick={(s) => navigate(`/sales/${s.id}`)}
              caption="Sales"
              empty={<EmptyState icon={<Receipt className="size-5" />} title="No sales found" description="Sales appear here as they are rung up at the POS." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
