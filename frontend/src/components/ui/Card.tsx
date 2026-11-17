import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('rounded-lg bg-surface shadow-card', className)} {...props} />
}

export function CardHeader({ title, description, actions, className }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <div className={cn('card-header flex flex-wrap items-start justify-between gap-3 p-6', className)}>
      <div className="min-w-0">
        <h2 className="text-lg font-medium text-ink">{title}</h2>
        {description && <p className="mt-0.5 text-sm text-ink-subtle">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
    </div>
  )
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  // Directly under a CardHeader the body needs no top padding.
  return <div className={cn('p-6 [.card-header+&]:pt-0', className)} {...props} />
}

export function CardFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('flex items-center justify-end gap-2 border-t border-line-soft px-6 py-4', className)} {...props} />
}

/** Dashboard widget: a labelled figure with optional icon and footnote. */
export function StatCard({ label, value, icon, footnote, className }: { label: ReactNode; value: ReactNode; icon?: ReactNode; footnote?: ReactNode; className?: string }) {
  return (
    <Card className={cn('p-6', className)}>
      {icon && <div className="mb-4 flex size-10 items-center justify-center rounded-md bg-brand-100 text-brand-500">{icon}</div>}
      <p className="font-medium text-ink-muted">{label}</p>
      <p className="mt-1 truncate text-[1.625rem] leading-tight font-medium text-ink">{value}</p>
      {footnote && <p className="mt-2 text-[0.8125rem] text-ink-subtle">{footnote}</p>}
    </Card>
  )
}

/** Definition list for read-only details ("Label: value" rows). */
export function DetailList({ items, className }: { items: Array<{ label: ReactNode; value: ReactNode }>; className?: string }) {
  return (
    <dl className={cn('divide-y divide-line-soft', className)}>
      {items.map((item, index) => (
        <div key={index} className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
          <dt className="text-sm text-ink-muted">{item.label}</dt>
          <dd className="text-sm font-medium text-ink sm:text-right">{item.value}</dd>
        </div>
      ))}
    </dl>
  )
}
