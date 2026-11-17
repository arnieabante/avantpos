import { compareDecimal } from '@/lib/decimal'
import type { DiscountInput } from './types'

export type DiscountDraft = { mode: 'none' } | { mode: 'preset'; presetId: number | null } | { mode: 'manual'; type: 'percent' | 'fixed'; value: string }

export function draftOf(discount: DiscountInput | null): DiscountDraft {
  if (!discount) return { mode: 'none' }
  if ('preset_id' in discount) return { mode: 'preset', presetId: discount.preset_id }
  return { mode: 'manual', type: discount.type, value: discount.value }
}

/** Draft → request value; `undefined` means the draft is incomplete. */
export function discountOf(draft: DiscountDraft): DiscountInput | null | undefined {
  if (draft.mode === 'none') return null
  if (draft.mode === 'preset') return draft.presetId ? { preset_id: draft.presetId } : undefined
  const v = draft.value.trim()
  if (!/^\d+(\.\d{1,2})?$/.test(v) || compareDecimal(v, '0') !== 1) return undefined
  if (draft.type === 'percent' && compareDecimal(v, '100') === 1) return undefined
  return { type: draft.type, value: v }
}
