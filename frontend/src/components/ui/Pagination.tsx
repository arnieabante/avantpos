import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { Pagination as PaginationMeta } from '@/lib/api/types'
import { formatNumber } from '@/lib/format'
import { pageWindow } from '@/lib/pagination'
import { cn } from '@/lib/utils'

interface PaginationProps {
  meta: PaginationMeta | undefined
  onPageChange: (page: number) => void
  disabled?: boolean
  className?: string
}

/** Works with the API's `meta.pagination` (length-aware paginator). */
export function Pagination({ meta, onPageChange, disabled, className }: PaginationProps) {
  const current = meta?.current_page ?? 1
  const last = meta?.last_page ?? 1
  const perPage = meta?.per_page ?? 0
  const total = meta?.total ?? 0

  if (!meta || total === 0) return null

  const from = (current - 1) * perPage + 1
  const to = Math.min(current * perPage, total)
  const button = 'inline-flex h-9 min-w-9 items-center justify-center rounded-md px-2 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40'

  return (
    <nav aria-label="Pagination" className={cn('flex flex-col items-center justify-between gap-3 px-4 py-3 sm:flex-row', className)}>
      <p className="text-sm text-ink-subtle">
        Showing <span className="font-medium text-ink">{formatNumber(from)}</span>–<span className="font-medium text-ink">{formatNumber(to)}</span> of{' '}
        <span className="font-medium text-ink">{formatNumber(total)}</span>
      </p>

      {last > 1 && (
        <div className="flex items-center gap-1">
          <button type="button" className={cn(button, 'bg-[#f0f2f4] text-ink-muted hover:bg-brand-500/15 hover:text-brand-500')} disabled={disabled || current <= 1} onClick={() => onPageChange(current - 1)} aria-label="Previous page">
            <ChevronLeft className="size-4" />
          </button>
          {pageWindow(current, last).map((page, index) =>
            page === null ? (
              <span key={`gap-${index}`} className="px-1 text-ink-subtle" aria-hidden="true">
                …
              </span>
            ) : (
              <button
                key={page}
                type="button"
                disabled={disabled}
                onClick={() => onPageChange(page)}
                aria-current={page === current ? 'page' : undefined}
                className={cn(button, page === current ? 'bg-brand-500 font-medium text-white shadow-primary' : 'bg-[#f0f2f4] text-ink-muted hover:bg-brand-500/15 hover:text-brand-500')}
              >
                {page}
              </button>
            ),
          )}
          <button type="button" className={cn(button, 'bg-[#f0f2f4] text-ink-muted hover:bg-brand-500/15 hover:text-brand-500')} disabled={disabled || current >= last} onClick={() => onPageChange(current + 1)} aria-label="Next page">
            <ChevronRight className="size-4" />
          </button>
        </div>
      )}
    </nav>
  )
}
