import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { PackageCheck, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { ButtonLink, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { StatusBadge } from '@/features/inventory/components/StatusBadge'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { purchasingApi, purchasingKeys, type PurchaseFilters } from '../api'
import type { GoodsReceipt } from '../types'

export default function ReceiptsPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<PurchaseFilters>({ page: 1 })
  const setFilter = (patch: Partial<PurchaseFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const canReceive = can('po.receive')

  const list = useQuery({ queryKey: purchasingKeys.receipts(filters), queryFn: () => purchasingApi.receipts(filters), placeholderData: (prev) => prev })

  const columns: Column<GoodsReceipt>[] = [
    { key: 'number', header: 'Number', render: (r) => <span className="font-medium text-ink">{r.number}</span> },
    { key: 'supplier', header: 'Supplier', render: (r) => r.supplier?.name ?? '—' },
    { key: 'po', header: 'PO', className: 'hidden md:table-cell', render: (r) => r.purchase_order?.number ?? <span className="text-ink-subtle">Direct</span> },
    { key: 'invoice', header: 'Invoice no.', className: 'hidden lg:table-cell', render: (r) => r.supplier_invoice_no ?? '—' },
    { key: 'date', header: 'Received', className: 'hidden md:table-cell', render: (r) => <span className="whitespace-nowrap">{formatDateTime(r.received_at)}</span> },
    { key: 'total', header: 'Total', align: 'right', render: (r) => <span className="whitespace-nowrap">{formatMoney(r.total)}</span> },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ]

  return (
    <>
      <PageHeader
        title="Goods received"
        description={`Deliveries received at ${activeBranch?.name ?? 'this branch'}. Each one adds stock at its cost.`}
        actions={
          canReceive && (
            <ButtonLink to="/purchasing/receipts/new" leftIcon={<Plus className="size-4" />}>
              Receive without PO
            </ButtonLink>
          )
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="GR number or supplier invoice no.…" />
          <Select aria-label="Status" className="sm:w-40" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as PurchaseFilters['status'] })}>
            <option value="">All</option>
            <option value="posted">Posted</option>
            <option value="reversed">Reversed</option>
          </Select>
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(r) => r.id}
              loading={list.isFetching}
              onRowClick={(r) => navigate(`/purchasing/receipts/${r.id}`)}
              caption="Goods receipts"
              empty={<EmptyState icon={<PackageCheck className="size-5" />} title="Nothing received yet" description="Receive deliveries from a purchase order, or directly when you buy without one." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
