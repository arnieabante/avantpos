import { Trash2 } from 'lucide-react'
import { IconButton, Input, Select } from '@/components/ui'
import { formatQty } from '@/features/inventory/format'
import { trimDecimal } from '@/features/catalog/productForm'
import { multiplyToMoney } from '@/lib/decimal'
import { formatMoney } from '@/lib/format'
import { unitOf, type PurchaseLine } from '../purchaseLines'

interface PurchaseLinesTableProps {
  lines: PurchaseLine[]
  errors: Record<string, string>
  onChange: (key: string, patch: Partial<PurchaseLine>, index: number) => void
  onRemove: (key: string) => void
  /** Show batch / expiry columns (expiry-tracked items, plan with batches). */
  showBatch?: boolean
  batchesEnabled?: boolean
}

/** The line grid shared by the order editor and the direct-receipt form. */
export function PurchaseLinesTable({ lines, errors, onChange, onRemove, showBatch = false, batchesEnabled = false }: PurchaseLinesTableProps) {
  if (lines.length === 0) return null

  return (
    <div className="-mx-6 overflow-x-auto">
      <table className="w-full min-w-[46rem] text-sm">
        <thead>
          <tr className="border-y border-line-soft text-left text-xs font-medium tracking-wide text-ink-muted uppercase">
            <th className="px-6 py-3">Item</th>
            <th className="w-36 px-3 py-3">Unit</th>
            <th className="w-28 px-3 py-3">Qty</th>
            <th className="w-36 px-3 py-3">Cost per unit</th>
            {showBatch && <th className="w-32 px-3 py-3">Batch</th>}
            {showBatch && <th className="w-40 px-3 py-3">Expiry</th>}
            <th className="w-32 px-3 py-3 text-right">Line total</th>
            <th className="w-12 px-3 py-3" />
          </tr>
        </thead>
        <tbody className="divide-y divide-line-soft">
          {lines.map((l, i) => {
            const err = (f: string) => errors[`items.${i}.${f}`]
            const unit = unitOf(l)
            const estimate = multiplyToMoney(l.qty, l.unitCost)
            const tracks = batchesEnabled && l.variant.track_expiry
            return (
              <tr key={l.key} className="align-top">
                <td className="px-6 py-3">
                  <p className="font-medium text-ink">{l.variant.name}</p>
                  <p className="text-xs text-ink-subtle">
                    {l.variant.sku}
                    {l.onHand !== null && ` · ${formatQty(l.onHand, l.variant.unit)} on hand`}
                  </p>
                  {err('product_variant_id') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('product_variant_id')}</p>}
                </td>
                <td className="px-3 py-3">
                  {l.units.length > 1 ? (
                    <Select
                      aria-label={`Unit of ${l.variant.name}`}
                      value={String(l.unitId ?? '')}
                      onChange={(e) => onChange(l.key, { unitId: e.target.value ? Number(e.target.value) : null }, i)}
                    >
                      {l.units.map((u) => (
                        <option key={u.product_unit_id ?? 'base'} value={u.product_unit_id ?? ''}>
                          {u.product_unit_id ? `${u.name} (${trimDecimal(u.factor)} ${l.variant.unit})` : u.name}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <p className="pt-2 text-ink">{unit?.name}</p>
                  )}
                  {err('product_unit_id') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('product_unit_id')}</p>}
                </td>
                <td className="px-3 py-3">
                  <Input aria-label={`Quantity of ${l.variant.name}`} inputMode="decimal" value={l.qty} aria-invalid={!!err('qty')} onChange={(e) => onChange(l.key, { qty: e.target.value }, i)} />
                  {err('qty') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('qty')}</p>}
                </td>
                <td className="px-3 py-3">
                  <Input aria-label={`Cost of ${l.variant.name}`} inputMode="decimal" leftAddon="₱" value={l.unitCost} aria-invalid={!!err('unit_cost')} onChange={(e) => onChange(l.key, { unitCost: e.target.value }, i)} />
                  {err('unit_cost') ? (
                    <p className="mt-1 text-xs text-[#ff3e1d]">{err('unit_cost')}</p>
                  ) : (
                    l.lastCost && !/^0*(\.0*)?$/.test(l.lastCost) && <p className="mt-1 text-xs text-ink-subtle">Last: {formatMoney(l.lastCost)}/{l.variant.unit} net</p>
                  )}
                </td>
                {showBatch && (
                  <td className="px-3 py-3">
                    {tracks ? <Input aria-label={`Batch of ${l.variant.name}`} value={l.batchNo} maxLength={60} onChange={(e) => onChange(l.key, { batchNo: e.target.value }, i)} /> : <span className="text-ink-subtle">—</span>}
                  </td>
                )}
                {showBatch && (
                  <td className="px-3 py-3">
                    {tracks ? (
                      <>
                        <Input type="date" aria-label={`Expiry of ${l.variant.name}`} value={l.expiryDate} aria-invalid={!!err('expiry_date')} onChange={(e) => onChange(l.key, { expiryDate: e.target.value }, i)} />
                        {err('expiry_date') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('expiry_date')}</p>}
                      </>
                    ) : (
                      <span className="text-ink-subtle">—</span>
                    )}
                  </td>
                )}
                <td className="px-3 py-3 pt-5 text-right whitespace-nowrap text-ink">{estimate ? formatMoney(estimate) : '—'}</td>
                <td className="px-3 py-3">
                  <IconButton label={`Remove ${l.variant.name}`} onClick={() => onRemove(l.key)}>
                    <Trash2 className="size-4" />
                  </IconButton>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
