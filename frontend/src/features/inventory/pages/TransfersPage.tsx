import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { ArrowRight, Plus, Truck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, ButtonLink, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { inventoryApi, inventoryKeys, type DocumentFilters } from '../api'
import { StatusBadge } from '../components/StatusBadge'
import type { StockTransfer } from '../types'

export default function TransfersPage() {
  const { can, activeBranch } = useAuth()
  const account = useAccount()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<DocumentFilters>({ page: 1 })
  const setFilter = (patch: Partial<DocumentFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const featureOn = Boolean(account.subscription?.plan.features?.stock_transfers)
  const canCreate = featureOn && can('inventory.transfer')

  const list = useQuery({ queryKey: inventoryKeys.transfers(filters), queryFn: () => inventoryApi.transfers(filters), placeholderData: (prev) => prev })

  const columns: Column<StockTransfer>[] = [
    { key: 'number', header: 'Number', render: (t) => <span className="font-medium text-ink">{t.number}</span> },
    {
      key: 'route',
      header: 'Route',
      render: (t) => (
        <span className="flex items-center gap-2 whitespace-nowrap">
          <span className={t.from_branch?.id === activeBranch?.id ? 'font-medium text-ink' : ''}>{t.from_branch?.name}</span>
          <ArrowRight className="size-4 text-ink-subtle" aria-label="to" />
          <span className={t.to_branch?.id === activeBranch?.id ? 'font-medium text-ink' : ''}>{t.to_branch?.name}</span>
        </span>
      ),
    },
    { key: 'items', header: 'Items', align: 'right', className: 'hidden sm:table-cell', render: (t) => t.items_count },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
    { key: 'date', header: 'Updated', className: 'hidden md:table-cell', render: (t) => <span className="whitespace-nowrap">{formatDateTime(t.received_at ?? t.dispatched_at ?? t.created_at)}</span> },
  ]

  return (
    <>
      <PageHeader
        title="Stock transfers"
        description={`Stock sent from and received at ${activeBranch?.name ?? 'this branch'}.`}
        actions={
          canCreate && (
            <ButtonLink to="/inventory/transfers/new" leftIcon={<Plus className="size-4" />}>
              New transfer
            </ButtonLink>
          )
        }
      />
      {!featureOn && <Alert tone="warning" className="mb-6">Stock transfers are not included in your current plan. Upgrade to move stock between branches.</Alert>}
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Transfer number…" />
          <Select aria-label="Direction" className="sm:w-44" value={filters.direction ?? ''} onChange={(e) => setFilter({ direction: (e.target.value || undefined) as DocumentFilters['direction'] })}>
            <option value="">In and out</option>
            <option value="outgoing">Outgoing</option>
            <option value="incoming">Incoming</option>
          </Select>
          <Select aria-label="Status" className="sm:w-40" value={filters.status ?? ''} onChange={(e) => setFilter({ status: e.target.value || undefined })}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="in_transit">In transit</option>
            <option value="received">Received</option>
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
              rowKey={(t) => t.id}
              loading={list.isFetching}
              onRowClick={(t) => navigate(`/inventory/transfers/${t.id}`)}
              caption="Stock transfers"
              empty={<EmptyState icon={<Truck className="size-5" />} title="No transfers" description="Transfers sent from or to this branch appear here." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
