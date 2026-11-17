import { formatNumber } from '@/lib/format'
import { IN_REASONS, OUT_REASONS, type AdjustmentReason } from './types'

/**
 * "12.500" + "kg" → "12.5 kg". Quantities stay strings (Intl formats numeric
 * strings exactly); no float arithmetic.
 */
export function formatQty(qty: string | null | undefined, unit?: string | null): string {
  if (qty === null || qty === undefined || qty === '') return '—'
  const shown = formatNumber(qty)
  return unit ? `${shown} ${unit}` : shown
}

/** Signed quantity for ledgers: "+5", "−3". */
export function formatSignedQty(qty: string, unit?: string | null): string {
  const negative = qty.trim().startsWith('-')
  const magnitude = negative ? qty.trim().slice(1) : qty
  return `${negative ? '−' : '+'}${formatQty(magnitude, unit)}`
}

export function isNegative(value: string | null | undefined): boolean {
  return !!value && value.trim().startsWith('-') && !/^-0*(\.0*)?$/.test(value.trim())
}

export function isZeroQty(value: string | null | undefined): boolean {
  return !value || /^-?0*(\.0*)?$/.test(value.trim())
}

/** A quantity the user typed: digits with up to 3 decimals (whole units only when the unit has no decimals). */
export function qtyError(value: string, allowsDecimal: boolean, { allowNegative = false } = {}): string | undefined {
  const v = value.trim()
  if (v === '') return 'Enter a quantity.'
  const pattern = allowNegative ? /^-?\d+(\.\d{1,3})?$/ : /^\d+(\.\d{1,3})?$/
  if (!pattern.test(v)) return allowNegative ? 'Enter a number (use − to remove stock).' : 'Enter a number with up to 3 decimals.'
  if (!allowsDecimal && v.includes('.') && !/\.0*$/.test(v)) return 'Whole units only.'
  if (isZeroQty(v)) return 'Enter a quantity other than zero.'
  return undefined
}

/** Whether an adjustment line adds or removes stock: fixed by the reason, or the sign typed. */
export function lineDirection(reason: AdjustmentReason, qty: string): 'in' | 'out' | null {
  if (IN_REASONS.includes(reason)) return 'in'
  if (OUT_REASONS.includes(reason)) return 'out'
  if (isZeroQty(qty) || !/^-?\d/.test(qty.trim())) return null
  return isNegative(qty) ? 'out' : 'in'
}
