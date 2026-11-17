import { Input, Select } from '@/components/ui'
import { cn } from '@/lib/utils'
import type { DiscountDraft } from '../discounts'
import type { DiscountPresetOption } from '../types'

/** No discount / a preset / a manual percent or peso amount. */
export function DiscountPicker({ value, onChange, presets }: { value: DiscountDraft; onChange: (d: DiscountDraft) => void; presets: DiscountPresetOption[] }) {
  const modes: Array<{ key: DiscountDraft['mode']; label: string }> = [
    { key: 'none', label: 'None' },
    ...(presets.length ? [{ key: 'preset' as const, label: 'Preset' }] : []),
    { key: 'manual', label: 'Amount or %' },
  ]
  return (
    <div className="space-y-3">
      <div role="radiogroup" aria-label="Discount" className="flex gap-2">
        {modes.map((m) => (
          <button
            key={m.key}
            type="button"
            role="radio"
            aria-checked={value.mode === m.key}
            onClick={() =>
              onChange(m.key === 'none' ? { mode: 'none' } : m.key === 'preset' ? { mode: 'preset', presetId: presets[0]?.id ?? null } : { mode: 'manual', type: 'percent', value: '' })
            }
            className={cn(
              'rounded-md border px-3 py-1.5 text-sm',
              value.mode === m.key ? 'border-brand-500 bg-brand-100/50 font-medium text-brand-600' : 'border-line text-ink hover:border-brand-500',
            )}
          >
            {m.label}
          </button>
        ))}
      </div>
      {value.mode === 'preset' && (
        <Select aria-label="Discount preset" value={value.presetId ?? ''} onChange={(e) => onChange({ mode: 'preset', presetId: Number(e.target.value) || null })}>
          {presets.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name} ({p.type === 'percent' ? `${Number(p.value)}%` : `₱${p.value}`}){p.requires_permission ? ' · needs approval' : ''}
            </option>
          ))}
        </Select>
      )}
      {value.mode === 'manual' && (
        <div className="flex gap-2">
          <Select aria-label="Discount type" className="w-28" value={value.type} onChange={(e) => onChange({ ...value, type: e.target.value as 'percent' | 'fixed' })}>
            <option value="percent">%</option>
            <option value="fixed">₱</option>
          </Select>
          <Input
            aria-label="Discount value"
            inputMode="decimal"
            value={value.value}
            onChange={(e) => onChange({ ...value, value: e.target.value.replace(/[^\d.]/g, '') })}
            placeholder={value.type === 'percent' ? '10' : '0.00'}
            data-autofocus
          />
        </div>
      )}
    </div>
  )
}
