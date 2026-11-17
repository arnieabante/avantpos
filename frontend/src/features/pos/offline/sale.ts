import type { Cart } from '../cart'
import type { Tender } from '../payments'
import type { PosSetup, Quote, Sale } from '../types'
import type { QueuedSale } from './store'

const cents = (v: string): bigint => {
  const [w = '0', f = ''] = v.trim().split('.')
  return BigInt(w) * 100n + BigInt((f + '00').slice(0, 2))
}
const money = (c: bigint) => `${c / 100n}.${(c % 100n).toString().padStart(2, '0')}`

/**
 * An offline sale: what is queued for upload, and the invoice as printed.
 * Payments follow the server's rules: non-cash first, the change comes out
 * of the first cash payment.
 */
export function buildOfflineSale(args: {
  clientUuid: string
  invoiceNo: string
  sessionId: number
  cart: Cart
  quote: Quote
  tenders: Tender[]
  setup: PosSetup
  cashier: { id: number; name: string }
  pricingVersion: string | null
  now?: Date
}): QueuedSale {
  const { quote, tenders, setup } = args
  const soldAt = (args.now ?? new Date()).toISOString()
  const total = cents(quote.totals.total)
  const nonCash = tenders.filter((t) => t.method.type !== 'cash').reduce((s, t) => s + cents(t.amount), 0n)
  const cashTendered = tenders.filter((t) => t.method.type === 'cash').reduce((s, t) => s + cents(t.amount), 0n)
  const change = nonCash + cashTendered > total ? nonCash + cashTendered - total : 0n

  let firstCash = true
  const payments: Sale['payments'] = tenders.map((t) => {
    const amount = cents(t.amount)
    if (t.method.type !== 'cash') {
      return { method: t.method.name, type: t.method.type, amount: money(amount), tendered: null, change: '0.00', reference_no: t.reference.trim() || null }
    }
    const isFirst = firstCash
    firstCash = false
    return {
      method: t.method.name,
      type: 'cash',
      amount: money(isFirst ? amount - change : amount),
      tendered: money(amount),
      change: money(isFirst ? change : 0n),
      reference_no: null,
    }
  })

  const receipt: Sale = {
    id: 0,
    invoice_no: args.invoiceNo,
    status: 'completed',
    sold_at: soldAt,
    branch: { id: setup.branch.id, name: setup.branch.name },
    terminal: setup.terminal
      ? { id: setup.terminal.id, code: setup.terminal.code, name: setup.terminal.name, min: setup.terminal.min, serial_no: setup.terminal.serial_no, ptu_no: setup.terminal.ptu_no, ptu_issued_at: setup.terminal.ptu_issued_at }
      : null,
    cashier: args.cashier,
    customer: null,
    totals: { ...quote.totals, amount_paid: money(nonCash + cashTendered), change: money(change) },
    points: { redeemed: '0.00', earned: '0.00' },
    items: quote.lines.map((l, i) => ({
      id: i + 1,
      product_variant_id: l.product_variant_id,
      name: l.name,
      sku: l.sku,
      unit: l.unit,
      qty: l.qty,
      unit_price: l.unit_price,
      line_discount: '0.00',
      allocated_discount: '0.00',
      sc_pwd_discount: '0.00',
      tax_class: l.tax_class,
      vat_amount: l.vat_amount,
      line_total: l.line_total,
      qty_refunded: '0',
    })),
    discounts: [],
    payments,
    notes: null,
    origin: 'offline',
  }

  return {
    client_uuid: args.clientUuid,
    invoice_no: args.invoiceNo,
    sold_at: soldAt,
    cash_register_session_id: args.sessionId,
    total: quote.totals.total,
    pricing_version: args.pricingVersion,
    items: args.cart.lines.map((l, i) => ({
      product_variant_id: l.item.product_variant_id,
      product_unit_id: l.unitId,
      qty: l.qty,
      unit_price: quote.lines[i]!.unit_price,
    })),
    payments: tenders.map((t) => ({ payment_method_id: t.method.id, amount: t.amount.trim(), reference_no: t.reference.trim() || null })),
    receipt,
  }
}
