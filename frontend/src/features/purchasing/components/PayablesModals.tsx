import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Checkbox, FormField, Input, Modal, Select, Spinner, Textarea } from '@/components/ui'
import { todayIn } from '@/features/reports/dates'
import { ApiError } from '@/lib/api/errors'
import { compareDecimal, sumMoney } from '@/lib/decimal'
import { formatDate, formatMoney } from '@/lib/format'
import { purchasingApi, purchasingKeys } from '../api'
import { BILL_STATUS, payableKeys, payablesApi, type SupplierBill, type SupplierPaymentRow } from '../payables'

const day = (d: string | null) => (d ? formatDate(`${d}T00:00:00+08:00`) : '—')
const errorsOf = (e: ApiError) => Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0] ?? '']))

/* ------------------------------------------------------------------ new bill */

export function NewBillModal({ onSaved, onClose }: { onSaved: (b: SupplierBill) => void; onClose: () => void }) {
  const [mode, setMode] = useState<'receipt' | 'other'>('receipt')
  const receipts = useQuery({ queryKey: payableKeys.billable(), queryFn: () => payablesApi.billable() })
  const suppliers = useQuery({ queryKey: purchasingKeys.supplierOptions, queryFn: purchasingApi.supplierOptions, enabled: mode === 'other' })
  const [v, setV] = useState({ goods_receipt_id: 0, supplier_id: 0, supplier_invoice_no: '', bill_date: todayIn(), due_date: '', total: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (p: Partial<typeof v>) => setV((x) => ({ ...x, ...p }))

  const save = async () => {
    setBusy(true)
    setError(null)
    setErrors({})
    try {
      onSaved(
        await payablesApi.createBill(
          mode === 'receipt'
            ? { goods_receipt_id: v.goods_receipt_id || null, supplier_invoice_no: v.supplier_invoice_no.trim() || null, bill_date: v.bill_date, due_date: v.due_date || null }
            : { supplier_id: v.supplier_id || null, supplier_invoice_no: v.supplier_invoice_no.trim() || null, bill_date: v.bill_date, due_date: v.due_date || null, total: v.total.trim() || null },
        ),
      )
    } catch (e) {
      const err = ApiError.from(e)
      if (err.isValidation) setErrors(errorsOf(err))
      else setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      dismissible={!busy}
      title="New supplier bill"
      description="What you owe a supplier. The due date follows the supplier's payment terms unless you set one."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy}>
            Save bill
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <div role="radiogroup" aria-label="Bill for" className="flex gap-2">
          {(
            [
              ['receipt', 'Goods received'],
              ['other', 'Other (services, etc.)'],
            ] as const
          ).map(([key, label]) => (
            <button
              key={key}
              type="button"
              role="radio"
              aria-checked={mode === key}
              onClick={() => setMode(key)}
              className={mode === key ? 'rounded-md border border-brand-500 bg-brand-100/50 px-3 py-1.5 text-sm font-medium text-brand-600' : 'rounded-md border border-line px-3 py-1.5 text-sm text-ink hover:border-brand-500'}
            >
              {label}
            </button>
          ))}
        </div>
        {mode === 'receipt' ? (
          <FormField label="Delivery" required error={errors.goods_receipt_id}>
            {receipts.isPending ? (
              <Spinner />
            ) : (
              <Select value={v.goods_receipt_id || ''} onChange={(e) => {
                const r = receipts.data?.find((x) => x.id === Number(e.target.value))
                set({ goods_receipt_id: Number(e.target.value), supplier_invoice_no: r?.supplier_invoice_no ?? '' })
              }} data-autofocus>
                <option value="">{receipts.data?.length ? 'Choose a delivery not billed yet…' : 'Every delivery is already billed'}</option>
                {(receipts.data ?? []).map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.number} · {r.supplier?.name} · {formatMoney(r.total)}
                  </option>
                ))}
              </Select>
            )}
          </FormField>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Supplier" required error={errors.supplier_id}>
              <Select value={v.supplier_id || ''} onChange={(e) => set({ supplier_id: Number(e.target.value) })}>
                <option value="">Choose…</option>
                {(suppliers.data ?? []).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Amount" required error={errors.total}>
              <Input value={v.total} inputMode="decimal" leftAddon="₱" onChange={(e) => set({ total: e.target.value.replace(/[^\d.]/g, '') })} />
            </FormField>
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Supplier's invoice no." error={errors.supplier_invoice_no}>
            <Input value={v.supplier_invoice_no} maxLength={60} onChange={(e) => set({ supplier_invoice_no: e.target.value })} />
          </FormField>
          <FormField label="Bill date" required error={errors.bill_date}>
            <Input type="date" value={v.bill_date} onChange={(e) => set({ bill_date: e.target.value })} />
          </FormField>
          <FormField label="Due date" hint="Optional" error={errors.due_date}>
            <Input type="date" value={v.due_date} min={v.bill_date} onChange={(e) => set({ due_date: e.target.value })} />
          </FormField>
        </div>
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------------------ pay supplier */

export function PaySupplierModal({ supplierId, onSaved, onClose }: { supplierId?: number; onSaved: (p: SupplierPaymentRow) => void; onClose: () => void }) {
  const suppliers = useQuery({ queryKey: purchasingKeys.supplierOptions, queryFn: purchasingApi.supplierOptions })
  const methods = useQuery({ queryKey: payableKeys.methods, queryFn: payablesApi.methods })
  const [supplier, setSupplier] = useState<number>(supplierId ?? 0)
  const bills = useQuery({ queryKey: payableKeys.bills({ supplier_id: supplier, status: 'unpaid' }), queryFn: () => payablesApi.bills({ supplier_id: supplier, status: 'unpaid' }), enabled: supplier > 0 })
  const [amounts, setAmounts] = useState<Record<number, string>>({})
  const [v, setV] = useState({ payment_method_id: 0, paid_at: todayIn(), reference_no: '', notes: '', paid_from_drawer: false })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const open = bills.data?.items ?? []
  const chosen = open.filter((b) => (amounts[b.id] ?? '').trim() !== '')
  const total = sumMoney(chosen.map((b) => amounts[b.id]))

  const save = async () => {
    const found: Record<string, string> = {}
    if (!supplier) found.supplier_id = 'Choose the supplier.'
    for (const b of chosen) {
      const a = amounts[b.id]!.trim()
      if (!/^\d+(\.\d{1,2})?$/.test(a) || compareDecimal(a, '0') !== 1 || compareDecimal(a, b.balance) === 1) found[`bill-${b.id}`] = `Up to ${formatMoney(b.balance)}.`
    }
    if (!chosen.length) found.allocations = 'Enter how much is paid on each bill.'
    setErrors(found)
    if (Object.keys(found).length) return
    setBusy(true)
    setError(null)
    try {
      onSaved(
        await payablesApi.pay({
          supplier_id: supplier,
          payment_method_id: v.payment_method_id || null,
          paid_at: v.paid_at,
          amount: total,
          reference_no: v.reference_no.trim() || null,
          notes: v.notes.trim() || null,
          paid_from_drawer: v.paid_from_drawer,
          allocations: chosen.map((b) => ({ supplier_bill_id: b.id, amount: amounts[b.id]!.trim() })),
        }),
      )
    } catch (e) {
      const err = ApiError.from(e)
      if (err.isValidation) setErrors(errorsOf(err))
      else setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      dismissible={!busy}
      title="Pay a supplier"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy} disabled={!chosen.length}>
            Record payment of {formatMoney(total)}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <FormField label="Supplier" required error={errors.supplier_id}>
          <Select value={supplier || ''} onChange={(e) => {
            setSupplier(Number(e.target.value))
            setAmounts({})
          }} data-autofocus>
            <option value="">Choose…</option>
            {(suppliers.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </Select>
        </FormField>
        {supplier > 0 && (
          <div className="overflow-x-auto rounded-md border border-line">
            {bills.isPending ? (
              <div className="flex justify-center p-4">
                <Spinner />
              </div>
            ) : open.length === 0 ? (
              <p className="p-4 text-sm text-ink-subtle">Nothing is owed to this supplier.</p>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-[#f8f9fa] text-left text-xs text-ink-subtle uppercase">
                  <tr>
                    <th className="px-3 py-2 font-medium">Bill</th>
                    <th className="px-3 py-2 font-medium">Due</th>
                    <th className="px-3 py-2 text-right font-medium">Balance</th>
                    <th className="w-44 px-3 py-2 font-medium">Pay now</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line-soft">
                  {open.map((b) => (
                    <tr key={b.id} className="align-top">
                      <td className="px-3 py-2">
                        <span className="font-medium text-ink">{b.number}</span>
                        {b.supplier_invoice_no && <span className="block text-xs text-ink-subtle">Inv. {b.supplier_invoice_no}</span>}
                      </td>
                      <td className="px-3 py-2 whitespace-nowrap">
                        {day(b.due_date)} {b.overdue && <Badge tone="danger">Overdue</Badge>}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(b.balance)}</td>
                      <td className="px-3 py-2">
                        <div className="flex gap-1">
                          <Input aria-label={`Amount for ${b.number}`} inputMode="decimal" value={amounts[b.id] ?? ''} placeholder="0.00"
                            onChange={(e) => setAmounts((a) => ({ ...a, [b.id]: e.target.value.replace(/[^\d.]/g, '') }))} />
                          <Button size="sm" variant="ghost" onClick={() => setAmounts((a) => ({ ...a, [b.id]: b.balance }))}>
                            All
                          </Button>
                        </div>
                        {errors[`bill-${b.id}`] && <p className="mt-1 text-xs text-[#ff3e1d]">{errors[`bill-${b.id}`]}</p>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
        {(errors.allocations || errors.amount) && <p className="text-sm text-[#ff3e1d]">{errors.allocations || errors.amount}</p>}
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Paid by">
            <Select value={v.payment_method_id || ''} onChange={(e) => setV((x) => ({ ...x, payment_method_id: Number(e.target.value) }))}>
              <option value="">Not specified</option>
              {(methods.data ?? []).map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Date paid" required error={errors.paid_at}>
            <Input type="date" value={v.paid_at} max={todayIn()} onChange={(e) => setV((x) => ({ ...x, paid_at: e.target.value }))} />
          </FormField>
          <FormField label="Reference (check / transfer no.)">
            <Input value={v.reference_no} maxLength={100} onChange={(e) => setV((x) => ({ ...x, reference_no: e.target.value }))} />
          </FormField>
        </div>
        <FormField label="Notes">
          <Textarea rows={2} maxLength={500} value={v.notes} onChange={(e) => setV((x) => ({ ...x, notes: e.target.value }))} />
        </FormField>
        <Checkbox label="Paid in cash from the drawer of my open shift" checked={v.paid_from_drawer} onChange={(e) => setV((x) => ({ ...x, paid_from_drawer: e.target.checked }))} />
        {errors.paid_from_drawer && <p className="text-sm text-[#ff3e1d]">{errors.paid_from_drawer}</p>}
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------------------ bill detail */

export function BillModal({ billId, canManage, onChanged, onClose }: { billId: number; canManage: boolean; onChanged: () => void; onClose: () => void }) {
  const bill = useQuery({ queryKey: payableKeys.bill(billId), queryFn: () => payablesApi.bill(billId) })
  const [reason, setReason] = useState('')
  const [voiding, setVoiding] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const b = bill.data

  const doVoid = async () => {
    setBusy(true)
    setError(null)
    try {
      await payablesApi.voidBill(billId, reason.trim())
      onChanged()
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={b ? `Bill ${b.number}` : 'Bill'}
      footer={
        b && canManage && b.status === 'open' ? (
          voiding ? (
            <>
              <Button variant="secondary" onClick={() => setVoiding(false)}>
                Back
              </Button>
              <Button variant="danger" loading={busy} disabled={reason.trim().length < 3} onClick={doVoid}>
                Void bill
              </Button>
            </>
          ) : (
            <Button variant="outline" onClick={() => setVoiding(true)}>
              Void
            </Button>
          )
        ) : undefined
      }
    >
      {!b ? (
        <div className="flex justify-center p-6">{bill.isError ? <ApiErrorAlert error={ApiError.from(bill.error)} /> : <Spinner />}</div>
      ) : (
        <div className="space-y-4 text-sm">
          <ApiErrorAlert error={error} />
          <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
            <dt>Supplier</dt>
            <dd className="text-right text-ink">{b.supplier?.name}</dd>
            <dt>Status</dt>
            <dd className="text-right">
              <Badge tone={BILL_STATUS[b.status].tone}>{BILL_STATUS[b.status].label}</Badge>
            </dd>
            <dt>Delivery</dt>
            <dd className="text-right text-ink">{b.goods_receipt?.number ?? '—'}</dd>
            <dt>Supplier invoice</dt>
            <dd className="text-right text-ink">{b.supplier_invoice_no ?? '—'}</dd>
            <dt>Bill date / due</dt>
            <dd className="text-right text-ink">
              {day(b.bill_date)} / {day(b.due_date)}
            </dd>
            <dt>Total</dt>
            <dd className="text-right text-ink tabular-nums">{formatMoney(b.total)}</dd>
            <dt>Paid</dt>
            <dd className="text-right text-ink tabular-nums">{formatMoney(b.amount_paid)}</dd>
            <dt className="font-medium">Balance</dt>
            <dd className="text-right font-semibold text-ink tabular-nums">{formatMoney(b.balance)}</dd>
          </dl>
          {(b.payments ?? []).length > 0 && (
            <div>
              <p className="mb-1 font-medium text-ink">Payments</p>
              <ul className="divide-y divide-line-soft rounded-md border border-line">
                {b.payments!.map((p) => (
                  <li key={p.id} className="flex justify-between px-3 py-2">
                    <span className={p.status === 'void' ? 'line-through' : ''}>
                      {p.number} · {day(p.paid_at)} · {p.method ?? '—'}
                    </span>
                    <span className="tabular-nums">{formatMoney(p.amount)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {voiding && (
            <FormField label="Reason for voiding" required>
              <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} data-autofocus />
            </FormField>
          )}
        </div>
      )}
    </Modal>
  )
}
