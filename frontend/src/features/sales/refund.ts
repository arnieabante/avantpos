import type { Sale } from '@/features/pos/types'

/**
 * Preview of what a refund gives back (the server computes the real
 * amount the same way): each line refunds its share of what was actually
 * charged on it; returning everything left on a line refunds exactly the
 * remainder.
 */
const cents = (v: string): bigint => {
  const [w = '0', f = ''] = v.split('.')
  return BigInt(w) * 100n + BigInt((f + '00').slice(0, 2))
}
const milli = (v: string): bigint => {
  const [w = '0', f = ''] = v.trim().split('.')
  return BigInt(w) * 1000n + BigInt((f + '000').slice(0, 3))
}
const money = (c: bigint) => `${c / 100n}.${(c % 100n).toString().padStart(2, '0')}`

export function isQty(v: string): boolean {
  return /^\d+(\.\d{1,3})?$/.test(v.trim())
}

/** Quantity still refundable on a line. */
export function remainingQty(item: Sale['items'][number]): string {
  const left = milli(item.qty) - milli(item.qty_refunded)
  return `${left / 1000n}${left % 1000n ? `.${(left % 1000n).toString().padStart(3, '0').replace(/0+$/, '')}` : ''}`
}

/** Amount already refunded on a line. */
function refundedAmount(sale: Sale, itemId: number): bigint {
  return (sale.refunds ?? []).flatMap((r) => r.items).filter((i) => i.sale_item_id === itemId).reduce((sum, i) => sum + cents(i.amount), 0n)
}

/** Refund amount for `qty` of a line, or null when the quantity is not valid. */
export function lineRefund(sale: Sale, item: Sale['items'][number], qty: string): string | null {
  if (!isQty(qty)) return null
  const q = milli(qty)
  const sold = milli(item.qty)
  const left = sold - milli(item.qty_refunded)
  if (q <= 0n || q > left || sold === 0n) return null
  if (q === left) return money(cents(item.line_total) - refundedAmount(sale, item.id))
  // line_total × q / sold, rounded half up to centavos.
  const num = cents(item.line_total) * q * 2n
  return money((num / sold + 1n) / 2n)
}

export function sumCents(values: Array<string | null>): string {
  return money(values.reduce<bigint>((sum, v) => sum + (v ? cents(v) : 0n), 0n))
}
