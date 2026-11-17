/** Payloads of the Phase 4A business-setup API (see backend Http/Resources). */

export interface PhAddress {
  address_line: string | null
  region_code: string | null
  region_name: string | null
  province_code: string | null
  province_name: string | null
  city_code: string | null
  city_name: string | null
  barangay_code: string | null
  barangay_name: string | null
  postal_code: string | null
  formatted: string | null
}

/** Address fields as sent to the API. */
export interface AddressInput {
  address_line: string
  region_code: string
  province_code: string
  city_code: string
  barangay_code: string
  postal_code: string
}

export interface Business {
  id: number
  name: string
  business_type: string | null
  subdomain: string | null
  status: string
  registered_name: string | null
  business_style: string | null
  tin: string | null
  tin_branch_code: string
  rdo_code: string | null
  vat_registered: boolean
  email: string | null
  phone: string | null
  address: PhAddress
  logo_url: string | null
  timezone: string
  currency: string
  date_format: string
  time_format: string
  updated_at: string | null
}

export type Weekday = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'
export interface DayHours {
  closed: boolean
  open?: string | null
  close?: string | null
}
export type OperatingHours = Partial<Record<Weekday, DayHours>>

export interface Branch {
  id: number
  code: string
  name: string
  is_main: boolean
  is_active: boolean
  phone: string | null
  email: string | null
  address: PhAddress
  bir_branch_code: string | null
  operating_hours: OperatingHours | null
  terminals_count?: number
  users_count?: number
}

export type TerminalStatus = 'active' | 'inactive' | 'retired'

export interface CashRegister {
  id: number
  branch_id: number
  terminal_id: number | null
  name: string
  is_active: boolean
  branch?: { id: number; name: string }
  terminal?: { id: number; code: string; name: string } | null
}

export interface Terminal {
  id: number
  branch_id: number
  branch?: { id: number; code: string; name: string }
  code: string
  name: string
  status: TerminalStatus
  min: string | null
  serial_no: string | null
  ptu_no: string | null
  ptu_issued_at: string | null
  ptu_valid_until: string | null
  device: {
    label: string | null
    platform: string | null
    registered_at: string
    last_seen_at: string | null
    last_synced_at: string | null
  } | null
  cash_registers?: CashRegister[]
}

export interface TaxRate {
  id: number
  name: string
  type: 'vat' | 'percentage_tax'
  rate: string
  is_default: boolean
  is_active: boolean
}

export type PaymentType = 'cash' | 'gcash' | 'maya' | 'bank_transfer' | 'card' | 'credit' | 'other'

export interface PaymentMethod {
  id: number
  type: PaymentType
  name: string
  requires_reference: boolean
  sort_order: number
  is_active: boolean
  gateway_code: string | null
}

export interface DiscountPreset {
  id: number
  name: string
  type: 'percent' | 'fixed'
  value: string
  scope: 'line' | 'transaction'
  statutory: string | null
  requires_permission: boolean
  max_amount: string | null
  is_active: boolean
}

export type ReceiptLayoutKey =
  | 'show_business_address'
  | 'show_tin'
  | 'show_branch'
  | 'show_cashier'
  | 'show_customer'
  | 'show_item_sku'
  | 'show_tax_breakdown'
  | 'show_payment_details'

export interface ReceiptTemplate {
  id: number
  name: string
  paper: '58mm' | '80mm' | 'a4'
  header_text: string | null
  footer_text: string | null
  show_logo: boolean
  layout: Record<ReceiptLayoutKey, boolean>
  is_default: boolean
}

export interface PsgcOption {
  code: string
  name: string
  type?: string
  region_name?: string | null
}

/** Display labels, kept next to the types they describe. */
export const PAYMENT_TYPE_LABELS: Record<PaymentType, string> = {
  cash: 'Cash',
  gcash: 'GCash',
  maya: 'Maya',
  bank_transfer: 'Bank transfer',
  card: 'Debit / credit card',
  credit: 'Charge to account',
  other: 'Other',
}

export const DATE_FORMATS: Array<{ value: string; label: string }> = [
  { value: 'M d, Y', label: 'Nov 14, 2026' },
  { value: 'F d, Y', label: 'November 14, 2026' },
  { value: 'm/d/Y', label: '11/14/2026' },
  { value: 'd/m/Y', label: '14/11/2026' },
  { value: 'Y-m-d', label: '2026-11-14' },
]

export const TIME_FORMATS: Array<{ value: string; label: string }> = [
  { value: 'h:i A', label: '02:30 PM (12-hour)' },
  { value: 'H:i', label: '14:30 (24-hour)' },
]

export const RECEIPT_LAYOUT_LABELS: Record<ReceiptLayoutKey, string> = {
  show_business_address: 'Business address',
  show_tin: 'TIN and VAT status',
  show_branch: 'Branch name',
  show_cashier: 'Cashier name',
  show_customer: 'Customer name',
  show_item_sku: 'Item SKU',
  show_tax_breakdown: 'Tax breakdown',
  show_payment_details: 'Payment details and change',
}

export const WEEKDAYS: Array<{ key: Weekday; label: string }> = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
]
