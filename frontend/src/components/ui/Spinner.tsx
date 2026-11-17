import { cn } from '@/lib/utils'

const sizes = { sm: 'size-4', md: 'size-6', lg: 'size-10' }

export function Spinner({ size = 'md', className, label = 'Loading' }: { size?: keyof typeof sizes; className?: string; label?: string }) {
  return (
    <svg className={cn('animate-spin text-brand-500', sizes[size], className)} viewBox="0 0 24 24" fill="none" role="status" aria-label={label}>
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" className="opacity-20" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  )
}

/** Centered spinner for whole pages / sections while data loads. */
export function PageLoader({ label = 'Loading…', fullScreen = false }: { label?: string; fullScreen?: boolean }) {
  return (
    <div className={cn('flex flex-col items-center justify-center gap-3 text-sm text-ink-subtle', fullScreen ? 'min-h-dvh' : 'py-24')}>
      <Spinner size="lg" label={label} />
      <span>{label}</span>
    </div>
  )
}

export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden="true" className={cn('animate-pulse rounded-md bg-line-soft', className)} />
}
