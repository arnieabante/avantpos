import { useState } from 'react'
import { Button, FormField, Input, Modal } from '@/components/ui'
import { cn } from '@/lib/utils'
import type { ScPwdDetails } from '../cart'

interface Props {
  details: ScPwdDetails | null
  /** Pre-filled from the customer when they are a registered Senior/PWD. */
  suggestedName?: string | null
  suggestedType?: ScPwdDetails['type'] | null
  eligibleCount: number
  onSave: (details: ScPwdDetails | null) => void
  onClose: () => void
}

/**
 * Senior Citizen (RA 9994) / PWD (RA 10754) discount: the ID details are
 * required for the BIR sales book. 20% off the VAT-exclusive price of the
 * eligible items, which also become VAT-exempt.
 */
export function ScPwdModal({ details, suggestedName, suggestedType, eligibleCount, onSave, onClose }: Props) {
  const [type, setType] = useState<ScPwdDetails['type']>(details?.type ?? suggestedType ?? 'senior')
  const [name, setName] = useState(details?.name ?? suggestedName ?? '')
  const [idNo, setIdNo] = useState(details?.id_no ?? '')
  const [tin, setTin] = useState(details?.tin ?? '')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = () => {
    const found: Record<string, string> = {}
    if (name.trim().length < 2) found.name = 'Enter the name on the ID.'
    if (idNo.trim().length < 3) found.id_no = 'Enter the ID number.'
    setErrors(found)
    if (Object.keys(found).length === 0) onSave({ type, name: name.trim(), id_no: idNo.trim(), tin: tin.trim() })
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title="Senior Citizen / PWD discount"
      description={
        eligibleCount > 0
          ? `20% off the VAT-exclusive price of ${eligibleCount} eligible item${eligibleCount === 1 ? '' : 's'}, VAT-exempt. Check the ID before applying.`
          : 'No item in the cart is eligible yet. Eligible items added later get the discount automatically.'
      }
      footer={
        <>
          {details && (
            <Button variant="ghost" className="mr-auto" onClick={() => onSave(null)}>
              Remove discount
            </Button>
          )}
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save}>Apply discount</Button>
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
        <div role="radiogroup" aria-label="Discount type" className="grid grid-cols-2 gap-2">
          {(['senior', 'pwd'] as const).map((t) => (
            <button
              key={t}
              type="button"
              role="radio"
              aria-checked={type === t}
              onClick={() => setType(t)}
              className={cn(
                'rounded-md border px-3 py-2 text-sm font-medium',
                type === t ? 'border-brand-500 bg-brand-100/50 text-brand-600' : 'border-line text-ink hover:border-brand-500',
              )}
            >
              {t === 'senior' ? 'Senior Citizen' : 'Person with disability'}
            </button>
          ))}
        </div>
        <FormField label="Name on the ID" required error={errors.name}>
          <Input value={name} maxLength={150} onChange={(e) => setName(e.target.value)} data-autofocus />
        </FormField>
        <FormField label={type === 'senior' ? 'OSCA / Senior Citizen ID number' : 'PWD ID number'} required error={errors.id_no}>
          <Input value={idNo} maxLength={40} autoComplete="off" onChange={(e) => setIdNo(e.target.value)} />
        </FormField>
        <FormField label="TIN" hint="Optional">
          <Input value={tin} maxLength={20} onChange={(e) => setTin(e.target.value)} />
        </FormField>
        <button type="submit" hidden />
      </form>
    </Modal>
  )
}
