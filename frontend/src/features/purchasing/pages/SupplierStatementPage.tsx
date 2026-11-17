import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft, Printer } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, ButtonLink, Card, PageHeader, PageLoader } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney } from '@/lib/format'
import { payableKeys, payablesApi } from '../payables'

/** /purchasing/suppliers/:supplierId/statement — bills and payments with the running balance (printable). */
export default function SupplierStatementPage() {
  const id = Number(useParams().supplierId)
  const { account } = useAuth()
  const q = useQuery({ queryKey: payableKeys.statement(id), queryFn: () => payablesApi.statement(id), enabled: Number.isFinite(id) })

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const s = q.data

  return (
    <div className="space-y-6">
      <ButtonLink to="/purchasing/bills" variant="link" leftIcon={<ArrowLeft className="size-4" />} className="print:hidden">
        Supplier bills
      </ButtonLink>
      <PageHeader
        title={`Statement · ${s.supplier.name}`}
        description={`${account?.tenant.name ?? ''} · payment terms ${s.supplier.payment_terms_days} days`}
        actions={
          <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={() => window.print()} className="print:hidden">
            Print
          </Button>
        }
      />
      <Card>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-[#f8f9fa] text-left text-xs text-ink-subtle uppercase">
              <tr>
                <th className="px-5 py-2.5 font-medium">Date</th>
                <th className="px-3 py-2.5 font-medium">Document</th>
                <th className="px-3 py-2.5 font-medium">Reference</th>
                <th className="px-3 py-2.5 text-right font-medium">Bill</th>
                <th className="px-3 py-2.5 text-right font-medium">Payment</th>
                <th className="px-5 py-2.5 text-right font-medium">Balance</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line-soft">
              {s.entries.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-5 py-8 text-center text-ink-subtle">
                    No bills or payments yet.
                  </td>
                </tr>
              )}
              {s.entries.map((e) => (
                <tr key={`${e.type}-${e.id}`}>
                  <td className="px-5 py-2 whitespace-nowrap">{formatDate(`${e.date}T00:00:00+08:00`)}</td>
                  <td className="px-3 py-2 whitespace-nowrap text-ink">{e.number}</td>
                  <td className="px-3 py-2">{e.reference || '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{e.type === 'bill' ? formatMoney(e.amount) : ''}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{e.type === 'payment' ? formatMoney(e.amount.replace('-', '')) : ''}</td>
                  <td className="px-5 py-2 text-right font-medium tabular-nums text-ink">{formatMoney(e.balance)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="border-t-2 border-line">
              <tr>
                <td colSpan={5} className="px-5 py-2.5 text-right font-semibold text-ink">
                  Balance owed
                </td>
                <td className="px-5 py-2.5 text-right font-semibold tabular-nums text-ink">{formatMoney(s.balance)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </Card>
    </div>
  )
}
