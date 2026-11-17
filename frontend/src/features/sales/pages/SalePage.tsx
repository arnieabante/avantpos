import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft, Ban, Printer, Undo2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, ButtonLink, Card, CardBody, CardHeader, DetailList, Input, PageHeader, PageLoader, useToast } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { cashKeys } from '@/features/cash/api'
import { posApi, posKeys } from '@/features/pos/api'
import { SaleDoneModal } from '@/features/pos/components/SaleDoneModal'
import type { Sale, SaleRefund } from '@/features/pos/types'
import { ApiError } from '@/lib/api/errors'
import { trimQty } from '@/lib/decimal'
import { formatDateTime, formatMoney } from '@/lib/format'
import { salesApi, salesKeys } from '../api'
import { RefundModal } from '../components/RefundModal'
import { SaleStatusBadge } from '../components/SaleStatusBadge'
import { VoidModal } from '../components/VoidModal'

const isZero = (v: string | null | undefined) => !v || /^-?0*(\.0*)?$/.test(v)
const RESTOCK: Record<string, string> = { sellable: 'back to stock', damaged: 'damaged', none: 'not returned' }

/** What the cashier must do after a refund. */
function refundMessage(r: SaleRefund): string {
  const out = r.payments.map((p) => `${formatMoney(p.amount)} by ${p.method ?? 'cash'}`).join(' and ')
  return out ? `Refund ${r.number}: give back ${out}` : `Refund ${r.number}: ${formatMoney(r.total)} credited to the customer's account`
}

const ISSUE_TITLES: Record<string, string> = {
  price_changed: 'Different price',
  total_mismatch: 'Different total',
  payment_mismatch: 'Payment did not add up',
  negative_stock: 'Stock below zero',
  shift_closed: 'Uploaded after the shift was closed',
  invoice_gap: 'Missing invoice numbers',
  renumbered: 'Invoice renumbered',
}

/** An offline sale: what did not match when it was uploaded, and the manager's review. */
function OfflineReview({ sale, canReview, onReviewed }: { sale: Sale; canReview: boolean; onReviewed: (s: Sale) => void }) {
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const issues = (sale.sync_issues ?? []).filter((i) => i.code !== 'reviewed')
  const review = (sale.sync_issues ?? []).find((i) => i.code === 'reviewed')

  if (sale.sync_status === 'ok') {
    return <Alert tone="info">Made while offline and uploaded later. Everything matched.</Alert>
  }
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      onReviewed(await salesApi.reviewSync(sale.id, notes.trim()))
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <Alert tone={sale.sync_status === 'flagged' ? 'warning' : 'info'} title={sale.sync_status === 'flagged' ? 'Offline sale: needs review' : 'Offline sale: reviewed'}>
      <ul className="mt-1 list-disc space-y-0.5 pl-5">
        {issues.map((i, n) => (
          <li key={n}>
            <span className="font-medium">{ISSUE_TITLES[i.code] ?? i.code}:</span> {i.message}
          </li>
        ))}
      </ul>
      {review && <p className="mt-2">Reviewed: {review.notes}</p>}
      {sale.sync_status === 'flagged' && canReview && (
        <div className="mt-3 flex flex-col gap-2 sm:flex-row">
          <Input aria-label="Review notes" placeholder="What was checked or done" value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} />
          <Button size="sm" onClick={submit} loading={busy} disabled={notes.trim().length < 3}>
            Mark reviewed
          </Button>
        </div>
      )}
      <ApiErrorAlert error={error} className="mt-2" />
    </Alert>
  )
}

