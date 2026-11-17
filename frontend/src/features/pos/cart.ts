import type { Customer } from '@/features/customers/types'
import { addQty, compareDecimal, trimQty } from '@/lib/decimal'
import { uuid } from '@/lib/uuid'
import type { CartRequest, DiscountInput, PosItem, PosUnit, ScPwdType } from './types'

/**
 * The cart as the cashier builds it. It only holds what was entered
 * (items, units, quantities, requested price changes and discounts); every
 * amount shown comes from the server's quote.
 */
export interface CartLine {
  key: string
  item: PosItem
  unitId: number | null
  qty: string
  priceOverride: string | null
  discount: DiscountInput | null
  scPwd: boolean
}

export interface ScPwdDetails {
  type: ScPwdType
  name: string
  id_no: string
  tin: string
}

export interface Cart {
  lines: CartLine[]
  customer: Customer | null
  discount: DiscountInput | null
  scPwd: ScPwdDetails | null
  redeemPoints: number | null
  notes: string
}

export const emptyCart = (): Cart => ({ lines: [], customer: null, discount: null, scPwd: null, redeemPoints: null, notes: '' })

export function unitOf(line: Pick<CartLine, 'item' | 'unitId'>): PosUnit {
  return line.item.units.find((u) => u.id === line.unitId) ?? line.item.units[0]!
}

/**
 * Add an item. Rings up onto the last line of the same item and unit when
 * that line has no price change or discount (scanning twice = qty 2).
 */
export function addItem(cart: Cart, item: PosItem, unitId: number | null = null, qty = '1'): Cart {
  const last = cart.lines[cart.lines.length - 1]
  if (last && last.item.product_variant_id === item.product_variant_id && last.unitId === unitId && !last.priceOverride && !last.discount) {
    const merged = addQty(last.qty, qty) ?? last.qty
    return { ...cart, lines: [...cart.lines.slice(0, -1), { ...last, qty: trimQty(merged) }] }
  }
  const line: CartLine = {
    key: uuid(),
    item,
    unitId,
    qty: trimQty(qty),
    priceOverride: null,
    discount: null,
    // While a Senior/PWD sale is open, eligible items get the discount automatically.
    scPwd: cart.scPwd !== null && item.sc_pwd_eligible,
  }
  return { ...cart, lines: [...cart.lines, line] }
}

export function updateLine(cart: Cart, key: string, patch: Partial<Omit<CartLine, 'key' | 'item'>>): Cart {
  return { ...cart, lines: cart.lines.map((l) => (l.key === key ? { ...l, ...patch } : l)) }
}

export function removeLine(cart: Cart, key: string): Cart {
  const lines = cart.lines.filter((l) => l.key !== key)
  // The Senior/PWD details only make sense while an eligible item is marked.
  const scPwd = lines.some((l) => l.scPwd) ? cart.scPwd : null
  return { ...cart, lines, scPwd }
}

/** Turn the Senior/PWD discount on (marks every eligible item, dropping their other discounts) or off. */
export function setScPwd(cart: Cart, details: ScPwdDetails | null): Cart {
  return {
    ...cart,
    scPwd: details,
    redeemPoints: details ? null : cart.redeemPoints,
    lines: cart.lines.map((l) =>
      details && l.item.sc_pwd_eligible ? { ...l, scPwd: true, discount: null } : details ? l : { ...l, scPwd: false },
    ),
  }
}

/** The request body the server prices. */
export function cartRequest(cart: Cart): CartRequest {
  const scPwd = cart.lines.some((l) => l.scPwd) ? cart.scPwd : null
  return {
    customer_id: cart.customer?.id ?? null,
    items: cart.lines.map((l) => ({
      product_variant_id: l.item.product_variant_id,
      product_unit_id: l.unitId,
      qty: l.qty,
      ...(l.priceOverride ? { unit_price: l.priceOverride } : {}),
      ...(l.scPwd ? { sc_pwd: true } : {}),
      ...(l.discount ? { discount: l.discount } : {}),
    })),
    discount: cart.discount,
    sc_pwd: scPwd ? { type: scPwd.type, name: scPwd.name.trim(), id_no: scPwd.id_no.trim(), tin: scPwd.tin.trim() || null } : null,
    redeem_points: cart.redeemPoints,
    notes: cart.notes.trim() || null,
  }
}

/** Problem with a typed quantity for this unit, or null when it is fine. */
export function qtyProblem(qty: string, allowsDecimal: boolean): string | null {
  const v = qty.trim()
  if (!/^\d+(\.\d{1,3})?$/.test(v) || compareDecimal(v, '0') !== 1) return 'Enter a quantity.'
  if (!allowsDecimal && v.includes('.') && !/\.0*$/.test(v)) return 'This unit is sold in whole numbers.'
  if (compareDecimal(v, '999999') === 1) return 'That quantity is too large.'
  return null
}

/** "3*4800016" → 3 × the item with code 4800016 (cashier shortcut). */
export function parseEntry(text: string): { qty: string; code: string } {
  const match = /^(\d+(?:\.\d{1,3})?)\s*[*xX]\s*(\S.*)$/.exec(text.trim())
  return match ? { qty: match[1]!, code: match[2]!.trim() } : { qty: '1', code: text.trim() }
}

/** Server field errors ("items.2.qty") → the cart line they belong to. */
export function lineErrors(errors: Record<string, string[]>, cart: Cart): { byLine: Record<string, string>; general: string[] } {
  const byLine: Record<string, string> = {}
  const general: string[] = []
  for (const [field, messages] of Object.entries(errors)) {
    const match = /^items\.(\d+)\./.exec(field)
    const line = match ? cart.lines[Number(match[1])] : undefined
    if (line) byLine[line.key] ??= messages[0] ?? ''
    else if (messages[0]) general.push(messages[0])
  }
  return { byLine, general }
}

/** What is kept when a cart is held: the cart as entered plus the item snapshots to show it again. */
export function heldCartPayload(cart: Cart, total: string | null) {
  return {
    ...cartRequest(cart),
    // The ID number is personal data: never kept in a held cart or in the browser.
    sc_pwd: null,
    lines: cart.lines.map(({ item, unitId, qty, priceOverride, discount, scPwd }) => ({ item, unitId, qty, priceOverride, discount, scPwd })),
    customer: cart.customer,
    sc_pwd_details: cart.scPwd ? { type: cart.scPwd.type, name: cart.scPwd.name, tin: cart.scPwd.tin } : null,
    preview: { total, names: cart.lines.slice(0, 3).map((l) => l.item.name) },
  }
}

/** Rebuild a cart from a held cart (the Senior/PWD ID number is never kept and must be entered again). */
export function restoreCart(saved: unknown): Cart {
  const data = (saved ?? {}) as Partial<ReturnType<typeof heldCartPayload>>
  const lines: CartLine[] = (data.lines ?? []).map((l) => ({ ...l, key: uuid() }))
  const sc = data.sc_pwd_details
  return {
    lines,
    customer: data.customer ?? null,
    discount: data.discount ?? null,
    scPwd: sc ? { type: sc.type, name: sc.name, id_no: '', tin: sc.tin ?? '' } : null,
    redeemPoints: data.redeem_points ?? null,
    notes: data.notes ?? '',
  }
}
