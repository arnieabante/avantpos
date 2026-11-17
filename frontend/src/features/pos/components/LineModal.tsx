import { useState } from 'react'
import { Minus, Plus, Trash2 } from 'lucide-react'
import { Button, Checkbox, FormField, IconButton, Input, Modal, Select } from '@/components/ui'
import { addQty, compareDecimal, trimQty } from '@/lib/decimal'
import { formatMoney } from '@/lib/format'
import { qtyProblem, unitOf, type CartLine } from '../cart'
import { discountOf, draftOf, type DiscountDraft } from '../discounts'
import type { DiscountPresetOption } from '../types'
import { DiscountPicker } from './DiscountPicker'

interface Props {
  line: CartLine
  presets: DiscountPresetOption[]
  scPwdActive: boolean
  onSave: (patch: Partial<Omit<CartLine, 'key' | 'item'>>) => void
  onRemove: () => void
  onClose: () => void
}

/** Change an item on the sale: quantity, unit, price, discount, Senior/PWD. */
export function LineModal({ line, presets, scPwdActive, onSave, onRemove, onClose }: Props) {
  const [qty, setQty] = useState(line.qty)
  const [unitId, setUnitId] = useState(line.unitId)
  const [price, setPrice] = useState(line.priceOverride ?? '')
  const [discount, setDiscount] = useState<DiscountDraft>(draftOf(line.discount))
  const [scPwd, setScPwd] = useState(line.scPwd)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const unit = unitOf({ item: line.item, unitId })
  const linePresets = presets.filter((p) => p.scope === 'line')

  const step = (delta: '1' | '-1') => {
    const next = delta === '1' ? addQty(qty || '0', '1') : addQty(qty || '0', '-1')
    if (next && compareDecimal(next, '0') === 1) setQty(trimQty(next))
  }

  const save = () => {
    const found: Record<string, string> = {}
    const q = qtyProblem(qty, unit.allows_decimal)
    if (q) found.qty = q
    const p = price.trim()
    if (p && !/^\d+(\.\d{1,2})?$/.test(p)) found.price = 'Enter a price, e.g. 25.00.'
    const d = scPwd ? null : discountOf(discount)
    if (d === undefined) found.discount = 'Complete the discount (percent up to 100).'
    setErrors(found)
    if (Object.keys(found).length) return
    onSave({
      qty: trimQty(qty.trim()),
      unitId,
      priceOverride: p && p !== unit.price ? p : null,
      discount: d ?? null,
      scPwd,
    })
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={line.item.name}
      description={`${line.item.sku} · ${formatMoney(unit.price)} per ${unit.name.toLowerCase()}`}
      footer={
        <>
          <Button variant="danger" className="mr-auto" leftIcon={<Trash2 className="size-4" />} onClick={onRemove}>
            Remove
          </Button>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save}>Save</Button>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save()
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Quantity" required error={errors.qty}>
            <div className="flex items-center gap-1">
              <IconButton label="One less" onClick={() => step('-1')}>
                <Minus className="size-4" />
              </IconButton>
              <Input value={qty} inputMode="decimal" className="text-center" onChange={(e) => setQty(e.target.value.replace(/[^\d.]/g, ''))} data-autofocus />
              <IconButton label="One more" onClick={() => step('1')}>
                <Plus className="size-4" />
              </IconButton>
            </div>
          </FormField>
          {line.item.units.length > 1 && (
            <FormField label="Unit">
              <Select
                value={unitId ?? ''}
                onChange={(e) => {
                  setUnitId(e.target.value ? Number(e.target.value) : null)
                  setPrice('')
                }}
              >
                {line.item.units.map((u) => (
                  <option key={u.id ?? 'base'} value={u.id ?? ''}>
                    {u.name} – {formatMoney(u.price)}
                  </option>
                ))}
              </Select>
            </FormField>
          )}
        </div>

        <FormField label="Price" hint={`List price ${formatMoney(unit.price)}. A different price needs approval.`} error={errors.price}>
          <Input value={price} inputMode="decimal" leftAddon="₱" placeholder={unit.price} onChange={(e) => setPrice(e.target.value.replace(/[^\d.]/g, ''))} />
        </FormField>

        {line.item.sc_pwd_eligible && scPwdActive && (
          <Checkbox
            label="Senior Citizen / PWD discount on this item"
            checked={scPwd}
            onChange={(e) => setScPwd(e.target.checked)}
          />
        )}

        {!scPwd && (
          <FormField label="Item discount" error={errors.discount}>
            <DiscountPicker value={discount} onChange={setDiscount} presets={linePresets} />
          </FormField>
        )}
      </form>
    </Modal>
  )
}
