import { Badge, type Tone } from '@/components/ui'

const STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  posted: { label: 'Posted', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'danger' },
  in_transit: { label: 'In transit', tone: 'warning' },
  received: { label: 'Received', tone: 'success' },
  counting: { label: 'Counting', tone: 'info' },
  finalized: { label: 'Finalized', tone: 'success' },
  approved: { label: 'Approved', tone: 'info' },
  partially_received: { label: 'Partly received', tone: 'warning' },
  closed: { label: 'Closed', tone: 'neutral' },
  reversed: { label: 'Reversed', tone: 'danger' },
}

/** Status of an inventory document (adjustment, transfer, count). */
export function StatusBadge({ status }: { status: string }) {
  const s = STATUS[status] ?? { label: status, tone: 'neutral' as Tone }
  return <Badge tone={s.tone} className="whitespace-nowrap">{s.label}</Badge>
}

const STOCK: Record<string, { label: string; tone: Tone }> = {
  in_stock: { label: 'In stock', tone: 'success' },
  low: { label: 'Low', tone: 'warning' },
  out: { label: 'Out', tone: 'danger' },
}

export function StockStatusBadge({ status }: { status: string }) {
  const s = STOCK[status] ?? { label: status, tone: 'neutral' as Tone }
  return <Badge tone={s.tone} className="whitespace-nowrap">{s.label}</Badge>
}
