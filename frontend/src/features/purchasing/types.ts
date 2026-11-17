import type { Ref, StockVariant } from '@/features/inventory/types'

/** Purchasing API shapes. Money and quantities are decimal STRINGS. */

export interface Supplier {
  id: number
  code: string | null
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  tin: string | null
  vat_registered: boolean
  address: string | null
  payment_terms_days: number
  notes: string | null
  is_active: boolean
  open_orders_count: number | null
  created_at: string | null
}

export interface SupplierOption {
  id: number
  name: string
  vat_registered: boolean
  payment_terms_days: number
}

export interface SupplierRef {
  id: number
  name: string
  vat_registered: boolean
}

/** A unit an item can be bought in; product_unit_id null = the base unit. */
export interface PurchaseUnit {
  product_unit_id: number | null
  name: string
  abbreviation: string | null
  factor: string
}

export interface PurchasableItem {
  variant: StockVariant
  units: PurchaseUnit[]
  default_unit_id: number | null
  /** Last purchase cost per BASE unit, net of input VAT. */
  last_cost: string | null
  qty_on_hand: string
  reorder_level: string | null
  suggested_qty: string | null
}

export type PoStatus = 'draft' | 'approved' | 'partially_received' | 'received' | 'cancelled' | 'closed'

export interface PurchaseOrder {
  id: number
  number: string
  status: PoStatus
  order_date: string
  expected_date: string | null
  supplier: SupplierRef | null
  branch: Ref | null
  subtotal: string
  tax_total: string
  total: string
  notes: string | null
  created_by: Ref | null
  approved_by: Ref | null
  approved_at: string | null
  created_at: string
  items_count: number
  can: { edit: boolean; approve: boolean; receive: boolean; cancel: boolean; close: boolean }
  items?: Array<{
    id: number
    variant: StockVariant
    unit: PurchaseUnit
    qty_ordered: string
    qty_received: string
    qty_remaining: string
    unit_cost: string
    line_total: string
    tax_amount: string
  }>
  receipts?: Array<{ id: number; number: string; status: string; total: string; received_at: string }>
}

export interface GoodsReceipt {
  id: number
  number: string
  status: 'posted' | 'reversed'
  supplier: SupplierRef | null
  branch: Ref | null
  purchase_order: { id: number; number: string } | null
  supplier_invoice_no: string | null
  supplier_invoice_date: string | null
  received_at: string
  received_by: Ref | null
  subtotal: string
  tax_total: string
  total: string
  notes: string | null
  reversed_by: Ref | null
  reversed_at: string | null
  reversal_reason: string | null
  items_count: number
  can: { reverse: boolean }
  items?: Array<{
    id: number
    variant: StockVariant
    unit: PurchaseUnit
    qty: string
    base_qty: string
    unit_cost: string
    line_total: string
    tax_amount: string
    batch_no: string | null
    expiry_date: string | null
  }>
}

export const PO_STATUS_LABELS: Record<PoStatus, string> = {
  draft: 'Draft',
  approved: 'Approved',
  partially_received: 'Partly received',
  received: 'Received',
  cancelled: 'Cancelled',
  closed: 'Closed',
}
