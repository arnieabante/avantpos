import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { ArrowLeft, Trash2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  CardBody,
  CardHeader,
  DataTable,
  DetailList,
  FormField,
  IconButton,
  Input,
  PageHeader,
  PageLoader,
  Select,
  Textarea,
  useConfirm,
  useToast,
  type Column,
} from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { trimDecimal } from '@/features/catalog/productForm'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { inventoryApi, inventoryKeys, type AdjustmentPayload } from '../api'
import { ItemPicker } from '../components/ItemPicker'
import { StatusBadge } from '../components/StatusBadge'
import { formatQty, formatSignedQty, isNegative, lineDirection, qtyError } from '../format'
import { ADJUSTMENT_REASONS, IN_REASONS, OUT_REASONS, REASON_LABELS, type AdjustmentReason, type PickerItem, type StockAdjustment, type StockVariant } from '../types'

interface Line {
  key: string
  variant: StockVariant
  onHand: string | null
  currentCost?: string
  qty: string
  unitCost: string
  batchNo: string
  expiryDate: string
}

let lineSeq = 0
const lineFrom = (variant: StockVariant, onHand: string | null, currentCost?: string): Line => ({
  key: `l${++lineSeq}`,
  variant,
  onHand,
  currentCost,
  qty: '',
  unitCost: '',
  batchNo: '',
  expiryDate: '',
})

/** /inventory/adjustments/new and /inventory/adjustments/:adjustmentId */
export default function AdjustmentPage() {
  const { adjustmentId } = useParams()
  const isNew = adjustmentId === 'new'
  const id = Number(adjustmentId)
  const { can, activeBranch } = useAuth()
  const [params] = useSearchParams()
  const prefillId = Number(params.get('item')) || null

  const adjustment = useQuery({ queryKey: inventoryKeys.adjustment(id), queryFn: () => inventoryApi.adjustment(id), enabled: !isNew && Number.isFinite(id) })
  const prefill = useQuery({ queryKey: inventoryKeys.item(prefillId ?? 0), queryFn: () => inventoryApi.item(prefillId!), enabled: isNew && prefillId !== null })

  if ((!isNew && adjustment.isPending) || (isNew && prefillId !== null && prefill.isPending)) return <PageLoader />
  if (adjustment.isError) return <ApiErrorAlert error={ApiError.from(adjustment.error)} />

  const doc = adjustment.data ?? null
  const editable = can('inventory.adjust') && (isNew || doc?.status === 'draft')

  if (!editable && doc) return <AdjustmentView adjustment={doc} />

  const initialLines = doc
    ? (doc.items ?? []).map((i) => ({
        ...lineFrom(i.variant, null),
        qty: OUT_REASONS.includes(doc.reason_code) || IN_REASONS.includes(doc.reason_code) ? i.qty.replace('-', '') : i.qty,
        unitCost: i.unit_cost ?? '',
        batchNo: i.batch_no ?? '',
        expiryDate: i.expiry_date ?? '',
      }))
    : prefill.data
      ? [lineFrom(prefill.data.variant, prefill.data.branches.find((b) => b.branch.id === activeBranch?.id)?.qty_on_hand ?? null)]
      : []

  return <AdjustmentEditor key={doc?.id ?? 'new'} adjustment={doc} initialLines={initialLines} />
}

/* ------------------------------------------------------------------ Editor */

