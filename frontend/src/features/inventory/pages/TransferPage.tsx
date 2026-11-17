import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { ArrowLeft, ArrowRight, Trash2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Alert,
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
import { useAuth } from '@/features/auth/useAuth'
import { trimDecimal } from '@/features/catalog/productForm'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { inventoryApi, inventoryKeys } from '../api'
import { ItemPicker } from '../components/ItemPicker'
import { StatusBadge } from '../components/StatusBadge'
import { formatQty, isZeroQty, qtyError } from '../format'
import type { PickerItem, StockTransfer, StockVariant } from '../types'

interface Line {
  variant: StockVariant
  onHand: string | null
  qty: string
}

function useErrorState() {
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<ApiError | null>(null)
  const report = (error: unknown) => {
    const apiError = ApiError.from(error)
    if (apiError.isValidation && Object.keys(apiError.errors).length > 0) {
      setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
      if (Object.keys(apiError.errors).some((k) => !k.startsWith('items') && k !== 'to_branch_id')) setFormError(apiError)
    } else {
      setFormError(apiError)
    }
  }
  return { errors, setErrors, formError, setFormError, report }
}

/** /inventory/transfers/new and /inventory/transfers/:transferId */
export default function TransferPage() {
  const { transferId } = useParams()
  const isNew = transferId === 'new'
  const id = Number(transferId)
  const { can } = useAuth()

  const transfer = useQuery({ queryKey: inventoryKeys.transfer(id), queryFn: () => inventoryApi.transfer(id), enabled: !isNew && Number.isFinite(id) })

  if (!isNew && transfer.isPending) return <PageLoader />
  if (transfer.isError) return <ApiErrorAlert error={ApiError.from(transfer.error)} />

  const doc = transfer.data ?? null
  if (isNew ? can('inventory.transfer') : doc?.can.edit) return <TransferEditor key={doc?.id ?? 'new'} transfer={doc} />
  return <TransferView key={`${doc!.id}-${doc!.status}`} transfer={doc!} />
}

/* ------------------------------------------------------------------ Draft editor */

function TransferEditor({ transfer }: { transfer: StockTransfer | null }) {
  const { activeBranch } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const destinations = useQuery({ queryKey: inventoryKeys.destinations, queryFn: inventoryApi.destinations })

  const [toBranch, setToBranch] = useState<number | ''>(transfer?.to_branch?.id ?? '')
  const [notes, setNotes] = useState(transfer?.notes ?? '')
  const [lines, setLines] = useState<Line[]>((transfer?.items ?? []).map((i) => ({ variant: i.variant, onHand: null, qty: trimDecimal(i.qty_sent) })))
  const [duplicate, setDuplicate] = useState<string | null>(null)
  const [saving, setSaving] = useState<'draft' | 'send' | 'cancel' | null>(null)
  const { errors, setErrors, formError, setFormError, report } = useErrorState()

  const add = (item: PickerItem) => {
    if (lines.some((l) => l.variant.id === item.variant.id)) {
      setDuplicate(`${item.variant.name} is already listed.`)
      return
    }
    setDuplicate(null)
    setLines((all) => [...all, { variant: item.variant, onHand: item.qty_on_hand, qty: '' }])
  }

  const save = async (dispatch: boolean) => {
    setFormError(null)
    const found: Record<string, string> = {}
    if (toBranch === '') found.to_branch_id = 'Choose the receiving branch.'
    if (lines.length === 0) found.items = 'Add at least one item.'
    lines.forEach((l, i) => {
      const message = qtyError(l.qty, l.variant.allows_decimal)
      if (message) found[`items.${i}.qty`] = message
    })
    setErrors(found)
    if (Object.keys(found).length > 0) return

    const destination = destinations.data?.find((b) => b.id === toBranch)?.name ?? 'the other branch'
    if (dispatch) {
      const ok = await confirm({
        title: `Send stock to ${destination}?`,
        description: `The items leave ${activeBranch?.name ?? 'this branch'} now and show as “in transit” until ${destination} receives them.`,
        confirmLabel: 'Send now',
      })
      if (!ok) return
    }

    setSaving(dispatch ? 'send' : 'draft')
    try {
      const saved = await inventoryApi.saveTransfer(
        { to_branch_id: Number(toBranch), notes: notes.trim() || null, dispatch, items: lines.map((l) => ({ product_variant_id: l.variant.id, qty: l.qty.trim() })) },
        transfer?.id,
      )
      queryClient.setQueryData(inventoryKeys.transfer(saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: inventoryKeys.all, predicate: (q) => q.queryKey[1] !== 'transfer' })
      toast.success(dispatch ? `${saved.number} sent to ${destination}.` : `${saved.number} saved as draft.`)
      navigate(`/inventory/transfers/${saved.id}`, { replace: true })
    } catch (error) {
      report(error)
    } finally {
      setSaving(null)
    }
  }

  const cancelDraft = async () => {
    if (!transfer) return
    if (!(await confirm({ title: `Cancel ${transfer.number}?`, confirmLabel: 'Cancel transfer', danger: true }))) return
    setSaving('cancel')
    try {
      const saved = await inventoryApi.transferAction(transfer.id, 'cancel')
      queryClient.setQueryData(inventoryKeys.transfer(saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: inventoryKeys.all, predicate: (q) => q.queryKey[1] !== 'transfer' })
      toast.success(`${saved.number} cancelled.`)
    } catch (error) {
      report(error)
    } finally {
      setSaving(null)
    }
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="space-y-6">
      <ButtonLink to="/inventory/transfers" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Transfers
      </ButtonLink>
      <PageHeader title={transfer ? `Transfer ${transfer.number}` : 'New stock transfer'} description={`From ${activeBranch?.name ?? '—'}`} actions={transfer && <StatusBadge status={transfer.status} />} />
      <ApiErrorAlert error={formError} />

      <Card>
        <CardHeader title="Details" />
        <CardBody className="grid gap-4 md:grid-cols-2">
          <FormField label="Send to" required error={errors.to_branch_id}>
            <Select value={String(toBranch)} onChange={(e) => setToBranch(e.target.value ? Number(e.target.value) : '')} disabled={destinations.isPending}>
              <option value="">Choose a branch…</option>
              {(destinations.data ?? []).map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Notes">
            <Textarea rows={2} value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Restock for weekend sale" />
          </FormField>
          {destinations.data?.length === 0 && <Alert tone="info" className="md:col-span-2">There is no other active branch to send stock to.</Alert>}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Items" />
        <CardBody className="space-y-4">
          <div>
            <ItemPicker onSelect={add} />
            {duplicate && <p className="mt-1 text-xs text-ink-subtle">{duplicate}</p>}
            {errors.items && <p className="mt-1 text-xs text-[#ff3e1d]">{errors.items}</p>}
          </div>
          {lines.length > 0 && (
            <div className="-mx-6 overflow-x-auto">
              <table className="w-full min-w-[32rem] text-sm">
                <thead>
                  <tr className="border-y border-line-soft text-left text-xs font-medium tracking-wide text-ink-muted uppercase">
                    <th className="px-6 py-3">Item</th>
                    <th className="px-3 py-3 text-right">On hand</th>
                    <th className="w-40 px-3 py-3">Send</th>
                    <th className="w-12 px-3 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-line-soft">
                  {lines.map((l, i) => (
                    <tr key={l.variant.id} className="align-top">
                      <td className="px-6 py-3">
                        <p className="font-medium text-ink">{l.variant.name}</p>
                        <p className="text-xs text-ink-subtle">{l.variant.sku}</p>
                        {errors[`items.${i}.product_variant_id`] && <p className="mt-1 text-xs text-[#ff3e1d]">{errors[`items.${i}.product_variant_id`]}</p>}
                      </td>
                      <td className="px-3 py-3 text-right whitespace-nowrap text-ink-muted">{l.onHand !== null ? formatQty(l.onHand, l.variant.unit) : '—'}</td>
                      <td className="px-3 py-3">
                        <Input
                          aria-label={`Quantity of ${l.variant.name}`}
                          inputMode="decimal"
                          value={l.qty}
                          aria-invalid={!!errors[`items.${i}.qty`]}
                          onChange={(e) => {
                            const qty = e.target.value
                            setLines((all) => all.map((x) => (x.variant.id === l.variant.id ? { ...x, qty } : x)))
                            setErrors((err) => ({ ...err, [`items.${i}.qty`]: '' }))
                          }}
                          rightAddon={<span className="pr-2 text-xs text-ink-subtle">{l.variant.unit}</span>}
                        />
                        {errors[`items.${i}.qty`] && <p className="mt-1 text-xs text-[#ff3e1d]">{errors[`items.${i}.qty`]}</p>}
                      </td>
                      <td className="px-3 py-3">
                        <IconButton label={`Remove ${l.variant.name}`} onClick={() => { setLines((all) => all.filter((x) => x.variant.id !== l.variant.id)); setErrors({}) }}>
                          <Trash2 className="size-4" />
                        </IconButton>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
        {transfer && (
          <Button variant="ghost" className="mr-auto text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={cancelDraft} loading={saving === 'cancel'} disabled={saving !== null}>
            Cancel transfer
          </Button>
        )}
        <Button type="submit" variant="outline" loading={saving === 'draft'} disabled={saving !== null}>
          Save draft
        </Button>
        <Button onClick={() => void save(true)} loading={saving === 'send'} disabled={saving !== null}>
          Send now
        </Button>
      </div>
    </form>
  )
}

/* ------------------------------------------------------------------ In transit / done */

function TransferView({ transfer }: { transfer: StockTransfer }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const canSeeCost = can('inventory.valuation')
  const items = transfer.items ?? []
  const receiving = transfer.can.receive
  const [received, setReceived] = useState<Record<number, string>>(() => Object.fromEntries(items.map((i) => [i.id, trimDecimal(i.qty_sent)])))
  const [busy, setBusy] = useState<'receive' | 'cancel' | null>(null)
  const { errors, setErrors, formError, setFormError, report } = useErrorState()
  type Item = (typeof items)[number]

  const finish = async (saved: StockTransfer, message: string) => {
    queryClient.setQueryData(inventoryKeys.transfer(saved.id), saved)
    await queryClient.invalidateQueries({ queryKey: inventoryKeys.all, predicate: (q) => q.queryKey[1] !== 'transfer' })
    toast.success(message)
  }

  const receive = async () => {
    setFormError(null)
    const found: Record<string, string> = {}
    items.forEach((i, index) => {
      const value = received[i.id] ?? ''
      if (!/^\d+(\.\d{1,3})?$/.test(value.trim())) found[`items.${index}.qty_received`] = 'Enter the quantity that arrived (0 if none).'
    })
    setErrors(found)
    if (Object.keys(found).length > 0) return

    const short = items.some((i) => received[i.id]?.trim() !== trimDecimal(i.qty_sent))
    const ok = await confirm({
      title: `Receive ${transfer.number}?`,
      description: short
        ? 'Some quantities are lower than what was sent. The difference is recorded as missing and does not enter your stock.'
        : 'The items are added to this branch’s stock.',
      confirmLabel: 'Receive stock',
    })
    if (!ok) return

    setBusy('receive')
    try {
      const saved = await inventoryApi.receiveTransfer(transfer.id, items.map((i) => ({ id: i.id, qty_received: (received[i.id] ?? '0').trim() })))
      await finish(saved, `${saved.number} received.`)
    } catch (error) {
      report(error)
    } finally {
      setBusy(null)
    }
  }

  const cancel = async () => {
    const ok = await confirm({
      title: `Cancel ${transfer.number}?`,
      description: transfer.status === 'in_transit' ? `The stock goes back to ${transfer.from_branch?.name}.` : undefined,
      confirmLabel: 'Cancel transfer',
      danger: true,
    })
    if (!ok) return
    setBusy('cancel')
    try {
      await finish(await inventoryApi.transferAction(transfer.id, 'cancel'), `${transfer.number} cancelled.`)
    } catch (error) {
      report(error)
    } finally {
      setBusy(null)
    }
  }

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
    { key: 'sent', header: 'Sent', align: 'right', render: (i) => formatQty(i.qty_sent, i.variant.unit) },
    ...(receiving
      ? [
          {
            key: 'receive',
            header: 'Received',
            className: 'w-40',
            render: (i: Item) => {
              const index = items.indexOf(i)
              const error = errors[`items.${index}.qty_received`]
              return (
                <div>
                  <Input
                    aria-label={`Received quantity of ${i.variant.name}`}
                    inputMode="decimal"
                    value={received[i.id] ?? ''}
                    aria-invalid={!!error}
                    onChange={(e) => {
                      const value = e.target.value
                      setReceived((r) => ({ ...r, [i.id]: value }))
                      setErrors((err) => ({ ...err, [`items.${index}.qty_received`]: '' }))
                    }}
                  />
                  {error && <p className="mt-1 text-xs text-[#ff3e1d]">{error}</p>}
                </div>
              )
            },
          },
        ]
      : transfer.status === 'received'
        ? [
            { key: 'received', header: 'Received', align: 'right' as const, render: (i: Item) => formatQty(i.qty_received, i.variant.unit) },
            {
              key: 'short',
              header: 'Missing',
              align: 'right' as const,
              render: (i: Item) => (isZeroQty(i.qty_short) ? '—' : <span className="font-medium text-[#ff3e1d]">{formatQty(i.qty_short, i.variant.unit)}</span>),
            },
          ]
        : []),
    ...(canSeeCost && transfer.status !== 'draft' ? [{ key: 'value', header: 'Value sent', align: 'right' as const, render: (i: Item) => formatMoney(i.value) }] : []),
  ]

  return (
    <div className="space-y-6">
      <ButtonLink to="/inventory/transfers" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Transfers
      </ButtonLink>
      <PageHeader
        title={`Transfer ${transfer.number}`}
        description={
          <span className="flex items-center gap-2">
            {transfer.from_branch?.name} <ArrowRight className="size-4" aria-label="to" /> {transfer.to_branch?.name}
          </span>
        }
        actions={<StatusBadge status={transfer.status} />}
      />
      <ApiErrorAlert error={formError} />
      {receiving && <Alert tone="info">Count what arrived and enter the quantities, then receive the transfer. Anything not received is recorded as missing.</Alert>}

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title="Items" />
          <DataTable columns={columns} rows={items} rowKey={(i) => i.id} caption="Transfer items" />
          {canSeeCost && transfer.value && (
            <div className="flex justify-end gap-4 border-t border-line-soft px-6 py-4 text-sm">
              <span className="text-ink-muted">Value at cost</span>
              <span className="font-medium text-ink">{formatMoney(transfer.value)}</span>
            </div>
          )}
        </Card>
        <Card>
          <CardHeader title="Details" />
          <CardBody>
            <DetailList
              items={[
                { label: 'Created', value: `${transfer.created_by?.name ?? '—'} · ${formatDateTime(transfer.created_at)}` },
                ...(transfer.dispatched_at ? [{ label: 'Sent', value: `${transfer.dispatched_by?.name ?? '—'} · ${formatDateTime(transfer.dispatched_at)}` }] : []),
                ...(transfer.received_at ? [{ label: 'Received', value: `${transfer.received_by?.name ?? '—'} · ${formatDateTime(transfer.received_at)}` }] : []),
                { label: 'Notes', value: transfer.notes ?? '—' },
              ]}
            />
          </CardBody>
        </Card>
      </div>

      {(receiving || transfer.can.cancel) && (
        <div className={cn('sticky bottom-0 z-20 -mx-4 flex flex-wrap justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6')}>
          {transfer.can.cancel && (
            <Button variant="ghost" className="mr-auto text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={cancel} loading={busy === 'cancel'} disabled={busy !== null}>
              Cancel transfer
            </Button>
          )}
          {receiving && (
            <Button onClick={receive} loading={busy === 'receive'} disabled={busy !== null}>
              Receive stock
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
