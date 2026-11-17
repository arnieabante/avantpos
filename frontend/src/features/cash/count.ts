import { multiplyToMoney, sumMoney } from '@/lib/decimal'
import { DENOMINATIONS } from './types'

export type Counts = Record<string, string>

/** Total of a bill/coin count, exact (no floats). */
export function countTotal(counts: Counts): string {
  return sumMoney(DENOMINATIONS.map((d) => (counts[d.key]?.trim() ? multiplyToMoney(counts[d.key]!.trim(), d.value) : null)))
}

/** Pieces per denomination for the API (empty / zero rows left out). */
export function countPayload(counts: Counts): Record<string, number> {
  return Object.fromEntries(
    Object.entries(counts)
      .filter(([, v]) => /^\d+$/.test(v.trim()) && Number(v) > 0)
      .map(([k, v]) => [k, Number(v)]),
  )
}
