import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Plus, SlidersHorizontal } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { ButtonLink, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { inventoryApi, inventoryKeys, type DocumentFilters } from '../api'
import { StatusBadge } from '../components/StatusBadge'
import { ADJUSTMENT_REASONS, REASON_LABELS, type AdjustmentReason, type StockAdjustment } from '../types'

export default function AdjustmentsPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<DocumentFilters>({ page: 1 })
  const setFilter = (patch: Partial<DocumentFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const canAdjust = can('inventory.adjust')

  const list = useQuery({ queryKey: inventoryKeys.adjustments(filters), queryFn: () => inventoryApi.adjustments(filters), placeholderData: (prev) => prev })

  const columns: Column<StockAdjustment>[] = [
    { key: 'number', header: 'Number', render: (a) => <span className="font-medium text-ink">{a.number}</span> },
    { key: 'reason', header: 'Reason', render: (a) => REASON_LABELS[a.reason_code] ?? a.reason_code },
    { key: 'items', header: 'Items', align: 'right', className: 'hidden sm:table-cell', render: (a) => a.items_count },
    { key: 'status', header: 'Status', render: (a) => <StatusBadge status={a.status} /> },
    { key: 'by', header: 'Created by', className: 'hidden md:table-cell', render: (a) => a.created_by?.name ?? '—' },
    { key: 'date', header: 'Date', className: 'hidden lg:table-cell', render: (a) => <span className="whitespace-nowrap">{formatDateTime(a.posted_at ?? a.created_at)}</span> },
  ]

  return (
    <>
      <PageHeader
        title="Stock adjustments"
        description={`Opening stock, damages, write-offs and corrections at ${activeBranch?.name ?? 'this branch'}.`}
        actions={
          canAdjust && (
            <ButtonLink to="/inventory/adjustments/new" leftIcon={<Plus className="size-4" />}>
              New adjustment
            </ButtonLink>
          )
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Adjustment number…" />
          <Select aria-label="Reason" className="sm:w-52" value={filters.reason_code ?? ''} onChange={(e) => setFilter({ reason_code: (e.target.value || undefined) as AdjustmentReason | undefined })}>
            <option value="">All reasons</option>
            {ADJUSTMENT_REASONS.map((r) => (
              <option key={r} value={r}>
                {REASON_LABELS[r]}
              </option>
            ))}
          </Select>
          <Select aria-label="Status" className="sm:w-40" value={filters.status ?? ''} onChange={(e) => setFilter({ status: e.target.value || undefined })}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="posted">Posted</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(a) => a.id}
              loading={list.isFetching}
              onRowClick={(a) => navigate(`/inventory/adjustments/${a.id}`)}
              caption="Stock adjustments"
              empty={
                <EmptyState
                  icon={<SlidersHorizontal className="size-5" />}
                  title="No adjustments yet"
                  description="Start with opening stock: record what is on your shelves today."
                  action={canAdjust ? <ButtonLink to="/inventory/adjustments/new">New adjustment</ButtonLink> : undefined}
                />
              }
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