/** /sales/:saleId — one sale: lines, payments, refunds; reprint, void, refund. */
export default function SalePage() {
  const id = Number(useParams().saleId)
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const sale = useQuery({ queryKey: salesKeys.sale(id), queryFn: () => salesApi.sale(id), enabled: Number.isFinite(id) })
  // Receipt header, payment methods and who can approve: from the POS set-up.
  const setup = useQuery({ queryKey: posKeys.setup, queryFn: posApi.setup, staleTime: 5 * 60_000, enabled: can('pos.sell'), retry: false })
  const [dialog, setDialog] = useState<'void' | 'refund' | 'reprint' | null>(null)
  const [reprinted, setReprinted] = useState<Sale | null>(null)

  if (sale.isPending) return <PageLoader />
  if (sale.isError) return <ApiErrorAlert error={ApiError.from(sale.error)} />
  const s = sale.data
  const t = s.totals

  const changed = async (next: Sale, message: string) => {
    queryClient.setQueryData(salesKeys.sale(id), next)
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['sales', 'list'] }),
      queryClient.invalidateQueries({ queryKey: cashKeys.all }),
      queryClient.invalidateQueries({ queryKey: ['pos', 'items'] }),
    ])
    setDialog(null)
    toast.success(message)
  }

  const reprint = async () => {
    try {
      setReprinted(await salesApi.reprint(s.id))
      setDialog('reprint')
    } catch (e) {
      toast.error(ApiError.from(e).message)
    }
  }

  const canAct = setup.isSuccess
  const refunded = new Map<number, string>()
  for (const r of s.refunds ?? []) for (const i of r.items) refunded.set(i.sale_item_id, i.qty)

  return (
    <div className="space-y-6">
      <ButtonLink to="/sales" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Sales
      </ButtonLink>
      <PageHeader
        title={`Invoice ${s.invoice_no}`}
        description={`${formatDateTime(s.sold_at)} · ${s.terminal?.name ?? ''} · ${s.cashier?.name ?? ''}`}
        actions={
          <>
            <SaleStatusBadge status={s.status} />
            {s.can?.reprint && (
              <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={reprint} disabled={!canAct}>
                Reprint
              </Button>
            )}
            {s.can?.refund && (
              <Button variant="outline" leftIcon={<Undo2 className="size-4" />} onClick={() => setDialog('refund')} disabled={!canAct}>
                Return items
              </Button>
            )}
            {s.can?.void && (
              <Button variant="danger" leftIcon={<Ban className="size-4" />} onClick={() => setDialog('void')} disabled={!canAct}>
                Void
              </Button>
            )}
          </>
        }
      />
      {s.can?.refund && setup.isError && (
        <Alert tone="info">Start a shift at the POS to process returns or voids.</Alert>
      )}
      {s.origin === 'offline' && (
        <OfflineReview sale={s} canReview={can('sales.review_sync')} onReviewed={(next) => void changed(next, 'Marked as reviewed')} />
      )}
      {s.void && (
        <Alert tone="danger" title="This sale was voided">
          {formatDateTime(s.void.voided_at)} by {s.void.by?.name ?? '—'}
          {s.void.authorized_by ? `, approved by ${s.void.authorized_by.name}` : ''}. Reason: {s.void.reason}
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Items" />
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-[#f8f9fa] text-left text-xs text-ink-subtle uppercase">
                <tr>
                  <th className="px-5 py-2.5 font-medium">Item</th>
                  <th className="px-3 py-2.5 text-right font-medium">Qty</th>
                  <th className="px-3 py-2.5 text-right font-medium">Price</th>
                  <th className="px-3 py-2.5 text-right font-medium">Discount</th>
                  <th className="px-5 py-2.5 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line-soft">
                {s.items.map((i) => {
                  const discount = [i.line_discount, i.allocated_discount, i.sc_pwd_discount].filter((v) => !isZero(v))
                  return (
                    <tr key={i.id}>
                      <td className="px-5 py-2.5">
                        <span className="block font-medium text-ink">{i.name}</span>
                        <span className="text-xs text-ink-subtle">
                          {i.sku}
                          {!isZero(i.qty_refunded) && <> · {trimQty(i.qty_refunded)} returned</>}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-right whitespace-nowrap">
                        {trimQty(i.qty)} {i.unit}
                      </td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{formatMoney(i.unit_price)}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{discount.length ? `−${discount.map((d) => formatMoney(d)).join(' −')}` : '—'}</td>
                      <td className="px-5 py-2.5 text-right font-medium tabular-nums text-ink">{formatMoney(i.line_total)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Card>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Totals" />
            <CardBody>
              <DetailList
                items={[
                  { label: 'Subtotal', value: formatMoney(t.gross) },
                  ...(!isZero(t.line_discount) ? [{ label: 'Item discounts', value: `−${formatMoney(t.line_discount)}` }] : []),
                  ...(!isZero(t.transaction_discount) ? [{ label: 'Sale discount', value: `−${formatMoney(t.transaction_discount)}` }] : []),
                  ...(!isZero(t.loyalty_discount) ? [{ label: 'Points used', value: `−${formatMoney(t.loyalty_discount)}` }] : []),
                  ...(!isZero(t.sc_pwd_discount) ? [{ label: 'Senior/PWD discount', value: `−${formatMoney(t.sc_pwd_discount)}` }] : []),
                  { label: 'VAT', value: formatMoney(t.vat_amount) },
                  { label: 'Total', value: <span className="text-base">{formatMoney(t.total)}</span> },
                  ...(t.cogs ? [{ label: 'Cost of goods', value: formatMoney(t.cogs) }] : []),
                ]}
              />
            </CardBody>
          </Card>
          <Card>
            <CardHeader title="Payment" />
            <CardBody>
              <DetailList
                items={[
                  ...s.payments.map((p) => ({ label: `${p.method ?? 'Payment'}${p.reference_no ? ` · ${p.reference_no}` : ''}`, value: formatMoney(p.tendered ?? p.amount) })),
                  { label: 'Change', value: formatMoney(t.change) },
                  ...(s.customer ? [{ label: 'Customer', value: s.customer.name }] : []),
                ]}
              />
            </CardBody>
          </Card>
        </div>
      </div>

      {(s.refunds ?? []).length > 0 && (
        <Card>
          <CardHeader title="Returns" />
          <ul className="divide-y divide-line-soft">
            {s.refunds!.map((r) => (
              <li key={r.id} className="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-medium text-ink">
                    {r.number} · {formatMoney(r.total)}
                  </p>
                  <p className="text-xs text-ink-subtle">
                    {formatDateTime(r.refunded_at)} · {r.processed_by?.name}
                    {r.authorized_by ? `, approved by ${r.authorized_by.name}` : ''} · {r.reason}
                  </p>
                  <p className="text-xs text-ink-subtle">
                    {r.items
                      .map((i) => `${trimQty(i.qty)} × ${s.items.find((x) => x.id === i.sale_item_id)?.name ?? 'item'} (${RESTOCK[i.restock_to]})`)
                      .join(', ')}
                  </p>
                </div>
                <p className="text-sm text-ink-muted">{r.payments.length ? r.payments.map((p) => `${p.method} ${formatMoney(p.amount)}`).join(', ') : 'Credited to account'}</p>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {dialog === 'void' && setup.data && (
        <VoidModal sale={s} approvers={setup.data.approvers} onClose={() => setDialog(null)} onVoided={(next) => void changed(next, `Invoice ${s.invoice_no} voided`)} />
      )}
      {dialog === 'refund' && setup.data && (
        <RefundModal
          sale={s}
          setup={setup.data}
          onClose={() => setDialog(null)}
          onRefunded={({ refund, sale: next }) => void changed(next, refundMessage(refund))}
        />
      )}
      {dialog === 'reprint' && reprinted && setup.data && <SaleDoneModal sale={reprinted} setup={setup.data} reprint onClose={() => setDialog(null)} />}
    </div>
  )
}
