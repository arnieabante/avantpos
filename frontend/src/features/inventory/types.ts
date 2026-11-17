/** Inventory API shapes. Quantities and money are decimal STRINGS; never do arithmetic on them here. */

export interface StockVariant {
  id: number
  product_id: number
  name: string
  product_name: string
  variant_name: string | null
  sku: string
  unit: string | null
  allows_decimal: boolean
  track_expiry: boolean
  is_active: boolean
}

export interface Ref {
  id: number
  name: string
}

export type StockStatus = 'in_stock' | 'low' | 'out'

export interface StockLevel {
  variant: StockVariant
  qty_on_hand: string
  reorder_level: string | null
  stock_status: StockStatus
  last_movement_at: string | null
  /** Only with inventory.valuation */
  value?: string
  average_cost?: string | null
}

export interface StockLayer {
  id: number
  source_type: string
  batch_no: string | null
  expiry_date: string | null
  qty_received: string
  qty_remaining: string
  received_at: string | null
  unit_cost?: string
}

export interface StockItemDetail {
  variant: StockVariant
  reorder_level: string | null
  branches: Array<{ branch: Ref; qty_on_hand: string; last_movement_at: string | null; value?: string }>
  layers: StockLayer[]
}

export interface StockAlerts {
  low_stock: number
  out_of_stock: number
  expiring: number
  expired: number
  expiry_window_days: number
}

export interface ExpiringBatch {
  id: number
  variant: StockVariant
  batch_no: string | null
  expiry_date: string
  qty_remaining: string
  expired: boolean
  value?: string
}

export const MOVEMENT_TYPES = [
  'opening', 'purchase_receipt', 'sale', 'sale_void', 'return', 'adjustment_in', 'adjustment_out',
  'transfer_out', 'transfer_in', 'count_variance', 'receipt_reversal',
] as const
export type MovementType = (typeof MOVEMENT_TYPES)[number]

export const MOVEMENT_LABELS: Record<MovementType, string> = {
  opening: 'Opening stock',
  purchase_receipt: 'Purchase received',
  sale: 'Sale',
  sale_void: 'Voided sale',
  return: 'Customer return',
  adjustment_in: 'Adjustment (in)',
  adjustment_out: 'Adjustment (out)',
  transfer_out: 'Transfer out',
  transfer_in: 'Transfer in',
  count_variance: 'Count variance',
  receipt_reversal: 'Receipt reversed',
}

export interface StockMovement {
  id: number
  type: MovementType
  qty: string
  balance_after: string
  variant: StockVariant
  branch: Ref | null
  reference: { type: string; id: number; number: string | null } | null
  reason: string | null
  user: Ref | null
  occurred_at: string
  unit_cost?: string
  total_cost?: string
}

export interface PickerItem {
  variant: StockVariant
  qty_on_hand: string
  current_cost?: string
}

/* ---------------------------------------------------------------- Adjustments */

export const ADJUSTMENT_REASONS = ['opening', 'found', 'damaged', 'expired', 'lost', 'internal_use', 'correction', 'other'] as const
export type AdjustmentReason = (typeof ADJUSTMENT_REASONS)[number]

export const REASON_LABELS: Record<AdjustmentReason, string> = {
  opening: 'Opening stock',
  found: 'Found / returned to shelf',
  damaged: 'Damaged',
  expired: 'Expired',
  lost: 'Lost or stolen',
  internal_use: 'Internal use',
  correction: 'Correction',
  other: 'Other',
}

/** Reasons that always add / always remove stock; the rest take the sign typed. */
export const IN_REASONS: readonly AdjustmentReason[] = ['opening', 'found']
export const OUT_REASONS: readonly AdjustmentReason[] = ['damaged', 'expired', 'lost', 'internal_use']

export type DocumentStatus = 'draft' | 'posted' | 'cancelled'

export interface StockAdjustment {
  id: number
  number: string
  status: DocumentStatus
  reason_code: AdjustmentReason
  notes: string | null
  branch: Ref | null
  created_by: Ref | null
  posted_by: Ref | null
  posted_at: string | null
  created_at: string
  items_count: number
  value_change?: string
  items?: Array<{
    id: number
    variant: StockVariant
    qty: string
    batch_no: string | null
    expiry_date: string | null
    unit_cost?: string | null
    value?: string | null
  }>
}

/* ---------------------------------------------------------------- Transfers */

export type TransferStatus = 'draft' | 'in_transit' | 'received' | 'cancelled'

export interface StockTransfer {
  id: number
  number: string
  status: TransferStatus
  notes: string | null
  from_branch: Ref | null
  to_branch: Ref | null
  created_by: Ref | null
  dispatched_by: Ref | null
  received_by: Ref | null
  dispatched_at: string | null
  received_at: string | null
  created_at: string
  items_count: number
  can: { edit: boolean; receive: boolean; cancel: boolean }
  value?: string
  items?: Array<{
    id: number
    variant: StockVariant
    qty_sent: string
    qty_received: string
    qty_short: string | null
    value?: string | null
  }>
}

/* ---------------------------------------------------------------- Counts */

export type CountStatus = 'counting' | 'finalized' | 'cancelled'

export interface StockCount {
  id: number
  number: string
  status: CountStatus
  notes: string | null
  branch: Ref | null
  created_by: Ref | null
  finalized_by: Ref | null
  finalized_at: string | null
  created_at: string
  updated_at: string
  summary: { items: number; counted: number; variances: number }
}

export interface StockCountItem {
  id: number
  variant: StockVariant
  system_qty: string
  counted_qty: string | null
  variance_qty: string | null
}

/* ---------------------------------------------------------------- Valuation */

export interface Valuation {
  branches: Array<{ branch: Ref; value: string; items_in_stock: number }>
  total: string
  categories: Array<{ category: Ref | null; value: string }>
  method: string
}
