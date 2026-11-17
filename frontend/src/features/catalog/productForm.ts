import type { ProductInput, UnitInput, VariantInput } from './api'
import type { Product } from './types'

/* Form-state helpers for the product form (no React here, so they are easy to test). */

export function emptyUnit(): UnitInput {
  return { unit_id: '', conversion_factor: '', selling_price: '', wholesale_price: '', is_sellable: true, is_purchasable: true, barcodes: [] }
}

export function emptyVariant(name = ''): VariantInput {
  return { name, sku: '', cost_price: '', selling_price: '', wholesale_price: '', reorder_level: '', is_active: true, barcodes: [], units: [] }
}

export function emptyProduct(baseUnitId: number | ''): ProductInput {
  return {
    name: '',
    description: '',
    category_id: '',
    brand_id: '',
    base_unit_id: baseUnitId,
    type: 'standard',
    tax_class: 'vatable',
    sc_pwd_eligible: true,
    track_inventory: true,
    track_expiry: false,
    allow_negative_stock: false,
    meta: { generic_name: '', requires_prescription: false },
    variants: [emptyVariant()],
  }
}

/** "24.000" → "24", "12.5000" → "12.5" (quantities and factors only; money keeps 2 decimals). */
export function trimDecimal(value: string | null | undefined): string {
  if (!value) return ''
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}

export function fromProduct(product: Product): ProductInput {
  return {
    name: product.name,
    description: product.description ?? '',
    category_id: product.category?.id ?? '',
    brand_id: product.brand?.id ?? '',
    base_unit_id: product.base_unit.id,
    type: product.type,
    tax_class: product.tax_class,
    sc_pwd_eligible: product.sc_pwd_eligible,
    track_inventory: product.track_inventory,
    track_expiry: product.track_expiry,
    allow_negative_stock: product.allow_negative_stock,
    meta: { generic_name: product.meta.generic_name ?? '', requires_prescription: Boolean(product.meta.requires_prescription) },
    variants: product.variants.map((v) => ({
      id: v.id,
      name: v.name ?? '',
      sku: v.sku,
      ...(v.cost_price !== undefined ? { cost_price: trimDecimal(v.cost_price) } : {}),
      selling_price: v.selling_price,
      wholesale_price: v.wholesale_price ?? '',
      reorder_level: trimDecimal(v.reorder_level),
      is_active: v.is_active,
      barcodes: v.barcodes,
      units: v.units.map((u) => ({
        unit_id: u.unit_id,
        conversion_factor: trimDecimal(u.conversion_factor),
        selling_price: u.selling_price ?? '',
        wholesale_price: u.wholesale_price ?? '',
        is_sellable: u.is_sellable,
        is_purchasable: u.is_purchasable,
        barcodes: u.barcodes,
      })),
    })),
  }
}

/** Client-side checks for instant feedback; the API validates everything again. */
export function validateProduct(v: ProductInput): Record<string, string | undefined> {
  const errors: Record<string, string | undefined> = {}
  const money = /^\d{1,13}(\.\d{1,2})?$/

  if (!v.name.trim()) errors.name = 'Enter the product name.'
  if (v.base_unit_id === '') errors.base_unit_id = 'Choose the unit you count this product in.'

  v.variants.forEach((variant, i) => {
    if (v.variants.length > 1 && !variant.name.trim()) errors[`variants.${i}.name`] = 'Name this variant.'
    if (!money.test(variant.selling_price)) errors[`variants.${i}.selling_price`] = 'Enter a price, e.g. 25 or 25.50.'
    if (variant.wholesale_price && !money.test(variant.wholesale_price)) errors[`variants.${i}.wholesale_price`] = 'Enter a price, e.g. 23.50.'
    variant.units.forEach((unit, u) => {
      if (unit.unit_id === '') errors[`variants.${i}.units.${u}.unit_id`] = 'Choose a unit.'
      if (!/^\d+(\.\d{1,4})?$/.test(unit.conversion_factor) || Number(unit.conversion_factor) <= 0)
        errors[`variants.${i}.units.${u}.conversion_factor`] = 'How many base units are in one?'
      if (unit.selling_price && !money.test(unit.selling_price)) errors[`variants.${i}.units.${u}.selling_price`] = 'Enter a price.'
    })
  })

  return errors
}
