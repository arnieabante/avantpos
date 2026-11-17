import { cn } from '@/lib/utils'
import { formatCell } from '../format'
import type { ColumnType, ReportColumn, ReportRow } from '../types'

const NUMERIC: ColumnType[] = ['int', 'qty', 'money', 'percent']

/** Any report, rendered from its typed columns (same layout on screen and on paper). */
export function ReportTable({ columns, rows, totals }: { columns: ReportColumn[]; rows: ReportRow[]; totals: ReportRow | null }) {
  const negative = (v: unknown) => typeof v === 'string' && v.startsWith('-')
  return (
    <div className="overflow-x-auto print:overflow-visible">
      <table className="w-full text-sm print:text-[10px]">
        <thead className="bg-[#f8f9fa] text-xs text-ink-subtle uppercase print:bg-transparent">
          <tr>
            {columns.map((c) => (
              <th key={c.key} scope="col" className={cn('px-4 py-2.5 font-medium whitespace-nowrap', NUMERIC.includes(c.type) ? 'text-right' : 'text-left')}>
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-line-soft">
          {rows.length === 0 && (
            <tr>
              <td colSpan={columns.length} className="px-4 py-8 text-center text-ink-subtle">
                Nothing to report for this period.
              </td>
            </tr>
          )}
          {rows.map((row, i) => (
            <tr key={i} className="hover:bg-[#f8f9fa]/60">
              {columns.map((c) => (
                <td
                  key={c.key}
                  className={cn(
                    'px-4 py-2 whitespace-nowrap',
                    NUMERIC.includes(c.type) ? 'text-right tabular-nums' : 'text-left',
                    negative(row[c.key]) && 'text-[#ff3e1d]',
                    i === 0 && c === columns[0] && 'font-medium text-ink',
                  )}
                >
                  {formatCell(row[c.key], c.type)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        {totals && rows.length > 0 && (
          <tfoot className="border-t-2 border-line font-semibold text-ink">
            <tr>
              {columns.map((c) => (
                <td key={c.key} className={cn('px-4 py-2.5 whitespace-nowrap', NUMERIC.includes(c.type) ? 'text-right tabular-nums' : 'text-left')}>
                  {totals[c.key] === undefined ? '' : formatCell(totals[c.key], c.type)}
                </td>
              ))}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  )
}
