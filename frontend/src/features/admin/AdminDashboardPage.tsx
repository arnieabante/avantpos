import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Building2, CreditCard, LifeBuoy, TrendingUp } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Card, CardBody, CardHeader, PageHeader, PageLoader, StatCard } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney, formatNumber, humanize } from '@/lib/format'
import { adminApi, adminKeys } from './api'

/** Platform overview: businesses, recurring revenue, sign-ups, renewals, payments, support. */
export default function AdminDashboardPage() {
  const q = useQuery({ queryKey: adminKeys.dashboard, queryFn: adminApi.dashboard, refetchInterval: 120_000 })
  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const d = q.data
  const max = Math.max(1, ...d.signups.map((s) => s.signups))

  return (
    <div className="space-y-6">
      <PageHeader title="Platform overview" description="AvantPOS SaaS administration." />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Businesses" value={formatNumber(d.tenants.total)} icon={<Building2 className="size-5" />} footnote={`${d.tenants.new_7_days} new this week · ${d.tenants.new_30_days} in 30 days`} />
        <StatCard label="MRR" value={formatMoney(d.mrr)} icon={<TrendingUp className="size-5" />} footnote={`ARR ${formatMoney(d.arr)} · ${d.subscriptions.paying} paying`} />
        <StatCard label="Collected (30 days)" value={formatMoney(d.payments.last_30_days)} icon={<CreditCard className="size-5" />} footnote={`${d.payments.count_30_days} payments · ${d.payments.pending_verification} to verify`} />
        <StatCard label="Support" value={formatNumber(d.support.open)} icon={<LifeBuoy className="size-5" />} footnote={`open · ${d.support.waiting} waiting for the business`} />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Sign-ups, last 30 days" />
          <CardBody>
            <div className="flex h-32 items-end gap-1" role="img" aria-label="New businesses per day">
              {d.signups.map((s) => (
                <div key={s.date} title={`${s.date}: ${s.signups}`} className="flex-1 rounded-t bg-brand-500" style={{ height: `${(s.signups / max) * 100}%`, minHeight: s.signups ? 4 : 1, opacity: s.signups ? 1 : 0.2 }} />
              ))}
            </div>
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Subscriptions" />
          <CardBody className="space-y-3 text-sm">
            {Object.entries(d.subscriptions.by_status).map(([k, n]) => (
              <div key={k} className="flex justify-between">
                <span>{humanize(k)}</span>
                <span className="text-ink">{n}</span>
              </div>
            ))}
            <hr className="border-line-soft" />
            {Object.entries(d.subscriptions.by_plan).map(([k, n]) => (
              <div key={k} className="flex justify-between">
                <span>{k}</span>
                <span className="text-ink">{n}</span>
              </div>
            ))}
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Renewals and trials ending" description="Trials ending in 7 days, renewals in 14 days, and overdue subscriptions." />
          <ul className="divide-y divide-line-soft text-sm">
            {d.expiring.length === 0 && <li className="px-5 py-4 text-ink-subtle">Nothing ending soon.</li>}
            {d.expiring.map((e) => (
              <li key={e.tenant.id} className="flex items-center justify-between gap-3 px-5 py-2.5">
                <Link to={`/admin/tenants/${e.tenant.id}`} className="font-medium text-brand-500 hover:underline">
                  {e.tenant.name}
                </Link>
                <span className="flex items-center gap-2">
                  {e.plan} · {formatDate(e.ends_at)}
                  <Badge tone={e.status === 'trialing' ? 'brand' : e.status === 'active' ? (e.cancel_at_period_end ? 'neutral' : 'success') : 'warning'}>{e.cancel_at_period_end ? 'Cancelling' : humanize(e.status)}</Badge>
                </span>
              </li>
            ))}
          </ul>
        </Card>
        <Card>
          <CardHeader title="Recent payments" actions={<Link to="/admin/billing" className="text-sm text-brand-500">All payments</Link>} />
          <ul className="divide-y divide-line-soft text-sm">
            {d.payments.recent.length === 0 && <li className="px-5 py-4 text-ink-subtle">No payments yet.</li>}
            {d.payments.recent.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-3 px-5 py-2.5">
                <span>
                  <span className="text-ink">{p.tenant}</span> · {p.invoice} · {p.channel ?? p.gateway}
                </span>
                <span className="flex items-center gap-2">
                  {formatMoney(p.amount)}
                  {p.status === 'pending' && <Badge tone="warning">To verify</Badge>}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  )
}
