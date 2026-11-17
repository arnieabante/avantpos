import { Card, CardBody, CardHeader, DataTable, DetailList, type Column } from '@/components/ui'
import { formatDateTime, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { DENOMINATIONS, MOVEMENT_LABELS, type CashSession } from '../types'

type Movement = NonNullable<CashSession['movements']>[number]

const isZero = (v: string | null | undefined) => !v || /^-?0*(\.0*)?$/.test(v)
/** "−₱150.00" for money going out; a plain "₱0.00" when nothing went out. */
const out = (v: string | undefined) => (isZero(v) ? formatMoney(v ?? '0') : `−${formatMoney(v)}`)

/** Over / short label for a variance: "Over ₱5.00", "Short ₱9.25", "Balanced". */
export function VarianceLabel({ variance, className }: { variance: string | null; className?: string }) {
  if (variance === null) return null
  if (isZero(variance)) return <span className={cn('font-medium text-[#56ca00]', className)}>Balanced</span>
  const short = variance.trim().startsWith('-')
  return (
    <span className={cn('font-medium', short ? 'text-[#ff3e1d]' : 'text-[#ffab00]', className)}>
      {short ? 'Short' : 'Over'} {formatMoney(short ? variance.trim().slice(1) : variance)}
    </span>
  )
}

/** Cash summary, payments by method, movements and (once closed) the count. */
export function SessionReport({ session }: { session: CashSession }) {
  const s = session.summary
  const movements = session.movements ?? []

  const columns: Column<Movement>[] = [
    { key: 'time', header: 'Time', render: (m) => <span className="whitespace-nowrap">{formatDateTime(m.occurred_at)}</span> },
    { key: 'type', header: 'Type', render: (m) => MOVEMENT_LABELS[m.type] },
    { key: 'reason', header: 'Reason', render: (m) => <span className="line-clamp-2">{m.reason}</span> },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (m) => <span className={cn('font-medium whitespace-nowrap', m.type === 'cash_in' ? 'text-[#56ca00]' : 'text-ink')}>{m.type === 'cash_in' ? '+' : '−'}{formatMoney(m.amount)}</span>,
    },
    { key: 'by', header: 'By', className: 'hidden md:table-cell', render: (m) => m.user?.name ?? '—' },
  ]

  return (
    <div className="grid gap-6 xl:grid-cols-3">
      <div className="space-y-6 xl:col-span-2">
        <Card>
          <CardHeader title="Cash drawer" description={session.status === 'open' && !s?.expected_cash ? 'The expected amount is shown after you close and count.' : undefined} />
          <CardBody>
            <DetailList
              items={[
                { label: 'Opening float', value: formatMoney(s?.opening_float ?? session.opening_float) },
                { label: 'Cash sales', value: formatMoney(s?.cash_sales) },
                ...(s && !isZero(s.cash_refunds) ? [{ label: 'Cash refunds', value: out(s.cash_refunds) }] : []),
                { label: 'Cash in', value: formatMoney(s?.cash_in) },
                { label: 'Cash out', value: out(s?.cash_out) },
                { label: 'Payouts', value: out(s?.payouts) },
                ...(s?.expected_cash ? [{ label: <span className="font-medium text-ink">Expected in drawer</span>, value: formatMoney(s.expected_cash) }] : []),
                ...(session.counted_cash !== null
                  ? [
                      { label: 'Counted', value: formatMoney(session.counted_cash) },
                      { label: 'Over / short', value: <VarianceLabel variance={session.variance} /> },
                    ]
                  : []),
              ]}
            />
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Cash in / out" />
          <DataTable columns={columns} rows={movements} rowKey={(m) => m.id} caption="Cash movements" empty={<p className="px-5 py-8 text-center text-sm text-ink-subtle">No cash added or taken out during this shift.</p>} />
        </Card>
      </div>
      <div className="space-y-6">
        <Card>
          <CardHeader title="Payments received" description={`${s?.sales_count ?? 0} sale${s?.sales_count === 1 ? '' : 's'}`} />
          <CardBody>
            {s && s.payments_by_method.length > 0 ? (
              <DetailList items={s.payments_by_method.map((p) => ({ label: p.method, value: formatMoney(p.amount) }))} />
            ) : (
              <p className="text-sm text-ink-subtle">No payments yet.</p>
            )}
          </CardBody>
        </Card>
        {session.denominations && Object.keys(session.denominations).length > 0 && (
          <Card>
            <CardHeader title="Count at closing" />
            <CardBody>
              <DetailList
                items={DENOMINATIONS.filter((d) => session.denominations![d.key]).map((d) => ({
                  label: `${d.label} ${d.kind}`,
                  value: `× ${session.denominations![d.key]}`,
                }))}
              />
            </CardBody>
          </Card>
        )}
      </div>
    </div>
  )
}