function AdjustmentEditor({ adjustment, initialLines }: { adjustment: StockAdjustment | null; initialLines: Line[] }) {
  const { can, activeBranch } = useAuth()
  const account = useAccount()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const canSeeCost = can('inventory.valuation')
  const batchesEnabled = Boolean(account.subscription?.plan.features?.batch_expiry)

  const [reason, setReason] = useState<AdjustmentReason>(adjustment?.reason_code ?? 'opening')
  const [notes, setNotes] = useState(adjustment?.notes ?? '')
  const [lines, setLines] = useState<Line[]>(initialLines)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<ApiError | null>(null)
  const [saving, setSaving] = useState<'draft' | 'post' | 'cancel' | null>(null)
  const [duplicate, setDuplicate] = useState<string | null>(null)

  const signed = reason === 'correction' || reason === 'other'
  const showBatch = batchesEnabled && lines.some((l) => l.variant.track_expiry)

  const update = (key: string, patch: Partial<Line>, index: number) => {
    setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)))
    setErrors((e) => Object.fromEntries(Object.entries(e).filter(([k]) => !k.startsWith(`items.${index}.`))))
  }

  const add = (item: PickerItem) => {
    if (!item.variant.track_expiry && lines.some((l) => l.variant.id === item.variant.id)) {
      setDuplicate(`${item.variant.name} is already listed.`)
      return
    }
    setDuplicate(null)
    setLines((all) => [...all, lineFrom(item.variant, item.qty_on_hand, item.current_cost)])
    setErrors((e) => ({ ...e, items: '' }))
  }

  const validate = (): Record<string, string> => {
    const found: Record<string, string> = {}
    if (lines.length === 0) found.items = 'Add at least one item.'
    lines.forEach((l, i) => {
      const qtyMessage = qtyError(l.qty, l.variant.allows_decimal, { allowNegative: signed })
      if (qtyMessage) found[`items.${i}.qty`] = qtyMessage
      if (l.unitCost.trim() && !/^\d+(\.\d{1,4})?$/.test(l.unitCost.trim())) found[`items.${i}.unit_cost`] = 'Enter an amount, e.g. 12.50'
      if (batchesEnabled && l.variant.track_expiry && lineDirection(reason, l.qty) === 'in' && !l.expiryDate) found[`items.${i}.expiry_date`] = 'Required for incoming stock.'
    })
    return found
  }

  const payload = (post: boolean): AdjustmentPayload => ({
    reason_code: reason,
    notes: notes.trim() || null,
    post,
    items: lines.map((l) => {
      const incoming = lineDirection(reason, l.qty) === 'in'
      const tracks = batchesEnabled && l.variant.track_expiry
      return {
        product_variant_id: l.variant.id,
        qty: l.qty.trim(),
        ...(canSeeCost && incoming ? { unit_cost: l.unitCost.trim() || null } : {}),
        ...(tracks ? { batch_no: l.batchNo.trim() || null, expiry_date: incoming ? l.expiryDate || null : null } : {}),
      }
    }),
  })

  const save = async (post: boolean) => {
    setFormError(null)
    const found = validate()
    setErrors(found)
    if (Object.values(found).some(Boolean)) return

    if (post) {
      const ok = await confirm({
        title: `Post ${adjustment?.number ?? 'this adjustment'}?`,
        description: 'Stock changes right away and the adjustment can no longer be edited. Mistakes are fixed with a new adjustment.',
        confirmLabel: 'Post adjustment',
      })
      if (!ok) return
    }

    setSaving(post ? 'post' : 'draft')
    try {
      const saved = await inventoryApi.saveAdjustment(payload(post), adjustment?.id)
      queryClient.setQueryData(inventoryKeys.adjustment(saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: inventoryKeys.all, predicate: (q) => q.queryKey[1] !== 'adjustment' })
      toast.success(post ? `${saved.number} posted. Stock is updated.` : `${saved.number} saved as draft.`)
      navigate(`/inventory/adjustments/${saved.id}`, { replace: true })
    } catch (error) {
      const apiError = ApiError.from(error)
      if (apiError.isValidation && Object.keys(apiError.errors).length > 0) {
        setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
        if (Object.keys(apiError.errors).some((k) => !k.startsWith('items'))) setFormError(apiError)
      } else {
        setFormError(apiError)
      }
    } finally {
      setSaving(null)
    }
  }

  const cancelDraft = async () => {
    if (!adjustment) return
    const ok = await confirm({ title: `Cancel ${adjustment.number}?`, description: 'The draft is kept for the record but can no longer be posted.', confirmLabel: 'Cancel draft', danger: true })
    if (!ok) return
    setSaving('cancel')
    try {
      const saved = await inventoryApi.adjustmentAction(adjustment.id, 'cancel')
      queryClient.setQueryData(inventoryKeys.adjustment(saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: inventoryKeys.all, predicate: (q) => q.queryKey[1] !== 'adjustment' })
      toast.success(`${saved.number} cancelled.`)
    } catch (error) {
      setFormError(ApiError.from(error))
    } finally {
      setSaving(null)
    }
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="space-y-6">
      <ButtonLink to="/inventory/adjustments" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Adjustments
      </ButtonLink>
      <PageHeader
        title={adjustment ? `Adjustment ${adjustment.number}` : 'New stock adjustment'}
        description={`Branch: ${adjustment?.branch?.name ?? activeBranch?.name ?? '—'}`}
        actions={adjustment && <StatusBadge status={adjustment.status} />}
      />

      <ApiErrorAlert error={formError} />

      <Card>
        <CardHeader title="Details" />
        <CardBody className="grid gap-4 md:grid-cols-2">
          <FormField label="Reason" required error={errors.reason_code} hint={reasonHint(reason)}>
            <Select value={reason} onChange={(e) => setReason(e.target.value as AdjustmentReason)}>
              {ADJUSTMENT_REASONS.map((r) => (
                <option key={r} value={r}>
                  {REASON_LABELS[r]}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Notes" error={errors.notes}>
            <Textarea rows={2} value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Rat damage in stock room" />
          </FormField>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Items" description={signed ? 'Type a positive number to add stock, or a negative number (e.g. -3) to remove it.' : undefined} />
        <CardBody className="space-y-4">
          <div>
            <ItemPicker onSelect={add} />
            {duplicate && <p className="mt-1 text-xs text-ink-subtle">{duplicate}</p>}
            {errors.items && <p className="mt-1 text-xs text-[#ff3e1d]">{errors.items}</p>}
          </div>

          {lines.length > 0 && (
            <div className="-mx-6 overflow-x-auto">
              <table className="w-full min-w-[40rem] text-sm">
                <thead>
                  <tr className="border-y border-line-soft text-left text-xs font-medium tracking-wide text-ink-muted uppercase">
                    <th className="px-6 py-3">Item</th>
                    <th className="px-3 py-3 text-right">On hand</th>
                    <th className="w-36 px-3 py-3">Quantity</th>
                    {canSeeCost && <th className="w-36 px-3 py-3">Unit cost</th>}
                    {showBatch && <th className="w-36 px-3 py-3">Batch</th>}
                    {showBatch && <th className="w-40 px-3 py-3">Expiry</th>}
                    <th className="w-12 px-3 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-line-soft">
                  {lines.map((l, i) => {
                    const dir = lineDirection(reason, l.qty)
                    const err = (f: string) => errors[`items.${i}.${f}`]
                    const tracks = batchesEnabled && l.variant.track_expiry
                    return (
                      <tr key={l.key} className="align-top">
                        <td className="px-6 py-3">
                          <p className="font-medium text-ink">{l.variant.name}</p>
                          <p className="text-xs text-ink-subtle">{l.variant.sku}</p>
                          {err('product_variant_id') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('product_variant_id')}</p>}
                        </td>
                        <td className="px-3 py-3 text-right whitespace-nowrap text-ink-muted">{l.onHand !== null ? formatQty(l.onHand, l.variant.unit) : '—'}</td>
                        <td className="px-3 py-3">
                          <Input
                            aria-label={`Quantity of ${l.variant.name}`}
                            inputMode="decimal"
                            value={l.qty}
                            onChange={(e) => update(l.key, { qty: e.target.value }, i)}
                            aria-invalid={!!err('qty')}
                            rightAddon={<span className="pr-2 text-xs text-ink-subtle">{l.variant.unit}</span>}
                          />
                          {err('qty') ? (
                            <p className="mt-1 text-xs text-[#ff3e1d]">{err('qty')}</p>
                          ) : (
                            dir && <p className={cn('mt-1 text-xs', dir === 'in' ? 'text-[#56ca00]' : 'text-[#ff3e1d]')}>{dir === 'in' ? 'Adds stock' : 'Removes stock'}</p>
                          )}
                        </td>
                        {canSeeCost && (
                          <td className="px-3 py-3">
                            {dir === 'out' ? (
                              <p className="pt-2 text-xs text-ink-subtle">FIFO cost</p>
                            ) : (
                              <>
                                <Input
                                  aria-label={`Unit cost of ${l.variant.name}`}
                                  inputMode="decimal"
                                  value={l.unitCost}
                                  placeholder={l.currentCost ? trimDecimal(l.currentCost) : '0.00'}
                                  onChange={(e) => update(l.key, { unitCost: e.target.value }, i)}
                                  aria-invalid={!!err('unit_cost')}
                                  leftAddon="₱"
                                />
                                {err('unit_cost') ? <p className="mt-1 text-xs text-[#ff3e1d]">{err('unit_cost')}</p> : !l.unitCost && <p className="mt-1 text-xs text-ink-subtle">Blank = current cost</p>}
                              </>
                            )}
                          </td>
                        )}
                        {showBatch && (
                          <td className="px-3 py-3">
                            {tracks ? (
                              <>
                                <Input aria-label={`Batch of ${l.variant.name}`} value={l.batchNo} maxLength={60} onChange={(e) => update(l.key, { batchNo: e.target.value }, i)} placeholder={dir === 'out' ? 'Any (FEFO)' : 'Optional'} />
                                {err('batch_no') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('batch_no')}</p>}
                              </>
                            ) : (
                              <span className="text-ink-subtle">—</span>
                            )}
                          </td>
                        )}
                        {showBatch && (
                          <td className="px-3 py-3">
                            {tracks && dir !== 'out' ? (
                              <>
                                <Input type="date" aria-label={`Expiry of ${l.variant.name}`} value={l.expiryDate} onChange={(e) => update(l.key, { expiryDate: e.target.value }, i)} aria-invalid={!!err('expiry_date')} />
                                {err('expiry_date') && <p className="mt-1 text-xs text-[#ff3e1d]">{err('expiry_date')}</p>}
                              </>
                            ) : (
                              <span className="text-ink-subtle">—</span>
                            )}
                          </td>
                        )}
                        <td className="px-3 py-3">
                          <IconButton
                            label={`Remove ${l.variant.name}`}
                            onClick={() => {
                              setLines((all) => all.filter((x) => x.key !== l.key))
                              setErrors({})
                            }}
                          >
                            <Trash2 className="size-4" />
                          </IconButton>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
          {reason === 'opening' && <Alert tone="info">Opening stock is the first stock of an item in this branch. For items that already have stock, use “Found” or “Correction”.</Alert>}
        </CardBody>
      </Card>

      <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
        {adjustment && (
          <Button variant="ghost" className="mr-auto text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={cancelDraft} loading={saving === 'cancel'} disabled={saving !== null}>
            Cancel draft
          </Button>
        )}
        <Button type="submit" variant="outline" loading={saving === 'draft'} disabled={saving !== null}>
          Save draft
        </Button>
        <Button onClick={() => void save(true)} loading={saving === 'post'} disabled={saving !== null}>
          Post adjustment
        </Button>
      </div>
    </form>
  )
}

function reasonHint(reason: AdjustmentReason): string {
  if (IN_REASONS.includes(reason)) return 'Adds stock.'
  if (OUT_REASONS.includes(reason)) return 'Removes stock at its FIFO cost.'
  return 'Adds or removes stock, depending on the sign you type.'
}

/* ------------------------------------------------------------------ Read-only */

function AdjustmentView({ adjustment }: { adjustment: StockAdjustment }) {
  const { can } = useAuth()
  const canSeeCost = can('inventory.valuation')
  const items = adjustment.items ?? []
  type Item = (typeof items)[number]
  const hasBatches = items.some((i) => i.batch_no || i.expiry_date)

  const columns: Column<Item>[] = [
    {
      key: 'item',
      header: 'Item',
      render: (i) => (
        <div>
          <p className="font-medium text-ink">{i.variant.name}</p>
          <p className="text-xs text-ink-subtle">{i.variant.sku}</p>
        </div>
      ),
    },
    {
      key: 'qty',
      header: 'Quantity',
      align: 'right',
      render: (i) => <span className={cn('font-medium', isNegative(i.qty) ? 'text-[#ff3e1d]' : 'text-[#56ca00]')}>{formatSignedQty(i.qty, i.variant.unit)}</span>,
    },
    ...(hasBatches
      ? [
          { key: 'batch', header: 'Batch', render: (i: Item) => i.batch_no ?? '—' },
          { key: 'expiry', header: 'Expiry', render: (i: Item) => formatDate(i.expiry_date) },
        ]
      : []),
    ...(canSeeCost
      ? [
          { key: 'cost', header: 'Unit cost', align: 'right' as const, render: (i: Item) => formatMoney(i.unit_cost) },
          { key: 'value', header: 'Value', align: 'right' as const, render: (i: Item) => formatMoney(i.value) },
        ]
      : []),
  ]

  return (
    <div className="space-y-6">
      <ButtonLink to="/inventory/adjustments" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Adjustments
      </ButtonLink>
      <PageHeader title={`Adjustment ${adjustment.number}`} description={REASON_LABELS[adjustment.reason_code]} actions={<StatusBadge status={adjustment.status} />} />
      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title="Items" />
          <DataTable columns={columns} rows={items} rowKey={(i) => i.id} caption="Adjustment items" />
          {canSeeCost && adjustment.value_change && (
            <div className="flex justify-end gap-4 border-t border-line-soft px-6 py-4 text-sm">
              <span className="text-ink-muted">Change in inventory value</span>
              <span className={cn('font-medium', isNegative(adjustment.value_change) ? 'text-[#ff3e1d]' : 'text-ink')}>{formatMoney(adjustment.value_change)}</span>
            </div>
          )}
        </Card>
        <Card>
          <CardHeader title="Details" />
          <CardBody>
            <DetailList
              items={[
                { label: 'Branch', value: adjustment.branch?.name ?? '—' },
                { label: 'Created', value: `${adjustment.created_by?.name ?? '—'} · ${formatDateTime(adjustment.created_at)}` },
                ...(adjustment.posted_at ? [{ label: 'Posted', value: `${adjustment.posted_by?.name ?? '—'} · ${formatDateTime(adjustment.posted_at)}` }] : []),
                { label: 'Notes', value: adjustment.notes ?? '—' },
              ]}
            />
            {adjustment.status === 'posted' && <Badge tone="neutral" className="mt-4">Posted adjustments are final</Badge>}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
