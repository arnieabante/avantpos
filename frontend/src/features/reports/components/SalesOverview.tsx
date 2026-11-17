import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { AlertTriangle, Banknote, ShoppingBag, TrendingUp, Wallet } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Card, CardBody, CardHeader, Select, Skeleton, StatCard } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatMoney, formatNumber } from '@/lib/format'
import { reportKeys, reportsApi } from '../api'
import type { Kpis } from '../types'
import { BarChart } from './BarChart'

/** Change vs yesterday, as text (display only). */
function versus(today: string, yesterday: string): string {
  const t = Number(today)
  const y = Number(yesterday)
  if (y === 0) return t > 0 ? 'No sales yesterday' : 'Same as yesterday'
  const pct = Math.round(((t - y) / y) * 100)
  return `${pct >= 0 ? '+' : ''}${pct}% vs yesterday (${formatMoney(yesterday)})`
}

/** Home dashboard: today's sales, this month, the last 30 days, top products. */
export function SalesOverview() {
  const { account } = useAuth()
  const branches = account?.branches ?? []
  const [branch, setBranch] = useState<number | 'all'>('all')
  const q = useQuery({ queryKey: reportKeys.dashboard(branch), queryFn: () => reportsApi.dashboard(branch), placeholderData: keepPreviousData, refetchInterval: 120_000 })

  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const d = q.data
  const month: Partial<Kpis> = d?.month ?? {}

  return (
    <section aria-label="Sales overview" className="space-y-4">
      {branches.length > 1 && (
        <div className="flex justify-end">
          <Select aria-label="Branch" className="w-52" value={String(branch)} onChange={(e) => setBranch(e.target.value === 'all' ? 'all' : Number(e.target.value))}>
            <option value="all">All my branches</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
        </div>
      )}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {d ? (
          <>
            <StatCard label="Net sales today" value={formatMoney(d.today.net_sales)} icon={<TrendingUp className="size-5" />} footnote={versus(d.today.net_sales, d.yesterday.net_sales)} />
            <StatCard label="Sales today" value={formatNumber(d.today.transactions)} icon={<ShoppingBag className="size-5" />} footnote={`Average ${formatMoney(d.today.average_sale)}`} />
            <StatCard label="Cash collected today" value={formatMoney(d.today.cash_collected)} icon={<Banknote className="size-5" />} footnote={`${d.open_shifts} shift${d.open_shifts === 1 ? '' : 's'} open`} />
            {d.can_see_costs ? (
              <StatCard label="Gross profit this month" value={formatMoney(month.gross_profit)} icon={<Wallet className="size-5" />} footnote={month.margin ? `${month.margin}% margin · expenses ${formatMoney(month.expenses)}` : `Expenses ${formatMoney(month.expenses)}`} />
            ) : (
              <StatCard label="Net sales this month" value={formatMoney(month.net_sales)} icon={<Wallet className="size-5" />} footnote={`${formatNumber(month.transactions ?? 0)} sales`} />
            )}
          </>
        ) : (
          [0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-36" />)
        )}
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Net sales, last 30 days" actions={<Link to="/reports/sales" className="text-sm text-brand-500">Sales report</Link>} />
          <CardBody>
            {d ? <BarChart data={d.series.map((s) => ({ label: s.date, value: s.net_sales, hint: s.date }))} label="Net sales per day, last 30 days" /> : <Skeleton className="h-44" />}
          </CardBody>
        </Card>
        <Card>
          <CardHeader title={d?.top_products ? 'Top products this month' : 'Payments today'} />
          <CardBody className="py-2">
            {!d ? (
              <Skeleton className="h-40" />
            ) : d.top_products ? (
              d.top_products.length === 0 ? (
                <p className="py-4 text-sm text-ink-subtle">No sales yet this month.</p>
              ) : (
                <ol className="divide-y divide-line-soft text-sm">
                  {d.top_products.map((p, i) => (
                    <li key={i} className="flex justify-between gap-3 py-2">
                      <span className="truncate text-ink">{p.name}</span>
                      <span className="tabular-nums">{formatMoney(String(p.net_sales))}</span>
                    </li>
                  ))}
                </ol>
              )
            ) : (
              <ul className="divide-y divide-line-soft text-sm">
                {d.payments_today.map((p, i) => (
                  <li key={i} className="flex justify-between gap-3 py-2">
                    <span className="text-ink">{p.method}</span>
                    <span className="tabular-nums">{formatMoney(String(p.net_collected))}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      {d && d.low_stock > 0 && (
        <Link to="/reports/low-stock" className="flex items-center gap-2 rounded-md border border-[#ffd27a] bg-[#fff2d6] px-4 py-3 text-sm text-[#8a5a00]">
          <AlertTriangle className="size-4" aria-hidden="true" />
          {d.low_stock} item{d.low_stock === 1 ? ' is' : 's are'} low or out of stock. See the low-stock report.
        </Link>
      )}
    </section>
  )
}
