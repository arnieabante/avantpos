import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, ButtonLink, Card, CardBody, CardHeader, DetailList, FormField, Input, Modal, PageHeader, PageLoader, Select, useToast } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, formatMoney, humanize } from '@/lib/format'
import { useAdminAuth } from '../useAdminAuth'
import { adminApi, adminKeys, type TenantDetail } from '../api'
import { TENANT_TONE } from '../labels'

/** /admin/tenants/:tenantId — one business: details, subscription, invoices, history. */
export default function AdminTenantPage() {
  const id = Number(useParams().tenantId)
  const { admin } = useAdminAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const q = useQuery({ queryKey: adminKeys.tenant(id), queryFn: () => adminApi.tenant(id) })
  const [dialog, setDialog] = useState<'suspend' | 'subscription' | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const role = admin?.role ?? ''

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const t = q.data

  const done = (next: TenantDetail, message: string) => {
    queryClient.setQueryData(adminKeys.tenant(id), next)
    void queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] })
    setDialog(null)
    toast.success(message)
  }

  return (
    <div className="space-y-6">
      <ButtonLink to="/admin/tenants" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Businesses
      </ButtonLink>
      <PageHeader
        title={t.name}
        description={`${t.slug}${t.subdomain ? ` · ${t.subdomain}.avantpos.ph` : ''} · joined ${formatDate(t.created_at)}`}
        actions={
          <>
            <Badge tone={TENANT_TONE[t.status] ?? 'neutral'}>{humanize(t.status)}</Badge>
            {['super_admin', 'billing'].includes(role) && (
              <Button variant="outline" onClick={() => setDialog('subscription')}>
                Adjust subscription
              </Button>
            )}
            {role === 'super_admin' &&
              (t.status === 'suspended' ? (
                <Button
                  onClick={async () => {
                    try {
                      done(await adminApi.activate(t.id), `${t.name} re-activated`)
                    } catch (e) {
                      setError(ApiError.from(e))
                    }
                  }}
                >
                  Re-activate
                </Button>
              ) : (
                <Button variant="danger" onClick={() => setDialog('suspend')}>
                  Suspend
                </Button>
              ))}
          </>
        }
      />
      <ApiErrorAlert error={error} />
      {t.status === 'suspended' && <Alert tone="danger" title={`Suspended ${formatDate(t.suspended_at)}`}>{t.suspension_reason}</Alert>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader title="Business" />
          <CardBody className="py-2">
            <DetailList
              items={[
                { label: 'Owner', value: t.owner ? `${t.owner.name} · ${t.owner.email}` : '—' },
                { label: 'Registered name', value: t.registered_name ?? '—' },
                { label: 'TIN', value: `${t.tin ?? '—'}${t.vat_registered ? ' (VAT)' : ''}` },
                { label: 'Phone', value: t.phone ?? '—' },
                { label: 'Type', value: humanize(t.business_type) },
              ]}
            />
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Subscription" />
          <CardBody className="py-2">
            {t.subscription ? (
              <DetailList
                items={[
                  { label: 'Plan', value: `${t.subscription.plan.name} (${t.subscription.billing_cycle})` },
                  { label: 'Status', value: humanize(t.subscription.status) + (t.subscription.cancel_at_period_end ? ' · cancelling' : '') },
                  ...(t.subscription.trial_ends_at ? [{ label: 'Trial ends', value: formatDate(t.subscription.trial_ends_at) }] : []),
                  ...(t.subscription.current_period_end ? [{ label: 'Period ends', value: formatDate(t.subscription.current_period_end) }] : []),
                  ...(t.subscription.grace_ends_at ? [{ label: 'Grace ends', value: formatDate(t.subscription.grace_ends_at) }] : []),
                  ...(t.subscription.pending_plan ? [{ label: 'Changing to', value: t.subscription.pending_plan }] : []),
                ]}
              />
            ) : (
              <p className="py-2 text-sm">No subscription.</p>
            )}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Usage and activity" />
          <CardBody className="py-2">
            <DetailList
              items={[
                { label: 'Branches · users', value: `${t.usage.branches} · ${t.usage.users}` },
                { label: 'POS terminals · products', value: `${t.usage.terminals} · ${t.usage.products}` },
                { label: 'Sales, last 30 days', value: t.activity.sales_30_days },
                { label: 'Last sale', value: t.activity.last_sale_at ? formatDateTime(t.activity.last_sale_at) : '—' },
                { label: 'Last sign-in', value: t.activity.last_login_at ? formatDateTime(t.activity.last_login_at) : '—' },
              ]}
            />
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Invoices" />
          <ul className="divide-y divide-line-soft text-sm">
            {t.invoices.length === 0 && <li className="px-5 py-4 text-ink-subtle">No invoices.</li>}
            {t.invoices.map((i) => (
              <li key={i.id} className="flex justify-between gap-3 px-5 py-2.5">
                <span>
                  {i.number} · {i.plan} · {humanize(i.kind)}
                </span>
                <span>
                  {formatMoney(i.total)} · {i.status === 'paid' ? `paid ${formatDate(i.paid_at)}` : humanize(i.status)}
                </span>
              </li>
            ))}
          </ul>
        </Card>
        <Card>
          <CardHeader title="Subscription history" />
          <ul className="divide-y divide-line-soft text-sm">
            {t.history.length === 0 && <li className="px-5 py-4 text-ink-subtle">No changes yet.</li>}
            {t.history.map((h, n) => (
              <li key={n} className="flex justify-between gap-3 px-5 py-2.5">
                <span>
                  {humanize(h.type)}
                  {h.to && h.from !== h.to ? ` · ${h.from ?? '—'} → ${h.to}` : ''}
                </span>
                <span className="text-ink-subtle">
                  {formatDate(h.effective_at)} · {humanize(h.by)}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      {dialog === 'suspend' && <SuspendModal tenant={t} onClose={() => setDialog(null)} onDone={(n) => done(n, `${t.name} suspended`)} />}
      {dialog === 'subscription' && <SubscriptionModal tenant={t} onClose={() => setDialog(null)} onDone={(n) => done(n, 'Subscription updated')} />}
    </div>
  )
}

function SuspendModal({ tenant, onDone, onClose }: { tenant: TenantDetail; onDone: (t: TenantDetail) => void; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={`Suspend ${tenant.name}?`}
      description="Its users are signed out of the app until it is re-activated. Data is kept."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="danger"
            loading={busy}
            disabled={reason.trim().length < 3}
            onClick={async () => {
              setBusy(true)
              try {
                onDone(await adminApi.suspend(tenant.id, reason.trim()))
              } catch (e) {
                setError(ApiError.from(e))
                setBusy(false)
              }
            }}
          >
            Suspend
          </Button>
        </>
      }
    >
      <ApiErrorAlert error={error} />
      <FormField label="Reason" required hint="Shown to the business when its users try to sign in.">
        <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} data-autofocus />
      </FormField>
    </Modal>
  )
}

function SubscriptionModal({ tenant, onDone, onClose }: { tenant: TenantDetail; onDone: (t: TenantDetail) => void; onClose: () => void }) {
  const plans = useQuery({ queryKey: adminKeys.plans, queryFn: adminApi.plans })
  const s = tenant.subscription
  const [v, setV] = useState({
    plan: s?.plan.code ?? 'free',
    billing_cycle: s?.billing_cycle ?? 'monthly',
    status: s?.status === 'grace' ? 'past_due' : (s?.status ?? 'active'),
    trial_ends_at: s?.trial_ends_at?.slice(0, 10) ?? '',
    current_period_end: s?.current_period_end?.slice(0, 10) ?? '',
    note: '',
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title="Adjust subscription"
      description="For trial extensions, complimentary periods and corrections. Recorded in the history and activity log."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            loading={busy}
            disabled={v.note.trim().length < 3}
            onClick={async () => {
              setBusy(true)
              setError(null)
              try {
                onDone(await adminApi.adjustSubscription(tenant.id, { ...v, trial_ends_at: v.trial_ends_at || null, current_period_end: v.current_period_end || null, note: v.note.trim() }))
              } catch (e) {
                setError(ApiError.from(e))
                setBusy(false)
              }
            }}
          >
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Plan">
            <Select value={v.plan} onChange={(e) => setV({ ...v, plan: e.target.value })}>
              {(plans.data?.plans ?? []).map((p) => (
                <option key={p.code} value={p.code}>
                  {p.name}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Billing">
            <Select value={v.billing_cycle} onChange={(e) => setV({ ...v, billing_cycle: e.target.value as 'monthly' | 'annual' })}>
              <option value="monthly">Monthly</option>
              <option value="annual">Yearly</option>
            </Select>
          </FormField>
          <FormField label="Status">
            <Select value={v.status} onChange={(e) => setV({ ...v, status: e.target.value })}>
              <option value="trialing">Trial</option>
              <option value="active">Active</option>
              <option value="past_due">Payment due</option>
              <option value="expired">Expired</option>
            </Select>
          </FormField>
          {v.status === 'trialing' ? (
            <FormField label="Trial ends" required>
              <Input type="date" value={v.trial_ends_at} onChange={(e) => setV({ ...v, trial_ends_at: e.target.value })} />
            </FormField>
          ) : (
            <FormField label="Paid until" hint="Leave empty for no end (e.g. Free)">
              <Input type="date" value={v.current_period_end} onChange={(e) => setV({ ...v, current_period_end: e.target.value })} />
            </FormField>
          )}
        </div>
        <FormField label="Why" required>
          <Input value={v.note} maxLength={255} onChange={(e) => setV({ ...v, note: e.target.value })} placeholder="e.g. One free month for the referral" />
        </FormField>
      </div>
    </Modal>
  )
}
