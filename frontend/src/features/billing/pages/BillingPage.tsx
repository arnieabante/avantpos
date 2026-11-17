import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router'
import { Check, CreditCard, ExternalLink, FileText, Upload } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, CardBody, CardHeader, FormField, Input, Modal, PageHeader, PageLoader, Textarea, useConfirm, useToast } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import type { PlanLimitKey } from '@/features/auth/types'
import { todayIn } from '@/features/reports/dates'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'
import { FEATURE_LABELS, STATUS_LABEL, billingApi, billingKeys, type Billing, type BillingInvoice, type BillingPlan, type Cycle } from '../api'
import { InvoiceModal } from '../components/InvoiceModal'

const LIMITS: Array<[PlanLimitKey, string, string, string]> = [
  ['branches', 'Branches', 'branch', 'branches'],
  ['users', 'Users', 'user', 'users'],
  ['terminals', 'POS terminals', 'POS terminal', 'POS terminals'],
  ['products', 'Products', 'product', 'products'],
]

/** /settings/billing — the business's AvantPOS plan, invoices and payments. */
export default function BillingPage() {
  const { can, refresh } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [params] = useSearchParams()
  const q = useQuery({ queryKey: billingKeys.all, queryFn: billingApi.get })
  const [cycle, setCycle] = useState<Cycle>('monthly')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [manual, setManual] = useState<BillingInvoice | null>(null)
  const [viewing, setViewing] = useState<number | null>(null)
  const manage = can('billing.manage')

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const b = q.data
  const s = b.subscription
  const openInvoice = b.invoices.find((i) => i.status === 'open')

  const reload = async () => {
    await queryClient.invalidateQueries({ queryKey: billingKeys.all })
    await refresh()
  }

  const choose = async (plan: BillingPlan) => {
    const price = cycle === 'annual' ? plan.annual_price : plan.monthly_price
    const ok = await confirm({
      title: `Switch to ${plan.name}?`,
      description: plan.free ? 'Your current paid period continues; you move to Free when it ends.' : `${formatMoney(price)} per ${cycle === 'annual' ? 'year' : 'month'} (VAT included).`,
      confirmLabel: plan.free ? 'Move to Free' : 'Continue',
    })
    if (!ok) return
    setBusy(plan.code)
    setError(null)
    try {
      const res = await billingApi.checkout(plan.code, cycle)
      await reload()
      if (res.checkout_url) {
        window.location.assign(res.checkout_url)
        return
      }
      toast.success(res.message)
      if (res.invoice && res.invoice.status === 'open') setManual(res.invoice)
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(null)
  }

  const payOnline = async (invoice: BillingInvoice) => {
    setBusy(`pay-${invoice.id}`)
    setError(null)
    try {
      const res = await billingApi.pay(invoice.id)
      if (res.checkout_url) window.location.assign(res.checkout_url)
      else setManual(res.invoice)
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(null)
  }

  const cancelOrResume = async () => {
    if (!s.cancel_at_period_end) {
      const ok = await confirm({
        title: 'Cancel your subscription?',
        description: `You keep ${s.plan.name} until ${formatDate(s.current_period_end ?? s.trial_ends_at)}, then your account becomes read-only. Your data is kept and you can choose a plan again any time.`,
        confirmLabel: 'Cancel subscription',
        danger: true,
      })
      if (!ok) return
    }
    setBusy('cancel')
    try {
      toast.success(s.cancel_at_period_end ? await billingApi.resume() : await billingApi.cancel())
      await reload()
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(null)
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Subscription" description="Your AvantPOS plan, invoices and payments. Prices include VAT." />
      {params.get('paid') && <Alert tone="success" title="Thank you!">Your payment is being confirmed. This page updates once the payment provider tells us it went through.</Alert>}
      <StatusAlert billing={b} />
      <ApiErrorAlert error={error} />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Current plan" actions={<Badge tone={STATUS_LABEL[s.status].tone}>{STATUS_LABEL[s.status].label}</Badge>} />
          <CardBody className="space-y-3">
            <p className="text-2xl font-semibold text-ink">
              {s.plan.name} <span className="text-base font-normal text-ink-muted">· {s.plan.free ? 'free' : s.billing_cycle === 'annual' ? 'billed yearly' : 'billed monthly'}</span>
            </p>
            <p className="text-sm">
              {s.status === 'trialing' && `Free trial until ${formatDate(s.trial_ends_at)}.`}
              {s.status === 'active' && s.current_period_end && `${s.cancel_at_period_end ? 'Ends' : 'Renews'} on ${formatDate(s.current_period_end)}.`}
              {s.pending_plan && ` Changes to ${s.pending_plan.name} on ${formatDate(s.current_period_end)}.`}
            </p>
            {manage && !s.plan.free && (s.status === 'trialing' || s.status === 'active') && (
              <Button variant="outline" size="sm" loading={busy === 'cancel'} onClick={cancelOrResume}>
                {s.cancel_at_period_end ? 'Keep my subscription' : 'Cancel subscription'}
              </Button>
            )}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Usage" />
          <CardBody className="space-y-3">
            {LIMITS.map(([key, label]) => {
              const limit = b.plans.find((p) => p.current)?.limits[key] ?? null
              const used = b.usage[key]
              const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0
              return (
                <div key={key}>
                  <div className="flex justify-between text-sm">
                    <span>{label}</span>
                    <span className="text-ink">
                      {formatNumber(used)} / {limit === null ? 'unlimited' : formatNumber(limit)}
                    </span>
                  </div>
                  {limit !== null && (
                    <div className="mt-1 h-1.5 rounded-full bg-line-soft">
                      <div className={cn('h-1.5 rounded-full', pct >= 90 ? 'bg-[#ff3e1d]' : 'bg-brand-500')} style={{ width: `${pct}%` }} />
                    </div>
                  )}
                </div>
              )
            })}
          </CardBody>
        </Card>
      </div>

      <section aria-labelledby="plans" className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 id="plans" className="text-lg font-medium text-ink">
            Plans
          </h2>
          <div role="radiogroup" aria-label="Billing" className="inline-flex rounded-md border border-line bg-surface p-1">
            {(['monthly', 'annual'] as const).map((c) => (
              <button
                key={c}
                type="button"
                role="radio"
                aria-checked={cycle === c}
                onClick={() => setCycle(c)}
                className={cn('rounded px-3 py-1.5 text-sm font-medium', cycle === c ? 'bg-brand-500 text-white' : 'text-ink hover:text-brand-500')}
              >
                {c === 'monthly' ? 'Monthly' : 'Yearly (2 months free)'}
              </button>
            ))}
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {b.plans.map((p) => (
            <Card key={p.code} className={cn('flex flex-col p-5', p.current && 'ring-2 ring-brand-500')}>
              <div className="flex items-start justify-between gap-2">
                <p className="text-lg font-semibold text-ink">{p.name}</p>
                {p.current && <Badge tone="brand">Current</Badge>}
              </div>
              <p className="mt-2 text-2xl font-semibold text-ink">
                {p.free ? 'Free' : formatMoney(cycle === 'annual' ? p.annual_price : p.monthly_price)}
                {!p.free && <span className="text-sm font-normal text-ink-muted"> / {cycle === 'annual' ? 'year' : 'month'}</span>}
              </p>
              {p.description && <p className="mt-1 text-sm">{p.description}</p>}
              <ul className="mt-3 space-y-1 text-sm">
                {LIMITS.map(([key, , one, many]) => (
                  <li key={key}>
                    {p.limits[key] === null ? `Unlimited ${many}` : `${formatNumber(p.limits[key] ?? 0)} ${p.limits[key] === 1 ? one : many}`}
                  </li>
                ))}
                {Object.entries(p.features)
                  .filter(([, on]) => on)
                  .map(([k]) => (
                    <li key={k} className="flex items-center gap-1.5">
                      <Check className="size-3.5 text-[#71dd37]" aria-hidden="true" />
                      {FEATURE_LABELS[k] ?? k}
                    </li>
                  ))}
              </ul>
              {manage && !(p.current && p.free) && (
                <Button className="mt-4" variant={p.current ? 'outline' : 'primary'} loading={busy === p.code} disabled={!!busy} onClick={() => choose(p)}>
                  {p.current ? (s.status === 'active' ? 'Renew' : 'Pay for this plan') : p.free ? 'Move to Free' : 'Choose'}
                </Button>
              )}
            </Card>
          ))}
        </div>
      </section>

      <Card>
        <CardHeader title="Invoices" />
        {b.invoices.length === 0 ? (
          <CardBody className="text-sm text-ink-subtle">No invoices yet.</CardBody>
        ) : (
          <ul className="divide-y divide-line-soft">
            {b.invoices.map((i) => {
              const pending = i.payments.find((p) => p.status === 'pending')
              const rejected = [...i.payments].reverse().find((p) => p.status === 'failed' && p.rejection_reason)
              return (
                <li key={i.id} className="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-ink">
                      {i.number} · {i.plan?.name} ({i.billing_cycle}) · {formatMoney(i.total)}
                    </p>
                    <p className="text-xs text-ink-subtle">
                      {i.status === 'paid' ? `Paid ${formatDate(i.paid_at)}` : i.status === 'open' ? `Due ${formatDate(i.due_at)}` : i.status === 'void' ? 'Cancelled' : 'Expired'}
                      {pending && ' · bank transfer waiting for verification'}
                      {rejected && i.status === 'open' && ` · last transfer not accepted: ${rejected.rejection_reason}`}
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" variant="ghost" leftIcon={<FileText className="size-4" />} onClick={() => setViewing(i.id)}>
                      View
                    </Button>
                    {manage && i.status === 'open' && !pending && (
                      <>
                        {b.payment.gateway !== 'manual' && (
                          <Button size="sm" leftIcon={<CreditCard className="size-4" />} loading={busy === `pay-${i.id}`} onClick={() => payOnline(i)}>
                            Pay online
                          </Button>
                        )}
                        <Button size="sm" variant="outline" leftIcon={<Upload className="size-4" />} onClick={() => setManual(i)}>
                          I paid by transfer
                        </Button>
                      </>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Card>
      {openInvoice?.checkout_url && (
        <p className="text-sm">
          <a className="inline-flex items-center gap-1 text-brand-500 hover:underline" href={openInvoice.checkout_url}>
            Continue to the payment page for {openInvoice.number} <ExternalLink className="size-3.5" />
          </a>
        </p>
      )}

      {manual && (
        <ManualPaymentModal
          invoice={manual}
          bankDetails={b.payment.bank_details}
          onClose={() => setManual(null)}
          onSent={async () => {
            setManual(null)
            await reload()
            toast.success('Thank you! We will confirm your transfer shortly.')
          }}
        />
      )}
      {viewing && <InvoiceModal invoiceId={viewing} onClose={() => setViewing(null)} />}
    </div>
  )
}

function StatusAlert({ billing }: { billing: Billing }) {
  const s = billing.subscription
  if (s.status === 'past_due' || s.status === 'grace')
    return (
      <Alert tone="warning" title="Payment overdue">
        Pay your open invoice by {formatDate(s.grace_ends_at)} to avoid interruption. After that your account becomes read-only until it is paid.
      </Alert>
    )
  if (s.status === 'expired')
    return (
      <Alert tone="danger" title="Your subscription has expired">
        Your records are safe, but you cannot record sales or changes until you pay your invoice or choose a plan below.
      </Alert>
    )
  if (s.status === 'trialing')
    return <Alert tone="info">You are on a free trial of {s.plan.name} until {formatDate(s.trial_ends_at)}. Choose a plan any time.</Alert>
  return null
}

function ManualPaymentModal({ invoice, bankDetails, onSent, onClose }: { invoice: BillingInvoice; bankDetails: string | null; onSent: () => void; onClose: () => void }) {
  const [v, setV] = useState({ reference: '', channel: '', amount: invoice.total, paid_on: todayIn(), notes: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const send = async () => {
    setBusy(true)
    setError(null)
    try {
      await billingApi.manualPayment(invoice.id, { ...v, reference: v.reference.trim(), channel: v.channel.trim(), notes: v.notes.trim() || null })
      onSent()
    } catch (e) {
      const err = ApiError.from(e)
      if (err.isValidation) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={`Pay ${invoice.number} by transfer`}
      description={`Amount due: ${formatMoney(invoice.total)}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Later
          </Button>
          <Button onClick={send} loading={busy}>
            Send for verification
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <div className="rounded-md bg-brand-100/50 p-3 text-sm whitespace-pre-line text-ink">
          {bankDetails ?? 'AvantPOS will send you the account details for bank transfer or GCash. Contact support if you have not received them.'}
          {`\nUse invoice number ${invoice.number} as the reference.`}
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Paid through" required hint="e.g. BPI, BDO, GCash" error={errors.channel}>
            <Input value={v.channel} maxLength={40} onChange={(e) => setV({ ...v, channel: e.target.value })} data-autofocus />
          </FormField>
          <FormField label="Reference number" required error={errors.reference}>
            <Input value={v.reference} maxLength={120} onChange={(e) => setV({ ...v, reference: e.target.value })} />
          </FormField>
          <FormField label="Amount sent" required error={errors.amount}>
            <Input value={v.amount} inputMode="decimal" leftAddon="₱" onChange={(e) => setV({ ...v, amount: e.target.value.replace(/[^\d.]/g, '') })} />
          </FormField>
          <FormField label="Date sent" required error={errors.paid_on}>
            <Input type="date" value={v.paid_on} max={todayIn()} onChange={(e) => setV({ ...v, paid_on: e.target.value })} />
          </FormField>
        </div>
        <FormField label="Notes">
          <Textarea rows={2} maxLength={500} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  )
}
