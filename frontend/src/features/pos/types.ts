import type { Ref } from '@/features/inventory/types'

export type TaxClass = 'vatable' | 'vat_exempt' | 'zero_rated' | 'non_vat'
export type ScPwdType = 'senior' | 'pwd'

export interface PaymentMethodOption {
  id: number
  type: 'cash' | 'gcash' | 'maya' | 'bank_transfer' | 'card' | 'credit' | 'other'
  name: string
  requires_reference: boolean
}

export interface DiscountPresetOption {
  id: number
  name: string
  type: 'percent' | 'fixed'
  value: string
  scope: 'line' | 'transaction'
  requires_permission: boolean
  max_amount: string | null
}

export interface ReceiptSettings {
  id: number | null
  name: string
  paper: '58mm' | '80mm' | 'a4'
  header_text: string | null
  footer_text: string | null
  show_logo: boolean
  layout: Record<string, boolean>
}

export interface PosSetup {
  business: {
    name: string
    registered_name: string | null
    business_style: string | null
    tin: string | null
    vat_registered: boolean
    address: string | null
    phone: string | null
    logo_url: string | null
  }
  branch: { id: number; code: string; name: string; is_main: boolean; tin_branch_code: string | null; address: string | null; phone: string | null }
  session: { id: number; opened_at: string } | null
  terminal: {
    id: number
    code: string
    name: string
    min: string | null
    serial_no: string | null
    ptu_no: string | null
    ptu_issued_at: string | null
    ptu_valid_until: string | null
  } | null
  receipt: ReceiptSettings
  payment_methods: PaymentMethodOption[]
  discount_presets: DiscountPresetOption[]
  categories: Array<{ id: number; parent_id: number | null; name: string; color: string | null }>
  loyalty: { spend_per_point: string; point_value: string; min_redeem_points: number } | null
  features: { customers: boolean; credit_sales: boolean; loyalty: boolean }
  max_discount_percent: string
  /** VAT rate when VAT-registered (for offline pricing), else null. */
  vat_rate: string | null
  /** The terminal's next invoice number (offline sales continue from it). */
  next_invoice_no: string | null
  approvers: Array<{ id: number; name: string; permissions: string[] }>
}

export interface PosUnit {
  /** null = the base unit */
  id: number | null
  name: string
  abbreviation: string
  conversion_factor: string
  allows_decimal: boolean
  price: string
}

export interface PosItem {
  product_variant_id: number
  product_id: number
  name: string
  sku: string
  category_id: number | null
  color: string | null
  image_url: string | null
  type: 'standard' | 'service'
  tax_class: TaxClass
  sc_pwd_eligible: boolean
  track_inventory: boolean
  allow_negative_stock: boolean
  qty_on_hand: string
  price: string
  units: PosUnit[]
  scanned_unit_id?: number | null
}

export type DiscountInput = { preset_id: number } | { type: 'percent' | 'fixed'; value: string }

/** What the server needs to price / ring up a cart (never amounts). */
export interface CartRequest {
  customer_id?: number | null
  items: Array<{
    product_variant_id: number
    product_unit_id: number | null
    qty: string
    unit_price?: string
    sc_pwd?: boolean
    discount?: DiscountInput
  }>
  discount?: DiscountInput | null
  sc_pwd?: { type: ScPwdType; name: string; id_no: string; tin?: string | null } | null
  redeem_points?: number | null
  notes?: string | null
}

export interface QuoteLine {
  index: number
  product_variant_id: number
  product_unit_id: number | null
  name: string
  sku: string
  unit: string | null
  qty: string
  list_price: string
  unit_price: string
  price_overridden: boolean
  gross: string
  line_discount: string
  allocated_discount: string
  sc_pwd: boolean
  sc_pwd_discount: string
  tax_class: TaxClass
  vat_amount: string
  line_total: string
  sc_pwd_eligible: boolean
}

export interface SaleTotals {
  gross: string
  line_discount: string
  transaction_discount: string
  sc_pwd_discount: string
  loyalty_discount: string
  vatable_sales: string
  vat_amount: string
  vat_exempt_sales: string
  zero_rated_sales: string
  total: string
}

