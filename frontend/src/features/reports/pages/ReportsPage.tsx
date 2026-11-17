import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { BarChart3, Lock } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Card, PageHeader, PageLoader } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { reportKeys, reportsApi } from '../api'
import { REPORTS } from '../catalog'

/** /reports — every report the user may open, grouped. */
export default function ReportsPage() {
  const access = useQuery({ queryKey: reportKeys.access, queryFn: reportsApi.access, staleTime: 60_000 })

  if (access.isPending) return <PageLoader />
  if (access.isError) return <ApiErrorAlert error={ApiError.from(access.error)} />
  const byKey = new Map(access.data.reports.map((r) => [r.key, r]))
  const visible = REPORTS.filter((r) => byKey.get(r.key)?.allowed)
  const groups = [...new Set(visible.map((r) => r.group))]

  return (
    <>
      <PageHeader title="Reports" description="Sales, profit, tax, inventory, purchases and expenses. Filter by date and branch; print or export." />
      <div className="space-y-8">
        {groups.map((group) => (
          <section key={group} aria-labelledby={`g-${group}`}>
            <h2 id={`g-${group}`} className="mb-3 text-sm font-semibold tracking-wide text-ink-subtle uppercase">
              {group}
            </h2>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {visible
                .filter((r) => r.group === group)
                .map((r) => {
                  const available = byKey.get(r.key)?.available !== false
                  const body = (
                    <Card className="h-full p-5 transition hover:shadow-raised">
                      <div className="flex items-start gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-brand-100 text-brand-500">
                          {available ? <BarChart3 className="size-4.5" /> : <Lock className="size-4.5" />}
                        </span>
                        <div>
                          <p className="font-medium text-ink">
                            {r.title} {!available && <Badge tone="warning">Upgrade</Badge>}
                          </p>
                          <p className="mt-1 text-sm">{r.description}</p>
                        </div>
                      </div>
                    </Card>
                  )
                  return available ? (
                    <Link key={r.key} to={`/reports/${r.key}`} className="block rounded-md focus-visible:outline-2">
                      {body}
                    </Link>
                  ) : (
                    <div key={r.key} title="Not included in your current plan" className="opacity-70">
                      {body}
                    </div>
                  )
                })}
            </div>
          </section>
        ))}
        {visible.length === 0 && <p className="text-sm text-ink-subtle">You do not have access to any report.</p>}
      </div>
    </>
  )
}
