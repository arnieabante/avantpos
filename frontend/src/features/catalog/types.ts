/** Payloads of the catalog API (categories, brands, units, products). */

export interface Category {
  id: number
  parent_id: number | null
  name: string
  color: string | null
  sort_order: number
  is_active: boolean
  products_count?: number
  children_count?: number
}

export interface Brand {
  id: number
  name: string
  products_count?: number
}

export interface Unit {
  id: number
  name: string
  abbreviation: string
  allows_decimal: boolean
}

export type ProductType = 'standard' | 'service'
export type TaxClass = 'vatable' | 'vat_exempt' | 'zero_rated'

export interface ProductUnitRow {
  id: number
  unit_id: number
  unit: { name: string; abbreviation: string }
  conversion_factor: string
  selling_price: string | null
  wholesale_price: string | null
  is_sellable: boolean
  is_purchasable: boolean
  barcodes: string[]
}

export interface BranchPrice {
  branch_id: number
  selling_price: string
  wholesale_price: string | null
}

export interface Variant {
  id: number
  name: string | null
  sku: string
  attributes: Record<string, string> | null
  /** Only present for users with products.view_cost. */
  cost_price?: string
  selling_price: string
  wholesale_price: string | null
  reorder_level: string
  is_default: boolean
  is_active: boolean
  barcodes: string[]
  units: ProductUnitRow[]
  branch_prices: BranchPrice[] | null
}

export interface Product {
  id: number
  name: string
  description: string | null
  type: ProductType
  tax_class: TaxClass
  sc_pwd_eligible: boolean
  track_inventory: boolean
  track_expiry: boolean
  allow_negative_stock: boolean
  is_active: boolean
  meta: { generic_name?: string; requires_prescription?: boolean }
  category: { id: number; name: string } | null
  brand: { id: number; name: string } | null
  base_unit: { id: number; name: string; abbreviation: string; allows_decimal: boolean }
  image_url: string | null
  variants: Variant[]
  created_at: string | null
  updated_at: string | null
}

export interface ProductListItem {
  id: number
  name: string
  type: ProductType
  is_active: boolean
  track_inventory: boolean
  image_url: string | null
  category: { id: number; name: string } | null
  brand: { id: number; name: string } | null
  base_unit: string | null
  sku: string | null
  variants_count: number
  price_min: string | null
  price_max: string | null
}

export const TAX_CLASS_LABELS: Record<TaxClass, string> = {
  vatable: 'VATable',
  vat_exempt: 'VAT-exempt',
  zero_rated: 'Zero-rated',
}
