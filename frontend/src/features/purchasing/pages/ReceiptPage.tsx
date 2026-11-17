import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { ArrowLeft } from 'lucide-react'
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
  Input,
  Modal,
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
import { inventoryKeys } from '@/features/inventory/api'
import { ItemPicker } from '@/features/inventory/components/ItemPicker'
import { StatusBadge } from '@/features/inventory/components/StatusBadge'
import { formatQty } from '@/features/inventory/format'
import { ApiError } from '@/lib/api/errors'
import { multiplyToMoney, sumMoney } from '@/lib/decimal'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { purchasingApi, purchasingKeys, type GoodsReceiptPayload } from '../api'
import { PurchaseLinesTable } from '../components/PurchaseLines'
import { lineFromItem, validatePurchaseLines, type PurchaseLine } from '../purchaseLines'
import type { GoodsReceipt, PurchasableItem, PurchaseOrder } from '../types'

const pickerSource = { key: 'purchasing', fetch: purchasingApi.searchItems }
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })

/** /purchasing/receipts/new[?po=ID] and /purchasing/receipts/:receiptId */
export default function ReceiptPage() {
  const { receiptId } = useParams()
  const isNew = receiptId === 'new'
  const id = Number(receiptId)
  const [params] = useSearchParams()
  const poId = Number(params.get('po')) || null

  const receipt = useQuery({ queryKey: purchasingKeys.receipt(id), queryFn: () => purchasingApi.receipt(id), enabled: !isNew && Number.isFinite(id) })
  const order = useQuery({ queryKey: purchasingKeys.order(poId ?? 0), queryFn: () => purchasingApi.order(poId!), enabled: isNew && poId !== null })

  if ((!isNew && receipt.isPending) || (isNew && poId !== null && order.isPending)) return <PageLoader />
  const failed = receipt.error ?? order.error
  if (failed) return <ApiErrorAlert error={ApiError.from(failed)} />

  if (!isNew) return <ReceiptView receipt={receipt.data!} />
  return order.data ? <ReceiveFromOrder order={order.data} /> : <ReceiveDirect />
}

/* ------------------------------------------------------------------ Shared header fields */

interface InvoiceFields {
  invoiceNo: string
  invoiceDate: string
  notes: string
}

function InvoiceCard({ value, onChange, errors, children }: { value: InvoiceFields; onChange: (v: InvoiceFields) => void; errors: Record<string, string>; children?: React.ReactNode }) {
  return (
    <Card>
      <CardHeader title="Delivery" />
      <CardBody className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {children}
        <FormField label="Supplier invoice / DR no." error={errors.supplier_invoice_no}>
          <Input value={value.invoiceNo} maxLength={60} onChange={(e) => onChange({ ...value, invoiceNo: e.target.value })} />
        </FormField>
        <FormField label="Invoice date" error={errors.supplier_invoice_date}>
          <Input type="date" value={value.invoiceDate} max={today()} onChange={(e) => onChange({ ...value, invoiceDate: e.target.value })} />
        </FormField>
        <FormField label="Notes" className="md:col-span-2 xl:col-span-4">
          <Textarea rows={2} value={value.notes} maxLength={2000} onChange={(e) => onChange({ ...value, notes: e.target.value })} />
        </FormField>
      </CardBody>
    </Card>
  )
}

function useReceiveSubmit() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<ApiError | null>(null)
  const [saving, setSaving] = useState(false)

  const submit = async (payload: GoodsReceiptPayload, summary: string) => {
    setFormError(null)
    if (!(await confirm({ title: 'Receive these goods?', description: `${summary} The stock is added right away.`, confirmLabel: 'Receive stock' }))) return
    setSaving(true)
    try {
      const saved = await purchasingApi.receive(payload)
      queryClient.setQueryData(purchasingKeys.receipt(saved.id), saved)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: purchasingKeys.all, predicate: (q) => q.queryKey[1] !== 'receipt' }),
        queryClient.invalidateQueries({ queryKey: inventoryKeys.all }),
      ])
      toast.success(`${saved.number} received. Stock is updated.`)
      navigate(`/purchasing/receipts/${saved.id}`, { replace: true })
    } catch (error) {
      const apiError = ApiError.from(error)
      if (apiError.isValidation && Object.keys(apiError.errors).length > 0) {
        setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
      } else {
        setFormError(apiError)
      }
    } finally {
      setSaving(false)
    }
  }

  return { errors, setErrors, formError, saving, submit }
}

