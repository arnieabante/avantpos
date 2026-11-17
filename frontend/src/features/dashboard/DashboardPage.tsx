import { Building2, CheckCircle2, Circle, ListChecks, Receipt, Store, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge, ButtonLink, Card, CardBody, CardHeader, DetailList, PageHeader, StatCard, type Tone } from '@/components/ui'
import type { AccountSubscription, PlanLimitKey } from '@/features/auth/types'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { SalesOverview } from '@/features/reports/components/SalesOverview'
import { daysUntil, formatDate, formatNumber, humanize } from '@/lib/format'
import { BUSINESS_TYPES } from '@/features/auth/types'

const STATUS_TONES: Record<string, Tone> = {
  trialing: 'brand',
  active: 'success',
  past_due: 'warning',
  grace: 'warning',
  expired: 'danger',
  cancelled: 'danger',
}

function greeting(now = new Date()): string {
  const hour = Number(new Intl.DateTimeFormat('en-PH', { hour: 'numeric', hour12: false, timeZone: 'Asia/Manila' }).format(now))
  if (hour < 12) return 'Magandang umaga'
  if (hour < 18) return 'Magandang hapon'
  return 'Magandang gabi'
}

/**
 * Home screen: sales overview (for users who may see sales), then setup
 * steps, business and plan.
 */
export default function DashboardPage() {
  const account = useAccount()
  const { can } = useAuth()
  const firstName = account.user.name.split(' ')[0]

  return (
    <>
      <PageHeader title={`${greeting()}, ${firstName}!`} description={`Here's the status of ${account.tenant.name}.`} />
      {can('reports.sales') && (
        <div className="mb-6">
          <SalesOverview />
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Branches" value={formatNumber(account.branches.length)} icon={<Building2 className="size-5" />} footnote={limitNote(account.subscription, 'branches')} />
        <StatCard label="Your roles" value={account.roles.length > 0 ? account.roles.join(', ') : '—'} icon={<Users className="size-5" />} footnote={`${account.permissions.length} permissions`} />
        <StatCard label="Plan" value={account.subscription?.plan.name ?? 'None'} icon={<Store className="size-5" />} footnote={planNote(account.subscription)} />
        <StatCard label="Tax setup" value={account.tenant.vat_registered ? 'VAT' : 'Non-VAT'} icon={<Receipt className="size-5" />} footnote={`Currency ${account.tenant.currency}`} />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <SetupChecklist />

        <Card>
          <CardHeader title="Business" />
          <CardBody className="py-2">
            <DetailList
              items={[
                { label: 'Name', value: account.tenant.name },
                { label: 'Type', value: BUSINESS_TYPES.find((t) => t.value === account.tenant.business_type)?.label ?? humanize(account.tenant.business_type) },
                { label: 'Web address', value: account.tenant.subdomain ?? '—' },
                { label: 'Timezone', value: account.tenant.timezone },
              ]}
            />
          </CardBody>
        </Card>

        <SubscriptionCard subscription={account.subscription} />
      </div>
    </>
  )
}

function SetupChecklist() {
  const { user } = useAccount()

  const steps: Array<{ done: boolean; title: string; description: string; action?: ReactNode }> = [
    { done: true, title: 'Register your business', description: 'Your AvantPOS account is ready.' },
    {
      done: user.email_verified,
      title: 'Verify your e-mail address',
      description: user.email_verified ? 'Verified.' : 'Use the link we e-mailed you (see the notice above).',
    },
    {
      done: user.has_pin,
      title: 'Set your POS PIN',
      description: 'Used for quick cashier sign-in and supervisor approvals.',
      action: !user.has_pin ? (
        <ButtonLink to="/account" size="sm" variant="outline">
          Set PIN
        </ButtonLink>
      ) : undefined,
    },
  ]

  const completed = steps.filter((s) => s.done).length

  return (
    <Card>
      <CardHeader title="Getting started" description={`${completed} of ${steps.length} done`} actions={<ListChecks className="size-5 text-ink-subtle" aria-hidden="true" />} />
      <CardBody>
        <ol className="space-y-4">
          {steps.map((step) => (
            <li key={step.title} className="flex gap-3">
              {step.done ? (
                <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" aria-label="Done" />
              ) : (
                <Circle className="mt-0.5 size-5 shrink-0 text-line" aria-label="To do" />
              )}
              <div className="min-w-0 flex-1">
                <p className={step.done ? 'text-sm font-medium text-ink-subtle line-through' : 'text-sm font-medium text-ink'}>{step.title}</p>
                <p className="text-sm text-ink-subtle">{step.description}</p>
              </div>
              {step.action}
            </li>
          ))}
        </ol>
      </CardBody>
    </Card>
  )
}

function SubscriptionCard({ subscription }: { subscription: AccountSubscription | null }) {
  if (!subscription) {
    return (
      <Card>
        <CardHeader title="Subscription" />
        <CardBody className="text-sm text-ink-subtle">No subscription found. Contact AvantPOS support.</CardBody>
      </Card>
    )
  }

  const limits = subscription.plan.limits
  const limitLabel = (key: PlanLimitKey) => {
    const value = limits?.[key]
    return value === null || value === undefined ? 'Unlimited' : formatNumber(value)
  }

  return (
    <Card>
      <CardHeader
        title="Subscription"
        actions={<Badge tone={STATUS_TONES[subscription.status] ?? 'neutral'}>{humanize(subscription.status)}</Badge>}
      />
      <CardBody className="py-2">
        <DetailList
          items={[
            { label: 'Plan', value: subscription.plan.name ?? '—' },
            subscription.status === 'trialing'
              ? { label: 'Trial ends', value: formatDate(subscription.trial_ends_at) }
              : { label: 'Renews / ends', value: formatDate(subscription.current_period_end) },
            { label: 'Branches', value: limitLabel('branches') },
            { label: 'Users', value: limitLabel('users') },
            { label: 'POS terminals', value: limitLabel('terminals') },
            { label: 'Products', value: limitLabel('products') },
          ]}
        />
      </CardBody>
    </Card>
  )
}

function limitNote(subscription: AccountSubscription | null, key: PlanLimitKey): string | undefined {
  const limit = subscription?.plan.limits?.[key]
  if (limit === undefined) return undefined
  return limit === null ? 'Unlimited on your plan' : `Up to ${formatNumber(limit)} on your plan`
}

function planNote(subscription: AccountSubscription | null): string | undefined {
  if (!subscription) return undefined
  if (subscription.status === 'trialing') {
    const days = daysUntil(subscription.trial_ends_at)
    return days === null ? 'Free trial' : `Free trial · ${days} day${days === 1 ? '' : 's'} left`
  }
  return humanize(subscription.status)
}
