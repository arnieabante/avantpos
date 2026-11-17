import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { ArrowLeft, PackageCheck, Printer, TrendingDown } from 'lucide-react'
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
import { ItemPicker } from '@/features/inventory/components/ItemPicker'
import { StatusBadge } from '@/features/inventory/components/StatusBadge'
import { formatQty } from '@/features/inventory/format'
import { ApiError } from '@/lib/api/errors'
import { sumMoney, multiplyToMoney } from '@/lib/decimal'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { purchasingApi, purchasingKeys } from '../api'
import { ReceiptPrintArea } from '@/features/pos/components/Receipt'
import { printReceipt } from '@/features/pos/receipt'
import { PrintablePurchaseOrder } from '../components/PrintablePurchaseOrder'
import { PurchaseLinesTable } from '../components/PurchaseLines'
import { lineFromItem, newLineKey, validatePurchaseLines, type PurchaseLine } from '../purchaseLines'
import type { PurchasableItem, PurchaseOrder } from '../types'

const pickerSource = { key: 'purchasing', fetch: purchasingApi.searchItems }

/** /purchasing/orders/new and /purchasing/orders/:orderId */
export default function PurchaseOrderPage() {
  const { orderId } = useParams()
  const isNew = orderId === 'new'
  const id = Number(orderId)
  const { can } = useAuth()
  const order = useQuery({ queryKey: purchasingKeys.order(id), queryFn: () => purchasingApi.order(id), enabled: !isNew && Number.isFinite(id) })

  if (!isNew && order.isPending) return <PageLoader />
  if (order.isError) return <ApiErrorAlert error={ApiError.from(order.error)} />

  const po = order.data ?? null
  if (isNew ? can('po.create') : po?.can.edit) return <OrderEditor key={po?.id ?? 'new'} order={po} />
  return <OrderView order={po!} />
}

/* ------------------------------------------------------------------ Editor */

