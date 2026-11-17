import { trimQty } from '@/lib/decimal'
import { formatDate, formatMoney } from '@/lib/format'
import type { PurchaseOrder } from '../types'

const day = (d: string | null) => (d ? formatDate(`${d.slice(0, 10)}T00:00:00+08:00`) : '—')

/** A purchase order laid out for A4 paper, to send to the supplier. */
export function PrintablePurchaseOrder({ order, businessName, vatRegistered }: { order: PurchaseOrder; businessName: string; vatRegistered: boolean }) {
  const items = order.items ?? []
  return (
    <div className="bg-white p-2 font-sans text-[11pt] leading-snug text-black" data-testid="printable-po">
      <div className="flex items-start justify-between border-b-2 border-black pb-3">
        <div>
          <p className="text-xl font-bold uppercase">{businessName}</p>
          <p>Deliver to: {order.branch?.name ?? '—'}</p>
        </div>
        <div className="text-right">
          <p className="text-2xl font-bold">PURCHASE ORDER</p>
          <p>No. {order.number}</p>
          <p>Date: {day(order.order_date)}</p>
          {order.expected_date && <p>Expected: {day(order.expected_date)}</p>}
        </div>
      </div>

      <div className="mt-3">
        <p className="text-xs font-bold uppercase">Supplier</p>
        <p className="text-base font-semibold">{order.supplier?.name}</p>
      </div>

      <table className="mt-4 w-full border-collapse text-[10pt]">
        <thead>
          <tr className="border-y border-black text-left">
            <th className="py-1.5 pr-2">#</th>
            <th className="py-1.5 pr-2">Item</th>
            <th className="py-1.5 pr-2">SKU</th>
            <th className="py-1.5 pr-2 text-right">Qty</th>
            <th className="py-1.5 pr-2">Unit</th>
            <th className="py-1.5 pr-2 text-right">Unit cost</th>
            <th className="py-1.5 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          {items.map((i, n) => (
            <tr key={i.id} className="border-b border-gray-300 align-top">
              <td className="py-1.5 pr-2">{n + 1}</td>
              <td className="py-1.5 pr-2">{i.variant.name}</td>
              <td className="py-1.5 pr-2">{i.variant.sku}</td>
              <td className="py-1.5 pr-2 text-right">{trimQty(i.qty_ordered)}</td>
              <td className="py-1.5 pr-2">{i.unit.abbreviation ?? i.unit.name}</td>
              <td className="py-1.5 pr-2 text-right">{formatMoney(i.unit_cost)}</td>
              <td className="py-1.5 text-right">{formatMoney(i.line_total)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="mt-3 ml-auto w-72 space-y-0.5">
        {vatRegistered && order.supplier?.vat_registered && (
          <>
            <div className="flex justify-between">
              <span>Net of VAT</span>
              <span>{formatMoney(order.subtotal)}</span>
            </div>
            <div className="flex justify-between">
              <span>VAT</span>
              <span>{formatMoney(order.tax_total)}</span>
            </div>
          </>
        )}
        <div className="flex justify-between border-t border-black pt-1 text-base font-bold">
          <span>TOTAL</span>
          <span>{formatMoney(order.total)}</span>
        </div>
      </div>

      {order.notes && (
        <div className="mt-4">
          <p className="text-xs font-bold uppercase">Notes</p>
          <p className="whitespace-pre-line">{order.notes}</p>
        </div>
      )}

      <div className="mt-16 grid grid-cols-2 gap-12 text-center text-[10pt]">
        <div className="border-t border-black pt-1">
          Prepared by: {order.created_by?.name ?? ''}
        </div>
        <div className="border-t border-black pt-1">
          Approved by: {order.approved_by?.name ?? ''}
        </div>
      </div>
    </div>
  )
}
