import type { Ref } from '@/features/inventory/types'

export type MovementType = 'cash_in' | 'cash_out' | 'payout'

export const MOVEMENT_LABELS: Record<MovementType, string> = {
  cash_in: 'Cash in',
  cash_out: 'Cash out',
  payout: 'Payout',
}

/** Philippine bills and coins, in the order a cashier counts them. "20c" is the ₱20 coin. */
export const DENOMINATIONS: Array<{ key: string; label: string; value: string; kind: 'bill' | 'coin' }> = [
  { key: '1000', label: '₱1,000', value: '1000', kind: 'bill' },
  { key: '500', label: '₱500', value: '500', kind: 'bill' },
  { key: '200', label: '₱200', value: '200', kind: 'bill' },
  { key: '100', label: '₱100', value: '100', kind: 'bill' },
  { key: '50', label: '₱50', value: '50', kind: 'bill' },
  { key: '20', label: '₱20', value: '20', kind: 'bill' },
  { key: '20c', label: '₱20', value: '20', kind: 'coin' },
  { key: '10', label: '₱10', value: '10', kind: 'coin' },
  { key: '5', label: '₱5', value: '5', kind: 'coin' },
  { key: '1', label: '₱1', value: '1', kind: 'coin' },
  { key: '0.25', label: '25¢', value: '0.25', kind: 'coin' },
  { key: '0.05', label: '5¢', value: '0.05', kind: 'coin' },
  { key: '0.01', label: '1¢', value: '0.01', kind: 'coin' },
]

export interface PosTerminal {
  id: number
  code: string
  name: string
  in_use_by: Ref | null
  drawers: Array<{ id: number; name: string; shared: boolean; in_use: boolean }>
}

export interface CashSession {
  id: number
  status: 'open' | 'closed'
  branch: Ref | null
  terminal: { id: number; code: string; name: string } | null
  drawer: Ref | null
  cashier: Ref | null
  opened_at: string
  opening_float: string
  closed_at: string | null
  closed_by: Ref | null
  counted_cash: string | null
  /** Hidden from the cashier while the shift is open (blind count). */
  expected_cash: string | null
  variance: string | null
  closing_notes: string | null
  can: { operate: boolean; close: boolean }
  summary?: {
    opening_float: string
    cash_sales: string
    cash_refunds: string
    cash_in: string
    cash_out: string
    payouts: string
    expected_cash?: string
    sales_count: number
    payments_by_method: Array<{ method: string; type: string; amount: string }>
  }
  denominations?: Record<string, number> | null
  movements?: Array<{ id: number; type: MovementType; amount: string; reason: string; user: Ref | null; occurred_at: string }>
}
