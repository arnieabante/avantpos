import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Input, Modal, useToast } from '@/components/ui'
import { useAccount } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatMoney } from '@/lib/format'
import { catalogApi, catalogKeys } from '../api'
import type { Variant } from '../types'

interface Row {
  branchId: number
  override: boolean
  selling: string
  wholesale: string
}

const MONEY = /^\d{1,13}(\.\d{1,2})?$/

/**
 * Per-branch prices for one variant. Branches without an override use the
 * product price. Saving replaces the whole set (audited server-side).
 */
export function BranchPricesModal({ variant, productId, onClose }: { variant: Variant; productId: number; onClose: () => void }) {
  const { branches } = useAccount()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [error, setError] = useState<ApiError | null>(null)
  const [saving, setSaving] = useState(false)
  const [rows, setRows] = useState<Row[]>(() =>
    branches.map((b) => {
      const existing = variant.branch_prices?.find((p) => p.branch_id === b.id)
      return { branchId: b.id, override: Boolean(existing), selling: existing?.selling_price ?? '', wholesale: existing?.wholesale_price ?? '' }
    }),
  )

  const update = (branchId: number, patch: Partial<Row>) => setRows((r) => r.map((row) => (row.branchId === branchId ? { ...row, ...patch } : row)))
  const invalid = rows.some((r) => r.override && (!MONEY.test(r.selling) || (r.wholesale !== '' && !MONEY.test(r.wholesale))))

  async function save() {
    setSaving(true)
    setError(null)
    try {
      await catalogApi.setBranchPrices(
        variant.id,
        rows.filter((r) => r.override).map((r) => ({ branch_id: r.branchId, selling_price: r.selling, wholesale_price: r.wholesale || null })),
      )
      await queryClient.invalidateQueries({ queryKey: catalogKeys.product(productId) })
      toast.success('Branch prices saved')
      onClose()
    } catch (e) {
      setError(ApiError.from(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!saving}
      size="lg"
      title={`Branch prices${variant.name ? ` – ${variant.name}` : ''}`}
      description={`Product price: ${formatMoney(variant.selling_price)}. Turn on a branch to give it its own price.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={save} loading={saving} disabled={invalid}>
            Save prices
          </Button>
        </>
      }
    >
      <ApiErrorAlert error={error} className="mb-4" />
      <div className="divide-y divide-line-soft">
        {rows.map((row) => {
          const branch = branches.find((b) => b.id === row.branchId)!
          return (
            <div key={row.branchId} className="grid items-center gap-3 py-3 sm:grid-cols-[1fr_9rem_9rem]">
              <label className="flex items-center gap-3 text-ink">
                <input type="checkbox" className="size-4 accent-brand-500" checked={row.override} onChange={(e) => update(row.branchId, { override: e.target.checked })} />
                <span>
                  {branch.name}
                  <span className="ml-1.5 text-xs text-ink-subtle">{branch.code}</span>
                </span>
              </label>
              <Input
                aria-label={`${branch.name} selling price`}
                leftAddon="₱"
                inputMode="decimal"
                placeholder={variant.selling_price}
                disabled={!row.override}
                value={row.override ? row.selling : ''}
                aria-invalid={row.override && !MONEY.test(row.selling) ? true : undefined}
                onChange={(e) => update(row.branchId, { selling: e.target.value })}
              />
              <Input
                aria-label={`${branch.name} wholesale price`}
                leftAddon="₱"
                inputMode="decimal"
                placeholder={variant.wholesale_price ?? 'Wholesale'}
                disabled={!row.override}
                value={row.override ? row.wholesale : ''}
                onChange={(e) => update(row.branchId, { wholesale: e.target.value })}
              />
            </div>
          )
        })}
      </div>
    </Modal>
  )
}
