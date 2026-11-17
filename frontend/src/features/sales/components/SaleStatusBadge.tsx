import { Badge, type Tone } from '@/components/ui'

const STATUS: Record<string, { label: string; tone: Tone }> = {
  completed: { label: 'Completed', tone: 'success' },
  voided: { label: 'Voided', tone: 'danger' },
  partially_refunded: { label: 'Part refunded', tone: 'warning' },
  refunded: { label: 'Refunded', tone: 'neutral' },
}

export function SaleStatusBadge({ status }: { status: string }) {
  const s = STATUS[status] ?? { label: status, tone: 'neutral' as Tone }
  return (
    <Badge tone={s.tone} className="whitespace-nowrap">
      {s.label}
    </Badge>
  )
}
