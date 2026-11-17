import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { ClipboardList, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { ButtonLink, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { StatusBadge } from '@/features/inventory/components/StatusBadge'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney } from '@/lib/format'
import { purchasingApi, purchasingKeys, type PurchaseFilters } from '../api'
import type { PurchaseOrder } from '../types'

export default function PurchaseOrdersPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<PurchaseFilters>({ page: 1 })
  const setFilter = (patch: Partial<PurchaseFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const canCreate = can('po.create')

  const list = useQuery({ queryKey: purchasingKeys.orders(filters), queryFn: () => purchasingApi.orders(filters), placeholderData: (prev) => prev })

  const columns: Column<PurchaseOrder>[] = [
    { key: 'number', header: 'Number', render: (o) => <span className="font-medium text-ink">{o.number}</span> },
    { key: 'supplier', header: 'Supplier', render: (o) => o.supplier?.name ?? '—' },
    { key: 'date', header: 'Ordered', className: 'hidden md:table-cell', render: (o) => <span className="whitespace-nowrap">{formatDate(o.order_date)}</span> },
    { key: 'expected', header: 'Expected', className: 'hidden lg:table-cell', render: (o) => <span className="whitespace-nowrap">{formatDate(o.expected_date)}</span> },
    { key: 'total', header: 'Total', align: 'right', render: (o) => <span className="whitespace-nowrap">{formatMoney(o.total)}</span> },
    { key: 'status', header: 'Status', render: (o) => <StatusBadge status={o.status} /> },
  ]

  return (
    <>
      <PageHeader
        title="Purchase orders"
        description={`Orders placed with suppliers for ${activeBranch?.name ?? 'this branch'}. Stock only changes when goods are received.`}
        actions={
          canCreate && (
            <ButtonLink to="/purchasing/orders/new" leftIcon={<Plus className="size-4" />}>
              New order
            </ButtonLink>
          )
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="PO number…" />
          <Select aria-label="Status" className="sm:w-48" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as PurchaseFilters['status'] })}>
            <option value="">All statuses</option>
            <option value="open">Waiting for delivery</option>
            <option value="draft">Draft</option>
            <option value="approved">Approved</option>
            <option value="partially_received">Partly received</option>
            <option value="received">Received</option>
            <option value="closed">Closed</option>
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
              rowKey={(o) => o.id}
              loading={list.isFetching}
              onRowClick={(o) => navigate(`/purchasing/orders/${o.id}`)}
              caption="Purchase orders"
              empty={
                <EmptyState
                  icon={<ClipboardList className="size-5" />}
                  title="No purchase orders"
                  description="Order from your suppliers here, then receive the goods against the order."
                  action={canCreate ? <ButtonLink to="/purchasing/orders/new">New order</ButtonLink> : undefined}
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
