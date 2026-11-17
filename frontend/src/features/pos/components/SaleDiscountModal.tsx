import { useState } from 'react'
import { Button, Modal } from '@/components/ui'
import { discountOf, draftOf, type DiscountDraft } from '../discounts'
import type { DiscountInput, DiscountPresetOption } from '../types'
import { DiscountPicker } from './DiscountPicker'

/** Discount on the whole sale (spread over the items; Senior/PWD items excluded). */
export function SaleDiscountModal({
  discount,
  presets,
  maxPercent,
  onSave,
  onClose,
}: {
  discount: DiscountInput | null
  presets: DiscountPresetOption[]
  maxPercent: string
  onSave: (d: DiscountInput | null) => void
  onClose: () => void
}) {
  const [draft, setDraft] = useState<DiscountDraft>(draftOf(discount))
  const [error, setError] = useState<string | null>(null)

  const save = () => {
    const d = discountOf(draft)
    if (d === undefined) {
      setError('Complete the discount (percent up to 100).')
      return
    }
    onSave(d)
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Sale discount"
      description={`Applies to the whole sale except Senior/PWD items. Above ${Number(maxPercent)}% needs a manager's approval.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save}>Apply</Button>
        </>
      }
    >
      <form
        className="space-y-2"
        onSubmit={(e) => {
          e.preventDefault()
          save()
        }}
      >
        <DiscountPicker value={draft} onChange={setDraft} presets={presets.filter((p) => p.scope === 'transaction')} />
        {error && <p className="text-sm text-[#ff3e1d]">{error}</p>}
        <button type="submit" hidden />
      </form>
    </Modal>
  )
}
