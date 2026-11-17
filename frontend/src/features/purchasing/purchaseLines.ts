import type { StockVariant } from '@/features/inventory/types'
import type { PurchasableItem, PurchaseUnit } from './types'

/** One editable purchase line (order or direct receipt). Everything stays a string. */
export interface PurchaseLine {
  key: string
  variant: StockVariant
  units: PurchaseUnit[]
  unitId: number | null
  qty: string
  unitCost: string
  lastCost: string | null
  onHand: string | null
  batchNo: string
  expiryDate: string
}

let seq = 0
export const newLineKey = () => `p${++seq}`

export const unitOf = (line: Pick<PurchaseLine, 'units' | 'unitId'>) => line.units.find((u) => u.product_unit_id === line.unitId) ?? line.units[0]

/** Client checks before sending (the API validates again). */
export function validatePurchaseLines(lines: PurchaseLine[], { requireExpiry = false } = {}): Record<string, string> {
  const errors: Record<string, string> = {}
  if (lines.length === 0) errors.items = 'Add at least one item.'
  lines.forEach((l, i) => {
    const wholeOnly = l.unitId !== null || !l.variant.allows_decimal
    const qty = l.qty.trim()
    if (!/^\d+(\.\d{1,3})?$/.test(qty) || /^0*(\.0*)?$/.test(qty)) errors[`items.${i}.qty`] = 'Enter a quantity.'
    else if (wholeOnly && qty.includes('.') && !/\.0*$/.test(qty)) errors[`items.${i}.qty`] = 'Whole units only.'
    if (!/^\d+(\.\d{1,4})?$/.test(l.unitCost.trim())) errors[`items.${i}.unit_cost`] = 'Enter the cost, e.g. 224.00'
    if (requireExpiry && l.variant.track_expiry && !l.expiryDate) errors[`items.${i}.expiry_date`] = 'Required.'
  })
  return errors
}

/** A new line for an item chosen in the picker (in the scanned unit, if a pack/box barcode was scanned). */
export function lineFromItem(item: PurchasableItem, qty = ''): PurchaseLine {
  return {
    key: newLineKey(),
    variant: item.variant,
    units: item.units,
    unitId: item.default_unit_id,
    qty,
    unitCost: '',
    lastCost: item.last_cost,
    onHand: item.qty_on_hand,
    batchNo: '',
    expiryDate: '',
  }
}