/* ------------------------------------------------------------------ Against a PO */

interface OrderLine {
  poItemId: number
  name: string
  sku: string
  unit: string | null
  trackExpiry: boolean
  remaining: string
  qty: string
  unitCost: string
  batchNo: string
  expiryDate: string
}

function ReceiveFromOrder({ order }: { order: PurchaseOrder }) {
  const account = useAccount()
  const batchesEnabled = Boolean(account.subscription?.plan.features?.batch_expiry)
  const [invoice, setInvoice] = useState<InvoiceFields>({ invoiceNo: '', invoiceDate: '', notes: '' })
  const [lines, setLines] = useState<OrderLine[]>(
    (order.items ?? [])
      .filter((i) => !/^0*(\.0*)?$/.test(i.qty_remaining))
      .map((i) => ({
        poItemId: i.id,
        name: i.variant.name,
        sku: i.variant.sku,
        unit: i.unit.abbreviation,
        trackExpiry: i.variant.track_expiry,
        remaining: i.qty_remaining,
        qty: trimDecimal(i.qty_remaining),
        unitCost: trimDecimal(i.unit_cost),
        batchNo: '',
        expiryDate: '',
      })),
  )
  const { errors, setErrors, formError, saving, submit } = useReceiveSubmit()
  const showBatch = batchesEnabled && lines.some((l) => l.trackExpiry)

  if (!order.can.receive) {
    return <Alert tone="warning">Purchase order {order.number} cannot be received ({order.status.replace('_', ' ')}).</Alert>
  }

  // Lines left at 0 are simply not part of this delivery.
  const sent = lines.map((l, index) => ({ l, index })).filter(({ l }) => !/^0*(\.0*)?$/.test(l.qty.trim()))
  const set = (index: number, patch: Partial<OrderLine>) => {
    setLines((all) => all.map((l, i) => (i === index ? { ...l, ...patch } : l)))
    setErrors({})
  }

  const receive = () => {
    const found: Record<string, string> = {}
    if (sent.length === 0) found.items = 'Enter what arrived for at least one item.'
    lines.forEach((l, i) => {
      if (!/^\d+(\.\d{1,3})?$/.test(l.qty.trim())) found[`line.${i}.qty`] = 'Enter a quantity (0 if not delivered).'
      if (!/^\d+(\.\d{1,4})?$/.test(l.unitCost.trim())) found[`line.${i}.unit_cost`] = 'Enter the cost.'
      if (showBatch && l.trackExpiry && !/^0*(\.0*)?$/.test(l.qty.trim()) && !l.expiryDate) found[`line.${i}.expiry_date`] = 'Required.'
    })
    setErrors(found)
    if (Object.keys(found).length > 0) return

    const total = sumMoney(sent.map(({ l }) => multiplyToMoney(l.qty, l.unitCost)))
    void submit(
      {
        purchase_order_id: order.id,
        supplier_id: null,
        supplier_invoice_no: invoice.invoiceNo.trim() || null,
        supplier_invoice_date: invoice.invoiceDate || null,
        notes: invoice.notes.trim() || null,
        items: sent.map(({ l }) => ({
          purchase_order_item_id: l.poItemId,
          qty: l.qty.trim(),
          unit_cost: l.unitCost.trim(),
          ...(showBatch && l.trackExpiry ? { batch_no: l.batchNo.trim() || null, expiry_date: l.expiryDate || null } : {}),
        })),
      },
      `${sent.length} item${sent.length === 1 ? '' : 's'} from ${order.supplier?.name}, about ${formatMoney(total)}.`,
    )
  }

  // Server errors are keyed by position among the SENT lines; map them back.
  const err = (index: number, field: string) => {
    const sentIndex = sent.findIndex((s) => s.index === index)
    return errors[`line.${index}.${field}`] ?? (sentIndex >= 0 ? errors[`items.${sentIndex}.${field}`] : undefined)
  }

  return (
    <div className="space-y-6">
      <ButtonLink to={`/purchasing/orders/${order.id}`} variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        {order.number}
      </ButtonLink>
      <PageHeader title="Receive goods" description={`${order.supplier?.name ?? ''} · against ${order.number}`} />
      <ApiErrorAlert error={formError} />
      <InvoiceCard value={invoice} onChange={setInvoice} errors={errors} />

      <Card>
        <CardHeader title="What arrived" description="Quantities start at what is still expected. Change them to what was actually delivered; enter 0 for items that did not come." />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[44rem] text-sm">
            <thead>
              <tr className="border-y border-line-soft text-left text-xs font-medium tracking-wide text-ink-muted uppercase">
                <th className="px-6 py-3">Item</th>
                <th className="px-3 py-3 text-right">Expected</th>
                <th className="w-28 px-3 py-3">Received</th>
                <th className="w-36 px-3 py-3">Cost per unit</th>
                {showBatch && <th className="w-32 px-3 py-3">Batch</th>}
                {showBatch && <th className="w-40 px-3 py-3">Expiry</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-line-soft">
              {lines.map((l, i) => (
                <tr key={l.poItemId} className="align-top">
                  <td className="px-6 py-3">
                    <p className="font-medium text-ink">{l.name}</p>
                    <p className="text-xs text-ink-subtle">{l.sku}</p>
                  </td>
                  <td className="px-3 py-3 pt-5 text-right whitespace-nowrap text-ink-muted">{formatQty(l.remaining, l.unit)}</td>
                  <td className="px-3 py-3">
                    <Input aria-label={`Received quantity of ${l.name}`} inputMode="decimal" value={l.qty} aria-invalid={!!err(i, 'qty')} onChange={(e) => set(i, { qty: e.target.value })} />
                    {err(i, 'qty') && <p className="mt-1 text-xs text-[#ff3e1d]">{err(i, 'qty')}</p>}
                  </td>
                  <td className="px-3 py-3">
                    <Input aria-label={`Cost of ${l.name}`} inputMode="decimal" leftAddon="₱" value={l.unitCost} aria-invalid={!!err(i, 'unit_cost')} onChange={(e) => set(i, { unitCost: e.target.value })} />
                    {err(i, 'unit_cost') && <p className="mt-1 text-xs text-[#ff3e1d]">{err(i, 'unit_cost')}</p>}
                  </td>
                  {showBatch && (
                    <td className="px-3 py-3">{l.trackExpiry ? <Input aria-label={`Batch of ${l.name}`} value={l.batchNo} maxLength={60} onChange={(e) => set(i, { batchNo: e.target.value })} /> : '—'}</td>
                  )}
                  {showBatch && (
                    <td className="px-3 py-3">
                      {l.trackExpiry ? (
                        <>
                          <Input type="date" aria-label={`Expiry of ${l.name}`} value={l.expiryDate} aria-invalid={!!err(i, 'expiry_date')} onChange={(e) => set(i, { expiryDate: e.target.value })} />
                          {err(i, 'expiry_date') && <p className="mt-1 text-xs text-[#ff3e1d]">{err(i, 'expiry_date')}</p>}
                        </>
                      ) : (
                        '—'
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {errors.items && <p className="px-6 py-3 text-xs text-[#ff3e1d]">{errors.items}</p>}
      </Card>

      <div className="sticky bottom-0 z-20 -mx-4 flex justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
        <Button onClick={receive} loading={saving}>
          Receive stock
        </Button>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ Direct (no PO) */

function ReceiveDirect() {
  const account = useAccount()
  const { activeBranch } = useAuth()
  const batchesEnabled = Boolean(account.subscription?.plan.features?.batch_expiry)
  const suppliers = useQuery({ queryKey: purchasingKeys.supplierOptions, queryFn: purchasingApi.supplierOptions })
  const [supplierId, setSupplierId] = useState<number | ''>('')
  const [invoice, setInvoice] = useState<InvoiceFields>({ invoiceNo: '', invoiceDate: '', notes: '' })
  const [lines, setLines] = useState<PurchaseLine[]>([])
  const [notice, setNotice] = useState<string | null>(null)
  const { errors, setErrors, formError, saving, submit } = useReceiveSubmit()
  const showBatch = batchesEnabled && lines.some((l) => l.variant.track_expiry)

  const add = (item: PurchasableItem) => {
    if (lines.some((l) => l.variant.id === item.variant.id && l.unitId === item.default_unit_id && !item.variant.track_expiry)) {
      setNotice(`${item.variant.name} is already listed.`)
      return
    }
    setNotice(null)
    setLines((all) => [...all, lineFromItem(item)])
  }

  const receive = () => {
    const found = validatePurchaseLines(lines, { requireExpiry: batchesEnabled })
    if (supplierId === '') found.supplier_id = 'Choose the supplier.'
    setErrors(found)
    if (Object.keys(found).length > 0) return

    const supplier = suppliers.data?.find((s) => s.id === supplierId)
    const total = sumMoney(lines.map((l) => multiplyToMoney(l.qty, l.unitCost)))
    void submit(
      {
        purchase_order_id: null,
        supplier_id: Number(supplierId),
        supplier_invoice_no: invoice.invoiceNo.trim() || null,
        supplier_invoice_date: invoice.invoiceDate || null,
        notes: invoice.notes.trim() || null,
        items: lines.map((l) => ({
          product_variant_id: l.variant.id,
          product_unit_id: l.unitId,
          qty: l.qty.trim(),
          unit_cost: l.unitCost.trim(),
          ...(batchesEnabled && l.variant.track_expiry ? { batch_no: l.batchNo.trim() || null, expiry_date: l.expiryDate || null } : {}),
        })),
      },
      `${lines.length} item${lines.length === 1 ? '' : 's'} from ${supplier?.name}, about ${formatMoney(total)}.`,
    )
  }

  return (
    <div className="space-y-6">
      <ButtonLink to="/purchasing/receipts" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Goods received
      </ButtonLink>
      <PageHeader title="Receive without a purchase order" description={`Into ${activeBranch?.name ?? '—'}`} />
      <ApiErrorAlert error={formError} />
      <InvoiceCard value={invoice} onChange={setInvoice} errors={errors}>
        <FormField label="Supplier" required error={errors.supplier_id} className="md:col-span-2">
          <Select value={String(supplierId)} onChange={(e) => setSupplierId(e.target.value ? Number(e.target.value) : '')} disabled={suppliers.isPending}>
            <option value="">Choose a supplier…</option>
            {(suppliers.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </Select>
        </FormField>
      </InvoiceCard>
      {suppliers.data?.length === 0 && (
        <Alert tone="info">
          Add a supplier first under <Link to="/purchasing/suppliers" className="font-medium underline">Suppliers</Link>.
        </Alert>
      )}

      <Card>
        <CardHeader title="Items" description="Costs as printed on the invoice (VAT included)." />
        <CardBody className="space-y-4">
          <div>
            <ItemPicker<PurchasableItem> source={pickerSource} onSelect={add} />
            {notice && <p className="mt-1 text-xs text-ink-subtle">{notice}</p>}
            {errors.items && <p className="mt-1 text-xs text-[#ff3e1d]">{errors.items}</p>}
          </div>
          <PurchaseLinesTable
            lines={lines}
            errors={errors}
            showBatch={showBatch}
            batchesEnabled={batchesEnabled}
            onChange={(key, patch, index) => {
              setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)))
              setErrors(Object.fromEntries(Object.entries(errors).filter(([k]) => !k.startsWith(`items.${index}.`))))
            }}
            onRemove={(key) => {
              setLines((all) => all.filter((l) => l.key !== key))
              setErrors({})
            }}
          />
          {lines.length > 0 && (
            <p className="text-right text-sm">
              <span className="text-ink-muted">Estimated total </span>
              <span className="text-base font-medium text-ink">{formatMoney(sumMoney(lines.map((l) => multiplyToMoney(l.qty, l.unitCost))))}</span>
            </p>
          )}
        </CardBody>
      </Card>

      <div className="sticky bottom-0 z-20 -mx-4 flex justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
        <Button onClick={receive} loading={saving}>
          Receive stock
        </Button>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ View + reverse */

function ReceiptView({ receipt }: { receipt: GoodsReceipt }) {
  const [reversing, setReversing] = useState(false)
  const items = receipt.items ?? []
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
      header: 'Received',
      align: 'right',
      render: (i) => (
        <div className="whitespace-nowrap">
          <p>{formatQty(i.qty, i.unit.abbreviation)}</p>
          {i.unit.product_unit_id && <p className="text-xs text-ink-subtle">= {formatQty(i.base_qty, i.variant.unit)}</p>}
        </div>
      ),
    },
    ...(hasBatches
      ? [
          { key: 'batch', header: 'Batch', render: (i: Item) => i.batch_no ?? '—' },
          { key: 'expiry', header: 'Expiry', render: (i: Item) => formatDate(i.expiry_date) },
        ]
      : []),
    { key: 'cost', header: 'Cost', align: 'right', className: 'hidden md:table-cell', render: (i) => formatMoney(i.unit_cost) },
    { key: 'total', header: 'Line total', align: 'right', render: (i) => <span className="whitespace-nowrap">{formatMoney(i.line_total)}</span> },
  ]

  return (
    <div className="space-y-6">
      <ButtonLink to="/purchasing/receipts" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Goods received
      </ButtonLink>
      <PageHeader
        title={`Goods receipt ${receipt.number}`}
        description={`${receipt.supplier?.name ?? ''} · ${receipt.branch?.name ?? ''}`}
        actions={
          <>
            <StatusBadge status={receipt.status} />
            {receipt.can.reverse && (
              <Button variant="outline" onClick={() => setReversing(true)}>
                Reverse receipt
              </Button>
            )}
          </>
        }
      />
      {receipt.status === 'reversed' && (
        <Alert tone="warning">
          Reversed {formatDateTime(receipt.reversed_at)} by {receipt.reversed_by?.name ?? '—'}: {receipt.reversal_reason}. The stock was taken back out.
        </Alert>
      )}

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title="Items" />
          <DataTable columns={columns} rows={items} rowKey={(i) => i.id} caption="Received items" />
          <div className="space-y-1 border-t border-line-soft px-6 py-4 text-sm">
            <p className="flex justify-end gap-6"><span className="text-ink-muted">Net of VAT (inventory value)</span><span className="w-28 text-right">{formatMoney(receipt.subtotal)}</span></p>
            <p className="flex justify-end gap-6"><span className="text-ink-muted">Input VAT</span><span className="w-28 text-right">{formatMoney(receipt.tax_total)}</span></p>
            <p className="flex justify-end gap-6 text-base font-medium text-ink"><span>Invoice total</span><span className="w-28 text-right">{formatMoney(receipt.total)}</span></p>
          </div>
        </Card>
        <Card>
          <CardHeader title="Details" />
          <CardBody>
            <DetailList
              items={[
                { label: 'Supplier', value: receipt.supplier?.name ?? '—' },
                {
                  label: 'Purchase order',
                  value: receipt.purchase_order ? (
                    <Link to={`/purchasing/orders/${receipt.purchase_order.id}`} className="text-brand-500 hover:underline">
                      {receipt.purchase_order.number}
                    </Link>
                  ) : (
                    'None (direct)'
                  ),
                },
                { label: 'Invoice no.', value: receipt.supplier_invoice_no ?? '—' },
                { label: 'Invoice date', value: formatDate(receipt.supplier_invoice_date) },
                { label: 'Received', value: `${receipt.received_by?.name ?? '—'} · ${formatDateTime(receipt.received_at)}` },
                { label: 'Notes', value: receipt.notes ?? '—' },
              ]}
            />
          </CardBody>
        </Card>
      </div>
      {reversing && <ReverseModal receipt={receipt} onClose={() => setReversing(false)} />}
    </div>
  )
}

function ReverseModal({ receipt, onClose }: { receipt: GoodsReceipt; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [reason, setReason] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [fieldError, setFieldError] = useState<string | undefined>()
  const [busy, setBusy] = useState(false)

  const reverse = async () => {
    if (reason.trim().length < 3) {
      setFieldError('Say why, e.g. "Wrong supplier invoice".')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const saved = await purchasingApi.reverseReceipt(receipt.id, reason.trim())
      queryClient.setQueryData(purchasingKeys.receipt(saved.id), saved)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: purchasingKeys.all, predicate: (q) => q.queryKey[1] !== 'receipt' }),
        queryClient.invalidateQueries({ queryKey: inventoryKeys.all }),
      ])
      toast.success(`${receipt.number} reversed.`)
      onClose()
    } catch (e) {
      const apiError = ApiError.from(e)
      if (apiError.errors.reason?.[0]) setFieldError(apiError.errors.reason[0])
      else setError(apiError)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!busy}
      title={`Reverse ${receipt.number}?`}
      description="The received stock is taken back out at the same cost. This only works while none of it has been sold, moved or adjusted."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Keep receipt
          </Button>
          <Button variant="danger" onClick={reverse} loading={busy}>
            Reverse receipt
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <FormField label="Reason" required error={fieldError}>
          <Input value={reason} maxLength={200} onChange={(e) => { setReason(e.target.value); setFieldError(undefined) }} data-autofocus />
        </FormField>
      </div>
    </Modal>
  )
}
