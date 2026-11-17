import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { EmptyState } from './Feedback'
import { Skeleton } from './Spinner'

export interface Column<T> {
  key: string
  header: ReactNode
  render: (row: T) => ReactNode
  align?: 'left' | 'right' | 'center'
  /** Hide on small screens (e.g. 'hidden md:table-cell'). */
  className?: string
  headerClassName?: string
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[] | undefined
  rowKey: (row: T) => string | number
  loading?: boolean
  /** Shown when `rows` is empty and not loading. */
  empty?: ReactNode
  onRowClick?: (row: T) => void
  caption?: string
  className?: string
  skeletonRows?: number
}

const alignClass = { left: 'text-left', right: 'text-right', center: 'text-center' }

/**
 * Generic table used by every list screen. Server-side pagination/filtering
 * is done by the caller (see Pagination + FilterBar).
 */
export function DataTable<T>({ columns, rows, rowKey, loading, empty, onRowClick, caption, className, skeletonRows = 5 }: DataTableProps<T>) {
  const showSkeleton = loading && (!rows || rows.length === 0)
  const isEmpty = !loading && rows !== undefined && rows.length === 0

  return (
    <div className={cn('overflow-x-auto', className)}>
      <table className="min-w-full text-[0.9375rem]">
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead className="border-y border-line-soft">
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={cn(
                  'px-5 py-3.5 text-[0.75rem] font-medium tracking-[0.08em] whitespace-nowrap text-ink uppercase',
                  alignClass[column.align ?? 'left'],
                  column.className,
                  column.headerClassName,
                )}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className={cn('divide-y divide-line-soft', loading && rows && rows.length > 0 && 'opacity-60')}>
          {showSkeleton &&
            Array.from({ length: skeletonRows }, (_, i) => (
              <tr key={`skeleton-${i}`}>
                {columns.map((column) => (
                  <td key={column.key} className={cn('px-5 py-4', column.className)}>
                    <Skeleton className="h-4 w-full max-w-40" />
                  </td>
                ))}
              </tr>
            ))}

          {!showSkeleton &&
            rows?.map((row) => (
              <tr
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                className={cn('transition-colors hover:bg-canvas/70', onRowClick && 'cursor-pointer')}
              >
                {columns.map((column) => (
                  <td key={column.key} className={cn('px-5 py-3 text-ink-muted', alignClass[column.align ?? 'left'], column.className)}>
                    {column.render(row)}
                  </td>
                ))}
              </tr>
            ))}

          {isEmpty && (
            <tr>
              <td colSpan={columns.length}>{empty ?? <EmptyState title="Nothing here yet" />}</td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}
