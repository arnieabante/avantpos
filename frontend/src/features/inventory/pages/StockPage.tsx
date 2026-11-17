import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router'
import { AlertTriangle, Boxes, CalendarClock, ClipboardList, PackageX, SlidersHorizontal, TrendingDown } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, ButtonLink, Card, CardHeader, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { catalogApi, catalogKeys } from '@/features/catalog/api'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { inventoryApi, inventoryKeys, type StockFilters } from '../api'
import { StockStatusBadge } from '../components/StatusBadge'
import { formatQty } from '../format'
import type { ExpiringBatch, StockLevel } from '../types'

const STATUSES = ['low', 'out', 'in_stock', 'negative'] as const

function AlertTile({ label, value, icon, tone, active, onClick }: { label: string; value: number | undefined; icon: ReactNode; tone: string; active?: boolean; onClick?: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'flex flex-col items-start gap-3 rounded-lg bg-surface p-4 text-left sm:flex-row sm:items-center sm:gap-4 sm:p-5 shadow-card transition hover:-translate-y-0.5',
        active && 'ring-2 ring-brand-500',
        !onClick && 'pointer-events-none',
      )}
    >
      <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-md', tone)}>{icon}</span>
      <span>
        <span className="block text-[1.375rem] leading-tight font-medium text-ink">{value ?? '–'}</span>
        <span className="block text-sm text-ink-muted">{label}</span>
      </span>
    </button>
  )
}

