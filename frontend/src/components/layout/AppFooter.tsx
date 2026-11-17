import { cn } from '@/lib/utils'

const YEAR = new Date().getFullYear()

/**
 * Page footer. Placed as the last child of a full-height flex column, it sits at the
 * bottom of the window on short pages and below the content on long ones.
 */
export function AppFooter({ className }: { className?: string }) {
  return (
    <footer className={cn('px-4 py-4 text-center text-xs text-ink-muted sm:px-6 print:hidden', className)}>
      Copyright © {YEAR} · Powered by <span className="font-medium text-ink">Abantesoft</span>
    </footer>
  )
}