function OrderEditor({ order }: { order: PurchaseOrder | null }) {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const suppliers = useQuery({ queryKey: purchasingKeys.supplierOptions, queryFn: purchasingApi.supplierOptions })
  const canApprove = can('po.approve')

  const [supplierId, setSupplierId] = useState<number | ''>(order?.supplier?.id ?? '')
  const [orderDate, setOrderDate] = useState(() => order?.order_date ?? new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' }))
  const [expectedDate, setExpectedDate] = useState(order?.expected_date ?? '')
  const [notes, setNotes] = useState(order?.notes ?? '')
  const [lines, setLines] = useState<PurchaseLine[]>(
    (order?.items ?? []).map((i) => ({
      key: newLineKey(),
      variant: i.variant,
      units: [i.unit],
      unitId: i.unit.product_unit_id,
      qty: trimDecimal(i.qty_ordered),
      unitCost: trimDecimal(i.unit_cost),
      lastCost: null,
      onHand: null,
      batchNo: '',
      expiryDate: '',
    })),
  )
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<ApiError | null>(null)
  const [saving, setSaving] = useState<'draft' | 'approve' | 'cancel' | 'low' | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const has = (item: PurchasableItem, unitId: number | null) => lines.some((l) => l.variant.id === item.variant.id && l.unitId === unitId)
  const add = (item: PurchasableItem) => {
    if (has(item, item.default_unit_id)) {
      setNotice(`${item.variant.name} is already on the order.`)
      return
    }
    setNotice(null)
    setLines((all) => [...all, lineFromItem(item)])
    setErrors((e) => ({ ...e, items: '' }))
  }

  const addLowStock = async () => {
    setSaving('low')
    try {
      const items = await purchasingApi.lowStockItems()
      const fresh = items.filter((item) => !lines.some((l) => l.variant.id === item.variant.id))
      setLines((all) => [...all, ...fresh.map((item) => ({ ...lineFromItem(item, item.suggested_qty ?? ''), unitId: null }))])
      setNotice(fresh.length ? `Added ${fresh.length} low-stock item${fresh.length === 1 ? '' : 's'} with suggested quantities (in ${fresh.length === 1 ? 'its' : 'their'} base unit).` : 'No other items are at or below their reorder level.')
    } catch (error) {
      setFormError(ApiError.from(error))
    } finally {
      setSaving(null)
    }
  }

  const update = (key: string, patch: Partial<PurchaseLine>, index: number) => {
    setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)))
    setErrors((e) => Object.fromEntries(Object.entries(e).filter(([k]) => !k.startsWith(`items.${index}.`))))
  }

  const save = async (approve: boolean) => {
    setFormError(null)
    const found = validatePurchaseLines(lines)
    if (supplierId === '') found.supplier_id = 'Choose a supplier.'
    setErrors(found)
    if (Object.keys(found).length > 0) return

    if (approve && !(await confirm({ title: 'Approve this order?', description: 'Approved orders can no longer be edited. Goods can then be received against it.', confirmLabel: 'Approve order' }))) return

    setSaving(approve ? 'approve' : 'draft')
    try {
      const saved = await purchasingApi.saveOrder(
        {
          supplier_id: Number(supplierId),
          order_date: orderDate || null,
          expected_date: expectedDate || null,
          notes: notes.trim() || null,
          approve,
          items: lines.map((l) => ({ product_variant_id: l.variant.id, product_unit_id: l.unitId, qty: l.qty.trim(), unit_cost: l.unitCost.trim() })),
        },
        order?.id,
      )
      queryClient.setQueryData(purchasingKeys.order(saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: purchasingKeys.all, predicate: (q) => q.queryKey[1] !== 'order' })
      toast.success(approve ? `${saved.number} approved.` : `${saved.number} saved.`)
      navigate(`/purchasing/orders/${saved.id}`, { replace: true })
    } catch (error) {
      const apiError = ApiError.from(error)
      if (apiError.isValidation && Object.keys(apiError.errors).length > 0) {
        setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
      } else {
        setFormError(apiError)
      }
    } finally {
      setSaving(null)
    }
  }

  const cancelOrder = async () => {
    if (!order || !(await confirm({ title: `Cancel ${order.number}?`, confirmLabel: 'Cancel order', danger: true }))) return
    setSaving('cancel')
    try {
      queryClient.setQueryData(purchasingKeys.order(order.id), await purchasingApi.orderAction(order.id, 'cancel'))
      await queryClient.invalidateQueries({ queryKey: purchasingKeys.all, predicate: (q) => q.queryKey[1] !== 'order' })
      toast.success(`${order.number} cancelled.`)
    } catch (error) {
      setFormError(ApiError.from(error))
    } finally {
      setSaving(null)
    }
  }

  const estimate = sumMoney(lines.map((l) => multiplyToMoney(l.qty, l.unitCost)))
  const supplier = suppliers.data?.find((s) => s.id === supplierId)

  return (
    <form onSubmit={(e) => { e.preventDefault(); void save(false) }} noValidate className="space-y-6">
      <ButtonLink to="/purchasing/orders" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Purchase orders
      </ButtonLink>
      <PageHeader title={order ? `Purchase order ${order.number}` : 'New purchase order'} description={`Deliver to ${activeBranch?.name ?? '—'}`} actions={order && <StatusBadge status={order.status} />} />
      <ApiErrorAlert error={formError} />

      <Card>
        <CardHeader title="Order details" />
        <CardBody className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
          <FormField label="Order date" error={errors.order_date}>
            <Input type="date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} />
          </FormField>
          <FormField label="Expected delivery" error={errors.expected_date}>
            <Input type="date" value={expectedDate} min={orderDate} onChange={(e) => setExpectedDate(e.target.value)} />
          </FormField>
          <FormField label="Notes" className="md:col-span-2 xl:col-span-4">
            <Textarea rows={2} value={notes} maxLength={2000} onChange={(e) => setNotes(e.target.value)} placeholder="Delivery instructions, terms…" />
          </FormField>
          {suppliers.data?.length === 0 && (
            <Alert tone="info" className="md:col-span-2 xl:col-span-4">
              Add a supplier first under <Link to="/purchasing/suppliers" className="font-medium underline">Suppliers</Link>.
            </Alert>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Items"
          description="Enter costs as printed on the supplier's invoice (VAT included)."
          actions={
            <Button variant="outline" size="sm" leftIcon={<TrendingDown className="size-4" />} onClick={addLowStock} loading={saving === 'low'}>
              Add low-stock items
            </Button>
          }
        />
        <CardBody className="space-y-4">
          <div>
            <ItemPicker<PurchasableItem> source={pickerSource} onSelect={add} />
            {notice && <p className="mt-1 text-xs text-ink-subtle">{notice}</p>}
            {errors.items && <p className="mt-1 text-xs text-[#ff3e1d]">{errors.items}</p>}
          </div>
          <PurchaseLinesTable
            lines={lines}
            errors={errors}
            onChange={update}
            onRemove={(key) => {
              setLines((all) => all.filter((l) => l.key !== key))
              setErrors({})
            }}
          />
          {lines.length > 0 && (
            <div className="flex flex-col items-end gap-1 text-sm">
              <p>
                <span className="text-ink-muted">Estimated total </span>
                <span className="text-base font-medium text-ink">{formatMoney(estimate)}</span>
              </p>
              <p className="text-xs text-ink-subtle">{supplier?.vat_registered ? 'Input VAT is split out when you save.' : 'Totals are confirmed when you save.'}</p>
            </div>
          )}
        </CardBody>
      </Card>

      <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
        {order && (
          <Button variant="ghost" className="mr-auto text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={cancelOrder} loading={saving === 'cancel'} disabled={saving !== null}>
            Cancel order
          </Button>
        )}
        {!canApprove && <p className="mr-2 text-xs text-ink-subtle">An owner or admin approves the order before it is sent.</p>}
        <Button type="submit" variant={canApprove ? 'outline' : 'primary'} loading={saving === 'draft'} disabled={saving !== null}>
          Save draft
        </Button>
        {canApprove && (
          <Button onClick={() => void save(true)} loading={saving === 'approve'} disabled={saving !== null}>
            Save and approve
          </Button>
        )}
      </div>
    </form>
  )
}

/* ------------------------------------------------------------------ Read-only + actions */

function OrderView({ order }: { order: PurchaseOrder }) {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const items = order.items ?? []
  type Item = (typeof items)[number]

  const act = async (action: 'approve' | 'cancel' | 'close') => {
    const prompts = {
      approve: { title: `Approve ${order.number}?`, confirmLabel: 'Approve order' },
      cancel: { title: `Cancel ${order.number}?`, confirmLabel: 'Cancel order', danger: true },
      close: { title: `Close ${order.number}?`, description: 'The items not yet delivered will no longer be expected.', confirmLabel: 'Close order' },
    }
    if (!(await confirm(prompts[action]))) return
    setBusy(action)
    setError(null)
    try {
      queryClient.setQueryData(purchasingKeys.order(order.id), await purchasingApi.orderAction(order.id, action))
      await queryClient.invalidateQueries({ queryKey: purchasingKeys.all, predicate: (q) => q.queryKey[1] !== 'order' })
      toast.success(`${order.number} ${action === 'approve' ? 'approved' : action === 'cancel' ? 'cancelled' : 'closed'}.`)
    } catch (e) {
      setError(ApiError.from(e))
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
    { key: 'ordered', header: 'Ordered', align: 'right', render: (i) => <span className="whitespace-nowrap">{formatQty(i.qty_ordered, i.unit.abbreviation)}</span> },
    { key: 'received', header: 'Received', align: 'right', render: (i) => <span className="whitespace-nowrap">{formatQty(i.qty_received, i.unit.abbreviation)}</span> },
    { key: 'cost', header: 'Cost', align: 'right', className: 'hidden md:table-cell', render: (i) => formatMoney(i.unit_cost) },
    { key: 'total', header: 'Line total', align: 'right', render: (i) => <span className="whitespace-nowrap">{formatMoney(i.line_total)}</span> },
  ]

  return (
    <div className="space-y-6">
      <ButtonLink to="/purchasing/orders" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Purchase orders
      </ButtonLink>
      <PageHeader
        title={`Purchase order ${order.number}`}
        description={`${order.supplier?.name ?? ''} · ${order.branch?.name ?? ''}`}
        actions={
          <>
            <StatusBadge status={order.status} />
            <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={() => printReceipt('a4')}>
              Print
            </Button>
            {order.can.approve && (
              <Button onClick={() => act('approve')} loading={busy === 'approve'} disabled={busy !== null}>
                Approve
              </Button>
            )}
            {order.can.receive && (
              <ButtonLink to={`/purchasing/receipts/new?po=${order.id}`} leftIcon={<PackageCheck className="size-4" />}>
                Receive goods
              </ButtonLink>
            )}
          </>
        }
      />
      <ApiErrorAlert error={error} />
      <ReceiptPrintArea>
        <PrintablePurchaseOrder order={order} businessName={account?.tenant.name ?? ''} vatRegistered={account?.tenant.vat_registered ?? false} />
      </ReceiptPrintArea>
      {order.status === 'draft' && !order.can.approve && <Alert tone="info">This order is waiting for approval by an owner or admin.</Alert>}

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title="Items" />
          <DataTable columns={columns} rows={items} rowKey={(i) => i.id} caption="Order items" />
          <div className="space-y-1 border-t border-line-soft px-6 py-4 text-sm">
            <p className="flex justify-end gap-6"><span className="text-ink-muted">Net of VAT</span><span className="w-28 text-right">{formatMoney(order.subtotal)}</span></p>
            <p className="flex justify-end gap-6"><span className="text-ink-muted">Input VAT</span><span className="w-28 text-right">{formatMoney(order.tax_total)}</span></p>
            <p className="flex justify-end gap-6 text-base font-medium text-ink"><span>Total</span><span className="w-28 text-right">{formatMoney(order.total)}</span></p>
          </div>
        </Card>
        <div className="space-y-6">
          <Card>
            <CardHeader title="Details" />
            <CardBody>
              <DetailList
                items={[
                  { label: 'Supplier', value: order.supplier?.name ?? '—' },
                  { label: 'Order date', value: formatDate(order.order_date) },
                  { label: 'Expected', value: formatDate(order.expected_date) },
                  { label: 'Created', value: `${order.created_by?.name ?? '—'} · ${formatDateTime(order.created_at)}` },
                  ...(order.approved_at ? [{ label: 'Approved', value: `${order.approved_by?.name ?? '—'} · ${formatDateTime(order.approved_at)}` }] : []),
                  { label: 'Notes', value: order.notes ?? '—' },
                ]}
              />
            </CardBody>
          </Card>
          {(order.receipts?.length ?? 0) > 0 && (
            <Card>
              <CardHeader title="Deliveries" />
              <CardBody className="space-y-2 text-sm">
                {order.receipts!.map((r) => (
                  <Link key={r.id} to={`/purchasing/receipts/${r.id}`} className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 hover:bg-canvas">
                    <span className="font-medium text-brand-500">{r.number}</span>
                    <span className="text-ink-muted">{formatDate(r.received_at)}</span>
                    <span className="ml-auto">{formatMoney(r.total)}</span>
                    <StatusBadge status={r.status} />
                  </Link>
                ))}
              </CardBody>
            </Card>
          )}
          {(order.can.cancel || order.can.close) && (
            <div className="flex justify-end gap-2">
              {order.can.close && (
                <Button variant="outline" onClick={() => act('close')} loading={busy === 'close'} disabled={busy !== null}>
                  Close order
                </Button>
              )}
              {order.can.cancel && (
                <Button variant="ghost" className="text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={() => act('cancel')} loading={busy === 'cancel'} disabled={busy !== null}>
                  Cancel order
                </Button>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