export default function StockPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const initialStatus = STATUSES.find((s) => s === params.get('status'))
  const [filters, setFilters] = useState<StockFilters>({ page: 1, status: initialStatus })
  const setFilter = (patch: Partial<StockFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const canSeeCost = can('inventory.valuation')

  const stock = useQuery({ queryKey: inventoryKeys.stock(filters), queryFn: () => inventoryApi.stock(filters), placeholderData: (prev) => prev })
  const alerts = useQuery({ queryKey: inventoryKeys.alerts, queryFn: inventoryApi.alerts })
  const categories = useQuery({ queryKey: catalogKeys.categories, queryFn: catalogApi.categories, staleTime: 60_000, enabled: can('products.view') || can('products.manage') })
  const topLevel = (categories.data ?? []).filter((c) => c.parent_id === null)
  const hasExpiry = (alerts.data?.expiring ?? 0) + (alerts.data?.expired ?? 0) > 0

  const toggleStatus = (status: StockFilters['status']) => setFilter({ status: filters.status === status ? undefined : status })

  const columns: Column<StockLevel>[] = [
    {
      key: 'item',
      header: 'Item',
      render: (s) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink">
            {s.variant.name}
            {!s.variant.is_active && <Badge className="ml-2">Inactive</Badge>}
          </p>
          <p className="truncate text-xs text-ink-subtle">{s.variant.sku}</p>
        </div>
      ),
    },
    {
      key: 'qty',
      header: 'On hand',
      align: 'right',
      render: (s) => <span className={cn('font-medium whitespace-nowrap', s.stock_status === 'out' ? 'text-[#ff3e1d]' : 'text-ink')}>{formatQty(s.qty_on_hand, s.variant.unit)}</span>,
    },
    { key: 'status', header: 'Status', render: (s) => <StockStatusBadge status={s.stock_status} /> },
    { key: 'reorder', header: 'Reorder at', align: 'right', className: 'hidden md:table-cell', render: (s) => (s.reorder_level && Number(s.reorder_level) > 0 ? formatQty(s.reorder_level, s.variant.unit) : '—') },
    ...(canSeeCost
      ? [
          { key: 'cost', header: 'Avg. cost', align: 'right' as const, className: 'hidden lg:table-cell', render: (s: StockLevel) => formatMoney(s.average_cost) },
          { key: 'value', header: 'Value', align: 'right' as const, render: (s: StockLevel) => <span className="whitespace-nowrap">{formatMoney(s.value)}</span> },
        ]
      : []),
    { key: 'last', header: 'Last movement', className: 'hidden xl:table-cell', render: (s) => (s.last_movement_at ? formatDateTime(s.last_movement_at) : 'Never') },
  ]

  return (
    <>
      <PageHeader
        title="Stock levels"
        description={`What is on hand at ${activeBranch?.name ?? 'this branch'}. Switch branches from the top bar.`}
        actions={
          <>
            {can('inventory.count') && (
              <ButtonLink to="/inventory/counts" variant="outline" leftIcon={<ClipboardList className="size-4" />}>
                Count stock
              </ButtonLink>
            )}
            {can('inventory.adjust') && (
              <ButtonLink to="/inventory/adjustments/new" leftIcon={<SlidersHorizontal className="size-4" />}>
                Adjust stock
              </ButtonLink>
            )}
          </>
        }
      />

      <div className="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
        <AlertTile label="Low stock" value={alerts.data?.low_stock} icon={<TrendingDown className="size-5" />} tone="bg-[#fff2d6] text-[#ffab00]" active={filters.status === 'low'} onClick={() => toggleStatus('low')} />
        <AlertTile label="Out of stock" value={alerts.data?.out_of_stock} icon={<PackageX className="size-5" />} tone="bg-[#ffe0db] text-[#ff3e1d]" active={filters.status === 'out'} onClick={() => toggleStatus('out')} />
        <AlertTile
          label={`Expiring in ${alerts.data?.expiry_window_days ?? 30} days`}
          value={alerts.data?.expiring}
          icon={<CalendarClock className="size-5" />}
          tone="bg-[#d7f5fc] text-[#03c3ec]"
          onClick={hasExpiry ? () => document.getElementById('expiring-batches')?.scrollIntoView({ behavior: 'smooth' }) : undefined}
        />
        <AlertTile
          label="Expired batches"
          value={alerts.data?.expired}
          icon={<AlertTriangle className="size-5" />}
          tone="bg-[#ebeef0] text-secondary"
          onClick={hasExpiry ? () => document.getElementById('expiring-batches')?.scrollIntoView({ behavior: 'smooth' }) : undefined}
        />
      </div>

      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Name, SKU or barcode…" />
          {topLevel.length > 0 && (
            <Select aria-label="Category" className="sm:w-52" value={filters.category_id ?? ''} onChange={(e) => setFilter({ category_id: e.target.value ? Number(e.target.value) : undefined })}>
              <option value="">All categories</option>
              {topLevel.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          )}
          <Select aria-label="Stock status" className="sm:w-44" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as StockFilters['status'] })}>
            <option value="">All items</option>
            <option value="in_stock">In stock</option>
            <option value="low">Low stock</option>
            <option value="out">Out of stock</option>
            <option value="negative">Negative</option>
          </Select>
        </FilterBar>
        {stock.isError ? (
          <ApiErrorAlert error={ApiError.from(stock.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={stock.data?.items}
              rowKey={(s) => s.variant.id}
              loading={stock.isFetching}
              onRowClick={(s) => navigate(`/inventory/items/${s.variant.id}`)}
              caption="Stock levels"
              empty={
                <EmptyState
                  icon={<Boxes className="size-5" />}
                  title={filters.search || filters.status || filters.category_id ? 'Nothing matches' : 'No stock-tracked products yet'}
                  description={filters.search || filters.status ? 'Try another search or filter.' : 'Products with “Track stock” turned on appear here. Add opening stock with an adjustment.'}
                />
              }
            />
            <Pagination meta={stock.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={stock.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>

      {hasExpiry && <ExpiringBatches canSeeCost={canSeeCost} />}
    </>
  )
}

function ExpiringBatches({ canSeeCost }: { canSeeCost: boolean }) {
  const navigate = useNavigate()
  const batches = useQuery({ queryKey: inventoryKeys.expiring, queryFn: inventoryApi.expiring })

  const columns: Column<ExpiringBatch>[] = [
    { key: 'item', header: 'Item', render: (b) => <span className="font-medium text-ink">{b.variant.name}</span> },
    { key: 'batch', header: 'Batch', render: (b) => b.batch_no ?? '—' },
    {
      key: 'expiry',
      header: 'Expiry',
      render: (b) => (
        <span className="flex items-center gap-2 whitespace-nowrap">
          {formatDate(b.expiry_date)}
          <Badge tone={b.expired ? 'danger' : 'warning'}>{b.expired ? 'Expired' : 'Soon'}</Badge>
        </span>
      ),
    },
    { key: 'qty', header: 'Remaining', align: 'right', render: (b) => formatQty(b.qty_remaining, b.variant.unit) },
    ...(canSeeCost ? [{ key: 'value', header: 'Value', align: 'right' as const, render: (b: ExpiringBatch) => formatMoney(b.value) }] : []),
  ]

  return (
    <Card className="mt-6" id="expiring-batches">
      <CardHeader title="Expiring and expired batches" description="Sell these first, or write them off with an “Expired” adjustment." />
      <DataTable columns={columns} rows={batches.data} rowKey={(b) => b.id} loading={batches.isFetching} caption="Expiring batches" onRowClick={(b) => navigate(`/inventory/items/${b.variant.id}`)} />
    </Card>
  )
}