export interface Quote {
  lines: QuoteLine[]
  totals: SaleTotals
  discounts: Array<{ scope: 'line' | 'transaction'; line: number | null; type: string; name: string; value_type: string; value: string; amount: string }>
  customer: { id: number; name: string; loyalty_points: string; allow_credit: boolean; available_credit: string | null; group: string | null } | null
  points_redeemed: string
  vat_registered: boolean
  approvals_required: string[]
  approvals_missing: string[]
}

export interface Sale {
  id: number
  invoice_no: string
  status: string
  sold_at: string
  branch: Ref | null
  terminal: { id: number; code: string; name: string; min?: string | null; serial_no?: string | null; ptu_no?: string | null; ptu_issued_at?: string | null } | null
  cashier: Ref | null
  customer: { id: number; name: string; tin: string | null; address: string | null } | null
  totals: SaleTotals & { amount_paid: string; change: string; cogs?: string }
  points: { redeemed: string; earned: string }
  items: Array<{
    id: number
    product_variant_id: number
    name: string
    sku: string
    unit: string | null
    qty: string
    unit_price: string
    line_discount: string
    allocated_discount: string
    sc_pwd_discount: string
    tax_class: TaxClass
    vat_amount: string
    line_total: string
    qty_refunded: string
  }>
  discounts: Array<{
    type: string
    scope: 'line' | 'transaction'
    value_type: string
    value: string
    amount: string
    sale_item_id: number | null
    beneficiary: { name: string; id_masked: string | null; tin: string | null } | null
    authorized_by: Ref | null
  }>
  payments: Array<{ method: string | null; type: string | null; amount: string; tendered: string | null; change: string; reference_no: string | null }>
  notes: string | null
  void?: { voided_at: string | null; reason: string | null; by: Ref | null; authorized_by: Ref | null } | null
  refunds?: SaleRefund[]
  can?: { void: boolean; refund: boolean; reprint: boolean }
  origin?: 'online' | 'offline'
  sync_status?: 'ok' | 'flagged' | 'reviewed'
  sync_issues?: Array<{ code: string; message?: string; notes?: string }> | null
}

export type SaleStatus = 'completed' | 'voided' | 'partially_refunded' | 'refunded'

export interface SaleRefund {
  id: number
  number: string
  reason: string
  subtotal: string
  vat_amount: string
  total: string
  refunded_at: string
  terminal: { id: number; code: string; name: string } | null
  processed_by: Ref | null
  authorized_by: Ref | null
  items: Array<{ sale_item_id: number; qty: string; amount: string; vat_amount: string; restock_to: 'sellable' | 'damaged' | 'none' }>
  payments: Array<{ method: string | null; type: string | null; amount: string }>
}

export interface RegisterReading {
  id: number
  type: 'X' | 'Z'
  reading_no: number
  business_date: string
  generated_at: string
  generated_by: Ref | null
  terminal: { id: number; code: string; name: string; min: string | null; serial_no: string | null; ptu_no: string | null } | null
  cash_register_session_id: number | null
  beginning_invoice_no: string | null
  ending_invoice_no: string | null
  transaction_count: number
  gross_sales: string
  sc_discount: string
  pwd_discount: string
  other_discount: string
  void_amount: string
  /** VAT removed from Senior/PWD items (part of the difference between gross and net). */
  sc_pwd_vat?: string
  refund_amount: string
  net_sales: string
  vatable_sales: string
  vat_amount: string
  vat_exempt_sales: string
  zero_rated_sales: string
  accumulated_grand_total_before: string
  accumulated_grand_total_after: string
  void_count: number
  refund_count: number
  payments: Array<{ method: string; type: string; received: string; refunded: string }>
}

export interface HeldCartSummary {
  id: number
  label: string | null
  customer: Ref | null
  held_by: Ref | null
  terminal: Ref | null
  item_count: number
  preview: { total?: string; names?: string[] } | null
  held_at: string
}

/** Labels for the permissions a supervisor can approve. */
export const APPROVAL_LABELS: Record<string, string> = {
  'pos.price_override': 'Price change',
  'pos.discount_line': 'Item discount',
  'pos.discount_transaction': 'Sale discount',
  'pos.discount_override_limit': 'Discount above the limit',
  'pos.sc_pwd': 'Senior Citizen / PWD discount',
  'pos.credit_sale': 'Charge to account',
  'sales.void': 'Void a sale',
  'sales.refund': 'Refund / return',
}
