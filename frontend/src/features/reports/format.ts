import { trimQty } from '@/lib/decimal'
import { formatDate, formatMoney, formatNumber } from '@/lib/format'
import type { ColumnType } from './types'

/** A report cell, formatted for its column type. */
export function formatCell(value: string | number | null | undefined, type: ColumnType): string {
  if (value === null || value === undefined || value === '') return type === 'text' ? '' : '—'
  switch (type) {
    case 'money':
      return formatMoney(String(value))
    case 'qty':
      return formatNumber(trimQty(String(value)))
    case 'int':
      return formatNumber(value)
    case 'percent':
      return `${value}%`
    case 'date':
      return /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? formatDate(`${value}T00:00:00+08:00`) : String(value)
    default:
      return String(value)
  }
}
