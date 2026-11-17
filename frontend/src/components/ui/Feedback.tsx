import type { ReactNode } from 'react'
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'brand'

/** Soft "label" badges: tinted background, coloured text. */
const badgeTones: Record<Tone, string> = {
  neutral: 'bg-[#ebeef0] text-secondary',
  info: 'bg-[#d7f5fc] text-[#03c3ec]',
  success: 'bg-[#e8fadf] text-[#56ca00] ',
  warning: 'bg-[#fff2d6] text-[#e6a000]',
  danger: 'bg-[#ffe0db] text-[#ff3e1d]',
  brand: 'bg-brand-100 text-brand-500',
}

export function Badge({ tone = 'neutral', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex items-center gap-1 rounded px-2 py-0.5 text-[0.75rem] font-semibold tracking-[0.02em] uppercase', badgeTones[tone], className)}>
      {children}
    </span>
  )
}

type AlertTone = Exclude<Tone, 'neutral' | 'brand'>

const alertTones: Record<AlertTone, { box: string; icon: ReactNode }> = {
  info: { box: 'border-[#b3eef9] bg-[#d7f5fc] text-[#03a4c6]', icon: <Info className="size-5 text-[#03c3ec]" /> },
  success: { box: 'border-[#c6f0b1] bg-[#e8fadf] text-[#4aab00]', icon: <CheckCircle2 className="size-5 text-[#56ca00]" /> },
  warning: { box: 'border-[#ffe3a8] bg-[#fff2d6] text-[#c78a00]', icon: <AlertTriangle className="size-5 text-[#ffab00]" /> },
  danger: { box: 'border-[#ffc5bb] bg-[#ffe0db] text-[#e0371a]', icon: <XCircle className="size-5 text-[#ff3e1d]" /> },
}

export function Alert({ tone = 'info', title, children, action, className }: { tone?: AlertTone; title?: ReactNode; children?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('flex gap-3 rounded-md border px-4 py-3 text-sm', alertTones[tone].box, className)}>
      <span className="mt-px shrink-0" aria-hidden="true">
        {alertTones[tone].icon}
      </span>
      <div className="min-w-0 flex-1">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={title ? 'mt-0.5' : undefined}>{children}</div>}
      </div>
      {action && <div className="shrink-0 self-center">{action}</div>}
    </div>
  )
}

export function EmptyState({ icon, title, description, action, className }: { icon?: ReactNode; title: ReactNode; description?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-col items-center justify-center px-6 py-12 text-center', className)}>
      {icon && <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-brand-100 text-brand-500">{icon}</div>}
      <h3 className="text-base font-medium text-ink">{title}</h3>
      {description && <p className="mt-1 max-w-sm text-sm text-ink-subtle">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}

export function PageHeader({ title, description, actions, className }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; className?: string }) {
  return (
    <div className={cn('mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between', className)}>
      <div className="min-w-0">
        <h1 className="text-[1.375rem] font-medium text-ink">{title}</h1>
        {description && <p className="mt-1 text-sm text-ink-subtle">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}
