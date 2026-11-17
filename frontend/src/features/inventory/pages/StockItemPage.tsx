import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft, Pencil, SlidersHorizontal } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, ButtonLink, Card, CardHeader, DataTable, PageHeader, PageLoader, Pagination, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { daysUntil, formatDate, formatDateTime, formatMoney, humanize } from '@/lib/format'
import { inventoryApi, inventoryKeys } from '../api'
import { MovementsTable } from '../components/MovementsTable'
import { formatQty } from '../format'
import type { StockItemDetail, StockLayer } from '../types'

/** /inventory/items/:variantId — one item across branches, its batches and its ledger. */
export default function StockItemPage() {
  const variantId = Number(useParams().variantId)
  const { can, activeBranch } = useAuth()
  const canSeeCost = can('inventory.valuation')
  const [page, setPage] = useState(1)

  const item = useQuery({ queryKey: inventoryKeys.item(variantId), queryFn: () => inventoryApi.item(variantId), enabled: Number.isFinite(variantId) })
  const movements = useQuery({
    queryKey: inventoryKeys.movements({ product_variant_id: variantId, page, per_page: 20 }),
    queryFn: () => inventoryApi.movements({ product_variant_id: variantId, page, per_page: 20 }),
    placeholderData: (prev) => prev,
  })

  if (item.isPending) return <PageLoader />
  if (item.isError) return <ApiErrorAlert error={ApiError.from(item.error)} />

  const { variant } = item.data
  const unit = variant.unit

  const branchColumns: Column<StockItemDetail['branches'][number]>[] = [
    {
      key: 'branch',
      header: 'Branch',
      render: (b) => (
        <span className="font-medium text-ink">
          {b.branch.name}
          {b.branch.id === activeBranch?.id && <Badge tone="brand" className="ml-2">Selected</Badge>}
        </span>
      ),
    },
    { key: 'qty', header: 'On hand', align: 'right', render: (b) => formatQty(b.qty_on_hand, unit) },
    ...(canSeeCost ? [{ key: 'value', header: 'Value', align: 'right' as const, render: (b: StockItemDetail['branches'][number]) => formatMoney(b.value) }] : []),
    { key: 'last', header: 'Last movement', className: 'hidden md:table-cell', render: (b) => (b.last_movement_at ? formatDateTime(b.last_movement_at) : 'Never') },
  ]

  const layerColumns: Column<StockLayer>[] = [
    { key: 'received', header: 'Received', render: (l) => <span className="whitespace-nowrap">{formatDate(l.received_at)}</span> },
    { key: 'source', header: 'Source', className: 'hidden md:table-cell', render: (l) => humanize(l.source_type) },
    ...(variant.track_expiry
      ? [
          { key: 'batch', header: 'Batch', render: (l: StockLayer) => l.batch_no ?? '—' },
          {
            key: 'expiry',
            header: 'Expiry',
            render: (l: StockLayer) => {
              if (!l.expiry_date) return '—'
              const days = daysUntil(l.expiry_date)
              return (
                <span className="flex items-center gap-2 whitespace-nowrap">
                  {formatDate(l.expiry_date)}
                  {days !== null && days < 0 && <Badge tone="danger">Expired</Badge>}
                  {days !== null && days >= 0 && days <= 30 && <Badge tone="warning">{days}d left</Badge>}
                </span>
              )
            },
          },
        ]
      : []),
    { key: 'remaining', header: 'Remaining', align: 'right', render: (l) => `${formatQty(l.qty_remaining)} of ${formatQty(l.qty_received, unit)}` },
    ...(canSeeCost ? [{ key: 'cost', header: 'Unit cost', align: 'right' as const, render: (l: StockLayer) => formatMoney(l.unit_cost) }] : []),
  ]

  return (
    <div className="space-y-6">
      <ButtonLink to="/inventory" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Stock levels
      </ButtonLink>
      <PageHeader
        title={variant.name}
        description={`SKU ${variant.sku}${item.data.reorder_level && Number(item.data.reorder_level) > 0 ? ` · Reorder at ${formatQty(item.data.reorder_level, unit)}` : ''}`}
        actions={
          <>
            {can('products.manage') && (
              <ButtonLink to={`/products/${variant.product_id}`} variant="outline" leftIcon={<Pencil className="size-4" />}>
                Edit product
              </ButtonLink>
            )}
            {can('inventory.adjust') && (
              <ButtonLink to={`/inventory/adjustments/new?item=${variant.id}`} leftIcon={<SlidersHorizontal className="size-4" />}>
                Adjust stock
              </ButtonLink>
            )}
          </>
        }
      />

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <CardHeader title="Stock by branch" />
          <DataTable columns={branchColumns} rows={item.data.branches} rowKey={(b) => b.branch.id} caption="Stock by branch" />
        </Card>
        <Card>
          <CardHeader
            title={variant.track_expiry ? 'Batches on hand' : 'Cost layers on hand'}
            description={`${activeBranch?.name ?? 'This branch'} · sold ${variant.track_expiry ? 'earliest expiry first' : 'oldest first'}`}
          />
          <DataTable columns={layerColumns} rows={item.data.layers} rowKey={(l) => l.id} caption="Batches" empty={<p className="px-5 py-8 text-center text-sm text-ink-subtle">No stock on hand in this branch.</p>} />
        </Card>
      </div>

      <Card>
        <CardHeader title="Movements" description={activeBranch?.name} />
        <MovementsTable rows={movements.data?.items} loading={movements.isFetching} canSeeCost={canSeeCost} hideItem />
        <Pagination meta={movements.data?.pagination} onPageChange={setPage} disabled={movements.isFetching} className="border-t border-line-soft" />
      </Card>
    </div>
  )
}
