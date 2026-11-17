import { useQuery } from '@tanstack/react-query'
import { Wallet } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Card, CardHeader, DataTable, PageHeader, PageLoader, StatCard, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatMoney, formatNumber } from '@/lib/format'
import { inventoryApi, inventoryKeys } from '../api'
import type { Valuation } from '../types'

export default function ValuationPage() {
  const { activeBranch } = useAuth()
  const valuation = useQuery({ queryKey: inventoryKeys.valuation, queryFn: inventoryApi.valuation })

  if (valuation.isPending) return <PageLoader />
  if (valuation.isError) return <ApiErrorAlert error={ApiError.from(valuation.error)} />

  const data = valuation.data
  const branchColumns: Column<Valuation['branches'][number]>[] = [
    { key: 'branch', header: 'Branch', render: (r) => <span className="font-medium text-ink">{r.branch.name}</span> },
    { key: 'items', header: 'Items in stock', align: 'right', render: (r) => formatNumber(r.items_in_stock) },
    { key: 'value', header: 'Value', align: 'right', render: (r) => <span className="font-medium text-ink">{formatMoney(r.value)}</span> },
  ]
  const categoryColumns: Column<Valuation['categories'][number]>[] = [
    { key: 'category', header: 'Category', render: (r) => r.category?.name ?? <span className="text-ink-subtle">No category</span> },
    { key: 'value', header: 'Value', align: 'right', render: (r) => <span className="font-medium text-ink">{formatMoney(r.value)}</span> },
  ]

  return (
    <>
      <PageHeader title="Inventory valuation" description="Stock on hand at cost (first-in, first-out). Updated with every stock movement." />
      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatCard label="Total inventory value" value={formatMoney(data.total)} icon={<Wallet className="size-5" />} footnote={`${data.branches.length} branch${data.branches.length === 1 ? '' : 'es'} you can access`} />
      </div>
      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <CardHeader title="By branch" />
          <DataTable columns={branchColumns} rows={data.branches} rowKey={(r) => r.branch.id} caption="Value by branch" />
        </Card>
        <Card>
          <CardHeader title="By category" description={activeBranch?.name} />
          <DataTable
            columns={categoryColumns}
            rows={data.categories}
            rowKey={(r) => r.category?.id ?? 0}
            caption="Value by category"
            empty={<p className="px-5 py-8 text-center text-sm text-ink-subtle">No stock value in this branch yet.</p>}
          />
        </Card>
      </div>
    </>
  )
}
