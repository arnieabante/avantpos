import { formatMoney } from '@/lib/format'
import type { PaymentMethodOption } from './types'

/**
 * Tender maths for the payment screen (display only — the server applies
 * the same rules and records the real amounts): card / e-wallet payments
 * cannot exceed what is due, change only comes from cash.
 */
export interface Tender {
  key: string
  method: PaymentMethodOption
  amount: string
  reference: string
}

const toCents = (v: string): bigint | null => {
  const m = /^(\d+)(?:\.(\d{1,2}))?$/.exec(v.trim())
  return m ? BigInt(m[1]!) * 100n + BigInt((m[2] ?? '').padEnd(2, '0')) : null
}
const fromCents = (c: bigint): string => {
  const neg = c < 0n
  const a = neg ? -c : c
  return `${neg ? '-' : ''}${a / 100n}.${(a % 100n).toString().padStart(2, '0')}`
}

export function isAmount(v: string): boolean {
  const c = toCents(v)
  return c !== null && c > 0n
}

export interface TenderSummary {
  paid: string
  /** Still to collect (0.00 when fully paid). */
  remaining: string
  change: string
  /** Why the sale cannot be completed yet, or null. */
  problem: string | null
}

export function summarize(tenders: Tender[], total: string): TenderSummary {
  const due = toCents(total) ?? 0n
  let paid = 0n
  let cash = 0n
  let nonCash = 0n
  for (const t of tenders) {
    const c = toCents(t.amount) ?? 0n
    paid += c
    if (t.method.type === 'cash') cash += c
    else nonCash += c
  }
  const remaining = due > paid ? due - paid : 0n
  const change = paid > due ? paid - due : 0n

  let problem: string | null = null
  const missingRef = tenders.find((t) => t.method.requires_reference && !t.reference.trim())
  if (tenders.some((t) => !isAmount(t.amount))) problem = 'Enter an amount for each payment.'
  else if (nonCash > due) problem = 'Card, e-wallet and account payments cannot be more than the amount due.'
  else if (change > cash) problem = 'Change can only be given from cash.'
  else if (missingRef) problem = `Enter the ${missingRef.method.name} reference number.`
  else if (remaining > 0n) problem = `${formatMoney(fromCents(remaining))} still to be paid.`

  return { paid: fromCents(paid), remaining: fromCents(remaining), change: fromCents(change), problem }
}

/** Quick cash buttons: the exact amount, then the next round bills above it. */
export function cashSuggestions(due: string): string[] {
  const d = toCents(due)
  if (d === null || d <= 0n) return []
  const out = new Set<string>([fromCents(d)])
  for (const bill of [20n, 50n, 100n, 200n, 500n, 1000n]) {
    const step = bill * 100n
    const next = ((d + step - 1n) / step) * step
    if (next > d) out.add(fromCents(next))
    if (out.size >= 5) break
  }
  return [...out].sort((a, b) => (toCents(a)! < toCents(b)! ? -1 : 1)).slice(0, 5)
}

export function paymentsPayload(tenders: Tender[]) {
  return tenders.map((t) => ({
    payment_method_id: t.method.id,
    amount: t.amount.trim(),
    reference_no: t.reference.trim() || null,
  }))
}
