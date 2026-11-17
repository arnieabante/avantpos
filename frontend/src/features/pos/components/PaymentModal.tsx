import { useState } from 'react'
import { Banknote, CreditCard, Smartphone, Trash2, UserRound } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, IconButton, Input, Modal } from '@/components/ui'
import type { ApiError } from '@/lib/api/errors'
import { compareDecimal, subtractMoney } from '@/lib/decimal'
import { formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { uuid } from '@/lib/uuid'
import { cashSuggestions, summarize, type Tender } from '../payments'
import type { PaymentMethodOption } from '../types'

const ICONS: Partial<Record<PaymentMethodOption['type'], typeof Banknote>> = {
  cash: Banknote,
  card: CreditCard,
  gcash: Smartphone,
  maya: Smartphone,
  credit: UserRound,
}

interface Props {
  total: string
  methods: PaymentMethodOption[]
  /** Customer on the sale, for "Charge to account". */
  customer: { name: string; allow_credit: boolean; available_credit: string | null } | null
  busy: boolean
  error: ApiError | null
  onSubmit: (tenders: Tender[]) => void
  onClose: () => void
}

/** Split payments: cash, e-wallets, card, charge to account. Change only from cash. */
export function PaymentModal({ total, methods, customer, busy, error, onSubmit, onClose }: Props) {
  const cash = methods.find((m) => m.type === 'cash')
  const [tenders, setTenders] = useState<Tender[]>(() => (cash ? [{ key: uuid(), method: cash, amount: '', reference: '' }] : []))
  const summary = summarize(tenders, total)
  const usable = methods.filter((m) => m.type !== 'credit' || customer?.allow_credit)
  const cashTender = tenders.find((t) => t.method.type === 'cash')
  const otherPaid = summarize(tenders.filter((t) => t !== cashTender), '0').paid
  const left = subtractMoney(total, otherPaid) ?? '0.00'
  const cashDue = compareDecimal(left, '0') === 1 ? left : '0.00'

  const setTender = (key: string, patch: Partial<Tender>) => setTenders((ts) => ts.map((t) => (t.key === key ? { ...t, ...patch } : t)))

  const addMethod = (method: PaymentMethodOption) => {
    const existing = tenders.find((t) => t.method.id === method.id)
    if (existing) return
    // A new non-cash payment takes whatever is still due; cash is typed or picked.
    const remaining = summarize(tenders, total).remaining
    setTenders((ts) => [
      ...ts.filter((t) => t.amount.trim() !== '' || t.method.type !== 'cash' || method.type === 'cash'),
      { key: uuid(), method, amount: method.type === 'cash' || remaining === '0.00' ? '' : remaining, reference: '' },
    ])
  }

  const complete = () => {
    if (!summary.problem && !busy) onSubmit(tenders)
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      dismissible={!busy}
      title="Payment"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Back to cart
          </Button>
          <Button size="lg" onClick={complete} loading={busy} disabled={!!summary.problem}>
            Complete sale
          </Button>
        </>
      }
    >
      <form
        className="space-y-5"
        onSubmit={(e) => {
          e.preventDefault()
          complete()
        }}
      >
        <div className="rounded-md bg-brand-100/60 p-4 text-center">
          <p className="text-sm text-ink-muted">Amount due</p>
          <p className="text-3xl font-semibold text-ink tabular-nums" data-testid="amount-due">
            {formatMoney(total)}
          </p>
        </div>

        <div role="group" aria-label="Payment methods" className="flex flex-wrap gap-2">
          {usable.map((m) => {
            const Icon = ICONS[m.type] ?? Banknote
            const active = tenders.some((t) => t.method.id === m.id)
            return (
              <button
                key={m.id}
                type="button"
                onClick={() => addMethod(m)}
                aria-pressed={active}
                className={cn(
                  'inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium transition',
                  active ? 'border-brand-500 bg-brand-100/50 text-brand-600' : 'border-line text-ink hover:border-brand-500',
                )}
              >
                <Icon className="size-4" aria-hidden="true" />
                {m.name}
              </button>
            )
          })}
        </div>
        {customer && customer.allow_credit && (
          <p className="text-xs text-ink-subtle">
            {customer.name} can charge up to {formatMoney(customer.available_credit ?? '0')} to their account.
          </p>
        )}

        <div className="space-y-3">
          {tenders.map((t, i) => (
            <div key={t.key} className="rounded-md border border-line p-3">
              <div className="flex items-center gap-2">
                <span className="w-32 shrink-0 text-sm font-medium text-ink">{t.method.name}</span>
                <Input
                  aria-label={`${t.method.name} amount`}
                  value={t.amount}
                  onChange={(e) => setTender(t.key, { amount: e.target.value.replace(/[^\d.]/g, '') })}
                  inputMode="decimal"
                  leftAddon="₱"
                  placeholder="0.00"
                  className="text-right text-lg tabular-nums"
                  data-autofocus={i === 0 ? true : undefined}
                />
                <IconButton label={`Remove ${t.method.name}`} onClick={() => setTenders((ts) => ts.filter((x) => x.key !== t.key))}>
                  <Trash2 className="size-4" />
                </IconButton>
              </div>
              {t.method.type === 'cash' && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {cashSuggestions(cashDue).map((amount) => (
                    <Button key={amount} size="sm" variant="outline" onClick={() => setTender(t.key, { amount })}>
                      {formatMoney(amount)}
                    </Button>
                  ))}
                </div>
              )}
              {t.method.requires_reference && (
                <Input
                  className="mt-2"
                  aria-label={`${t.method.name} reference number`}
                  placeholder="Reference number"
                  value={t.reference}
                  maxLength={100}
                  onChange={(e) => setTender(t.key, { reference: e.target.value })}
                />
              )}
            </div>
          ))}
        </div>

        <div className="grid grid-cols-2 gap-4 border-t border-line pt-4 text-center">
          <div>
            <p className="text-sm text-ink-muted">Paid</p>
            <p className="text-xl font-medium text-ink tabular-nums">{formatMoney(summary.paid)}</p>
          </div>
          <div>
            <p className="text-sm text-ink-muted">Change</p>
            <p className="text-2xl font-semibold text-[#71dd37] tabular-nums" data-testid="change">
              {formatMoney(summary.change)}
            </p>
          </div>
        </div>
        {summary.problem && tenders.some((t) => t.amount.trim() !== '') && <p className="text-sm text-[#ff3e1d]">{summary.problem}</p>}
        <ApiErrorAlert error={error} />
        <button type="submit" hidden />
      </form>
    </Modal>
  )
}
