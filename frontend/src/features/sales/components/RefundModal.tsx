import { useState } from 'react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, FormField, Input, Modal, Select, Textarea } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApprovalModal } from '@/features/pos/components/ApprovalModal'
import type { PosSetup, Sale, SaleRefund } from '@/features/pos/types'
import { ApiError } from '@/lib/api/errors'
import { trimQty } from '@/lib/decimal'
import { formatMoney } from '@/lib/format'
import { uuid } from '@/lib/uuid'
import { salesApi, type RefundInput } from '../api'
import { isQty, lineRefund, remainingQty, sumCents } from '../refund'

type Restock = RefundInput['items'][number]['restock']

const RESTOCK_LABELS: Record<Restock, string> = {
  sellable: 'Back to stock',
  damaged: 'Damaged (write off)',
  none: 'Not returned',
}

interface Props {
  sale: Sale
  setup: Pick<PosSetup, 'approvers' | 'payment_methods'>
  onRefunded: (result: { refund: SaleRefund; sale: Sale }) => void
  onClose: () => void
}

/**
 * Return items from a sale. Each line gives back its share of what was
 * charged; credit sales go back to the customer's account first.
 */
export function RefundModal({ sale, setup, onRefunded, onClose }: Props) {
  const { can, account } = useAuth()
  const lines = sale.items.filter((i) => remainingQty(i) !== '0')
  const [qty, setQty] = useState<Record<number, string>>({})
  const [restock, setRestock] = useState<Record<number, Restock>>({})
  const methods = setup.payment_methods.filter((m) => m.type !== 'credit')
  const [methodId, setMethodId] = useState<number | null>(methods.find((m) => m.type === 'cash')?.id ?? methods[0]?.id ?? null)
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [approving, setApproving] = useState(false)
  // One key per refund attempt: retrying after a network error does not refund twice.
  const [requestId] = useState(uuid)

  const chosen = lines.filter((i) => (qty[i.id] ?? '').trim() !== '' && (qty[i.id] ?? '').trim() !== '0')
  const amounts = chosen.map((i) => lineRefund(sale, i, qty[i.id]!))
  const total = sumCents(amounts)
  const charged = sale.payments.some((p) => p.type === 'credit')

  const submit = async (token: string | null) => {
    setBusy(true)
    setError(null)
    try {
      onRefunded(
        await salesApi.refund(sale.id, {
          client_uuid: requestId,
          reason: reason.trim(),
          items: chosen.map((i) => ({ sale_item_id: i.id, qty: qty[i.id]!.trim(), restock: restock[i.id] ?? 'sellable' })),
          payment_method_id: methodId,
          override_token: token,
        }),
      )
    } catch (e) {
      const err = ApiError.from(e)
      if (err.code === 'APPROVAL_REQUIRED' || err.code === 'OVERRIDE_REQUIRED') setApproving(true)
      else if (err.isValidation) {
        setErrors(
          Object.fromEntries(
            Object.entries(err.errors).map(([k, v]) => {
              const m = /^items\.(\d+)\./.exec(k)
              return [m ? `line-${chosen[Number(m[1])]?.id}` : k, v[0] ?? '']
            }),
          ),
        )
      } else setError(err)
      setBusy(false)
    }
  }

  const start = () => {
    const found: Record<string, string> = {}
    for (const i of lines) {
      const v = (qty[i.id] ?? '').trim()
      if (v && v !== '0' && (!isQty(v) || lineRefund(sale, i, v) === null)) found[`line-${i.id}`] = `Enter up to ${remainingQty(i)}.`
    }
    if (chosen.length === 0) found.items = 'Enter the quantity being returned.'
    if (reason.trim().length < 3) found.reason = 'Say why the items are returned.'
    setErrors(found)
    if (Object.keys(found).length) return
    if (can('sales.refund')) void submit(null)
    else setApproving(true)
  }

  return (
    <>
      <Modal
        open
        onClose={onClose}
        size="lg"
        dismissible={!busy}
        title={`Return items · invoice ${sale.invoice_no}`}
        footer={
          <>
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              Cancel
            </Button>
            <Button onClick={start} loading={busy} disabled={chosen.length === 0}>
              Refund {formatMoney(total)}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <ApiErrorAlert error={error} />
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-ink-subtle uppercase">
                  <th className="py-2 pr-2 font-medium">Item</th>
                  <th className="px-2 py-2 font-medium">Return</th>
                  <th className="px-2 py-2 font-medium">Goes to</th>
                  <th className="py-2 pl-2 text-right font-medium">Refund</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line-soft">
                {lines.map((i, idx) => (
                  <tr key={i.id} className="align-top">
                    <td className="py-2 pr-2">
                      <span className="block font-medium text-ink">{i.name}</span>
                      <span className="text-xs text-ink-subtle">
                        {remainingQty(i)} of {trimQty(i.qty)} {i.unit ?? ''} left · {formatMoney(i.line_total)}
                      </span>
                    </td>
                    <td className="w-28 px-2 py-2">
                      <Input
                        aria-label={`Quantity to return of ${i.name}`}
                        inputMode="decimal"
                        placeholder="0"
                        value={qty[i.id] ?? ''}
                        onChange={(e) => setQty((q) => ({ ...q, [i.id]: e.target.value.replace(/[^\d.]/g, '') }))}
                        data-autofocus={idx === 0 ? true : undefined}
                      />
                      {errors[`line-${i.id}`] && <p className="mt-1 text-xs text-[#ff3e1d]">{errors[`line-${i.id}`]}</p>}
                    </td>
                    <td className="w-44 px-2 py-2">
                      <Select aria-label={`Where ${i.name} goes`} value={restock[i.id] ?? 'sellable'} onChange={(e) => setRestock((r) => ({ ...r, [i.id]: e.target.value as Restock }))}>
                        {(Object.keys(RESTOCK_LABELS) as Restock[]).map((k) => (
                          <option key={k} value={k}>
                            {RESTOCK_LABELS[k]}
                          </option>
                        ))}
                      </Select>
                    </td>
                    <td className="py-2 pl-2 text-right tabular-nums">{lineRefund(sale, i, qty[i.id] ?? '') ? formatMoney(lineRefund(sale, i, qty[i.id] ?? '')) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {errors.items && <p className="text-sm text-[#ff3e1d]">{errors.items}</p>}

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Give money back by" hint={charged ? 'The part charged to the customer’s account is credited back to it first.' : undefined} error={errors.payment_method_id}>
              <Select value={methodId ?? ''} onChange={(e) => setMethodId(Number(e.target.value) || null)}>
                {methods.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Reason" required error={errors.reason}>
              <Textarea rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
            </FormField>
          </div>
        </div>
      </Modal>
      {approving && (
        <ApprovalModal
          permissions={['sales.refund']}
          approvers={setup.approvers}
          selfId={account?.user.id}
          onClose={() => setApproving(false)}
          onApproved={(tokens) => {
            setApproving(false)
            void submit(tokens['sales.refund'] ?? null)
          }}
        />
      )}
    </>
  )
}
