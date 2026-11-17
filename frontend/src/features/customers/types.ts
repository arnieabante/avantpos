import type { Ref } from '@/features/inventory/types'

/** Customer API shapes. Money and points are decimal STRINGS. */

export type PriceTier = 'retail' | 'wholesale'
export type ScPwdType = 'senior' | 'pwd'

export interface CustomerGroup {
  id: number
  name: string
  price_tier: PriceTier
  discount_percent: string
  customers_count: number
}

export interface Customer {
  id: number
  code: string | null
  name: string
  phone: string | null
  email: string | null
  tin: string | null
  address: string | null
  birthdate: string | null
  group: { id: number; name: string; price_tier: PriceTier; discount_percent: string } | null
  sc_pwd: { type: ScPwdType; id_masked: string | null; id_no?: string | null } | null
  allow_credit: boolean
  credit_limit: string
  balance: string
  available_credit: string | null
  loyalty_points: string
  notes: string | null
  is_active: boolean
  created_at: string | null
}

export type LedgerType = 'opening_balance' | 'credit_sale' | 'payment' | 'refund' | 'adjustment'

export const LEDGER_LABELS: Record<LedgerType, string> = {
  opening_balance: 'Opening balance',
  credit_sale: 'Credit sale',
  payment: 'Payment',
  refund: 'Refund',
  adjustment: 'Adjustment',
}

export interface LedgerEntry {
  id: number
  type: LedgerType
  number: string | null
  amount: string
  balance_after: string
  payment_method: string | null
  reference_no: string | null
  notes: string | null
  branch: Ref | null
  user: Ref | null
  created_at: string
}

export interface LoyaltyEntry {
  id: number
  points: string
  balance_after: string
  reason: string
  notes: string | null
  user: Ref | null
  created_at: string
}

export interface LoyaltySettings {
  enabled: boolean
  spend_per_point: string
  point_value: string
  min_redeem_points: number
}

export const SC_PWD_LABELS: Record<ScPwdType, string> = { senior: 'Senior Citizen', pwd: 'PWD' }
