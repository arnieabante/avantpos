import { unitOf, type Cart } from '../cart'
import type { Quote, QuoteLine, TaxClass } from '../types'

/**
 * Prices a cart while offline, with the same arithmetic as the server's
 * SaleCalculator for a plain sale (no discounts):
 *   line amount = unit price × qty, rounded half-up to centavos
 *   VATable:  net = amount ÷ (1 + rate/100) rounded half-up, VAT = amount − net
 *   zero-rated / VAT-exempt: no VAT; a non-VAT business records non-VAT sales.
 * The server prices the sale again when it is uploaded and flags any difference.
 */

const toCents = (v: string): bigint => {
  const [w = '0', f = ''] = v.split('.')
  return BigInt(w) * 100n + BigInt((f + '00').slice(0, 2))
}
const toMilli = (v: string): bigint => {
  const [w = '0', f = ''] = v.split('.')
  return BigInt(w) * 1000n + BigInt((f + '000').slice(0, 3))
}
const money = (c: bigint): string => {
  const neg = c < 0n
  const a = neg ? -c : c
  return `${neg ? '-' : ''}${a / 100n}.${(a % 100n).toString().padStart(2, '0')}`
}
/** a ÷ b rounded half-up (a, b ≥ 0). */
const divRound = (a: bigint, b: bigint): bigint => (a * 2n + b) / (b * 2n)

/** What a cart uses that cannot be done offline (empty when it can be sold offline). */
export function offlineBlockers(cart: Cart): string[] {
  const out: string[] = []
  if (cart.customer) out.push('a customer')
  if (cart.discount || cart.lines.some((l) => l.discount)) out.push('discounts')
  if (cart.scPwd || cart.lines.some((l) => l.scPwd)) out.push('the Senior/PWD discount')
  if (cart.redeemPoints) out.push('points')
  if (cart.lines.some((l) => l.priceOverride)) out.push('price changes')
  return out
}

export function offlineQuote(cart: Cart, vatRate: string | null): Quote {
  // Divisor 1 + rate/100 to 6 decimals, as integers: 1_000_000 + rate × 10_000.
  const divisor = vatRate !== null ? 1_000_000n + toCents(vatRate) * 100n : null
  const totals = { gross: 0n, vatable: 0n, vat: 0n, exempt: 0n, zero: 0n }

  const lines: QuoteLine[] = cart.lines.map((line, index) => {
    const unit = unitOf(line)
    // price (2 dp) × qty (3 dp) → 5 dp; round half-up to centavos.
    const gross = divRound(toCents(unit.price) * toMilli(line.qty), 1000n)
    let vat = 0n
    let taxClass: TaxClass
    if (divisor !== null && line.item.tax_class === 'vatable') {
      const net = divRound(gross * 1_000_000n, divisor)
      vat = gross - net
      taxClass = 'vatable'
      totals.vatable += net
      totals.vat += vat
    } else if (divisor !== null && line.item.tax_class === 'zero_rated') {
      taxClass = 'zero_rated'
      totals.zero += gross
    } else {
      taxClass = divisor === null ? 'non_vat' : 'vat_exempt'
      totals.exempt += gross
    }
    totals.gross += gross

    return {
      index,
      product_variant_id: line.item.product_variant_id,
      product_unit_id: line.unitId,
      name: line.item.name,
      sku: line.item.sku,
      unit: unit.abbreviation,
      qty: line.qty,
      list_price: unit.price,
      unit_price: unit.price,
      price_overridden: false,
      gross: money(gross),
      line_discount: '0.00',
      allocated_discount: '0.00',
      sc_pwd: false,
      sc_pwd_discount: '0.00',
      tax_class: taxClass,
      vat_amount: money(vat),
      line_total: money(gross),
      sc_pwd_eligible: line.item.sc_pwd_eligible,
    }
  })

  return {
    lines,
    totals: {
      gross: money(totals.gross),
      line_discount: '0.00',
      transaction_discount: '0.00',
      sc_pwd_discount: '0.00',
      loyalty_discount: '0.00',
      vatable_sales: money(totals.vatable),
      vat_amount: money(totals.vat),
      vat_exempt_sales: money(totals.exempt),
      zero_rated_sales: money(totals.zero),
      total: money(totals.gross),
    },
    discounts: [],
    customer: null,
    points_redeemed: '0',
    vat_registered: vatRate !== null,
    approvals_required: [],
    approvals_missing: [],
  }
}
