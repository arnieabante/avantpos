import { Link } from 'react-router'
import { History } from 'lucide-react'
import { DataTable, EmptyState, type Column } from '@/components/ui'
import { formatDateTime, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { formatQty, formatSignedQty, isNegative } from '../format'
import { MOVEMENT_LABELS, type StockMovement } from '../types'

const REFERENCE_ROUTES: Record<string, string> = {
  stock_adjustment: '/inventory/adjustments',
  stock_transfer: '/inventory/transfers',
  stock_count: '/inventory/counts',
  goods_receipt: '/purchasing/receipts',
}

export function MovementReference({ reference }: { reference: StockMovement['reference'] }) {
  if (!reference) return <>—</>
  const base = REFERENCE_ROUTES[reference.type]
  const label = reference.number ?? `#${reference.id}`
  return base ? (
    <Link to={`${base}/${reference.id}`} className="font-medium text-brand-500 hover:underline" onClick={(e) => e.stopPropagation()}>
      {label}
    </Link>
  ) : (
    <>{label}</>
  )
}

interface MovementsTableProps {
  rows: StockMovement[] | undefined
  loading: boolean
  canSeeCost: boolean
  /** Hide the item column (on an item's own page). */
  hideItem?: boolean
  onItemClick?: (variantId: number) => void
}

/** The stock ledger: one row per movement, newest first. */
export function MovementsTable({ rows, loading, canSeeCost, hideItem, onItemClick }: MovementsTableProps) {
  const columns: Column<StockMovement>[] = [
    { key: 'date', header: 'Date', render: (m) => <span className="whitespace-nowrap">{formatDateTime(m.occurred_at)}</span> },
    ...(hideItem
      ? []
      : [
          {
            key: 'item',
            header: 'Item',
            render: (m: StockMovement) => (
              <div className="min-w-0">
                <p className="truncate font-medium text-ink">{m.variant.name}</p>
                <p className="truncate text-xs text-ink-subtle">{m.variant.sku}</p>
              </div>
            ),
          },
        ]),
    {
      key: 'type',
      header: 'Movement',
      render: (m) => (
        <div>
          <p className="text-ink">{MOVEMENT_LABELS[m.type] ?? m.type}</p>
          {m.reason && <p className="max-w-56 truncate text-xs text-ink-subtle" title={m.reason}>{m.reason}</p>}
        </div>
      ),
    },
    { key: 'ref', header: 'Reference', className: 'hidden md:table-cell', render: (m) => <MovementReference reference={m.reference} /> },
    {
      key: 'qty',
      header: 'Qty',
      align: 'right',
      render: (m) => (
        <span className={cn('font-medium whitespace-nowrap', isNegative(m.qty) ? 'text-[#ff3e1d]' : 'text-[#56ca00]')}>{formatSignedQty(m.qty, m.variant.unit)}</span>
      ),
    },
    { key: 'balance', header: 'Balance', align: 'right', render: (m) => <span className="whitespace-nowrap">{formatQty(m.balance_after, m.variant.unit)}</span> },
    ...(canSeeCost
      ? [
          {
            key: 'cost',
            header: 'Cost',
            align: 'right' as const,
            className: 'hidden lg:table-cell',
            render: (m: StockMovement) => (
              <div className="whitespace-nowrap">
                <p>{formatMoney(m.total_cost)}</p>
                <p className="text-xs text-ink-subtle">@ {formatMoney(m.unit_cost)}</p>
              </div>
            ),
          },
        ]
      : []),
    { key: 'user', header: 'By', className: 'hidden xl:table-cell', render: (m) => m.user?.name ?? 'System' },
  ]

  return (
    <DataTable
      columns={columns}
      rows={rows}
      rowKey={(m) => m.id}
      loading={loading}
      caption="Stock movements"
      onRowClick={onItemClick ? (m) => onItemClick(m.variant.id) : undefined}
      empty={<EmptyState icon={<History className="size-5" />} title="No movements" description="Stock changes appear here as they happen." />}
    />
  )
}
