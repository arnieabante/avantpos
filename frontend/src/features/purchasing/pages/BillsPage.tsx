import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Banknote, FileStack, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney } from '@/lib/format'
import { purchasingApi, purchasingKeys } from '../api'
import { BillModal, NewBillModal, PaySupplierModal } from '../components/PayablesModals'
import { BILL_STATUS, payableKeys, payablesApi, type BillFilters, type SupplierBill } from '../payables'

/** /purchasing/bills — what is owed to suppliers. */
export default function BillsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const canManage = can('supplier_payments.manage')
  const [filters, setFilters] = useState<BillFilters>({ page: 1, status: 'unpaid' })
  const [dialog, setDialog] = useState<'new' | 'pay' | { bill: number } | null>(null)
  const setFilter = (p: Partial<BillFilters>) => setFilters((f) => ({ ...f, ...p, page: p.page ?? 1 }))
  const list = useQuery({ queryKey: payableKeys.bills(filters), queryFn: () => payablesApi.bills(filters), placeholderData: (p) => p })
  const suppliers = useQuery({ queryKey: purchasingKeys.supplierOptions, queryFn: purchasingApi.supplierOptions })
  const refresh = () => queryClient.invalidateQueries({ queryKey: payableKeys.all })

  const columns: Column<SupplierBill>[] = [
    { key: 'number', header: 'Bill', render: (b) => <span className="font-medium whitespace-nowrap text-ink">{b.number}</span> },
    {
      key: 'supplier',
      header: 'Supplier',
      render: (b) =>
        b.supplier ? (
          <Link to={`/purchasing/suppliers/${b.supplier.id}/statement`} className="text-brand-500 hover:underline" onClick={(e) => e.stopPropagation()}>
            {b.supplier.name}
          </Link>
        ) : (
          '—'
        ),
    },
    { key: 'invoice', header: 'Supplier invoice', className: 'hidden lg:table-cell', render: (b) => b.supplier_invoice_no ?? '—' },
    { key: 'due', header: 'Due', render: (b) => <span className="whitespace-nowrap">{b.due_date ? formatDate(`${b.due_date}T00:00:00+08:00`) : '—'}</span> },
    { key: 'total', header: 'Total', align: 'right', className: 'hidden md:table-cell', render: (b) => formatMoney(b.total) },
    { key: 'balance', header: 'Balance', align: 'right', render: (b) => <span className="tabular-nums">{formatMoney(b.balance)}</span> },
    {
      key: 'status',
      header: 'Status',
      render: (b) => (
        <span className="flex flex-wrap gap-1">
          <Badge tone={BILL_STATUS[b.status].tone}>{BILL_STATUS[b.status].label}</Badge>
          {b.overdue && <Badge tone="danger">Overdue</Badge>}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Supplier bills"
        description="What you owe suppliers for deliveries and services. Pay several bills at once; see each supplier's statement."
        actions={
          canManage && (
            <>
              <Button variant="outline" leftIcon={<Plus className="size-4" />} onClick={() => setDialog('new')}>
                New bill
              </Button>
              <Button leftIcon={<Banknote className="size-4" />} onClick={() => setDialog('pay')}>
                Pay supplier
              </Button>
            </>
          )
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search: search || undefined })} placeholder="Bill or invoice no." />
          <Select aria-label="Status" className="sm:w-44" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as BillFilters['status'] })}>
            <option value="unpaid">Unpaid</option>
            <option value="overdue">Overdue</option>
            <option value="paid">Paid</option>
            <option value="void">Void</option>
            <option value="">All bills</option>
          </Select>
          <Select aria-label="Supplier" className="sm:w-56" value={filters.supplier_id ?? ''} onChange={(e) => setFilter({ supplier_id: e.target.value ? Number(e.target.value) : undefined })}>
            <option value="">All suppliers</option>
            {(suppliers.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </Select>
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(b) => b.id}
              loading={list.isFetching}
              onRowClick={(b) => setDialog({ bill: b.id })}
              caption="Supplier bills"
              empty={<EmptyState icon={<FileStack className="size-5" />} title="No bills" description="Bill a delivery from Goods received, or add a bill for a service." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {dialog === 'new' && (
        <NewBillModal
          onClose={() => setDialog(null)}
          onSaved={(b) => {
            setDialog(null)
            void refresh()
            toast.success(`Bill ${b.number} saved`, `Due ${b.due_date ? formatDate(`${b.due_date}T00:00:00+08:00`) : '—'}`)
          }}
        />
      )}
      {dialog === 'pay' && (
        <PaySupplierModal
          supplierId={filters.supplier_id}
          onClose={() => setDialog(null)}
          onSaved={(p) => {
            setDialog(null)
            void refresh()
            toast.success(`Payment ${p.number} recorded`, formatMoney(p.amount))
          }}
        />
      )}
      {dialog && typeof dialog === 'object' && (
        <BillModal
          billId={dialog.bill}
          canManage={canManage}
          onClose={() => setDialog(null)}
          onChanged={() => {
            setDialog(null)
            void refresh()
            toast.success('Bill voided')
          }}
        />
      )}
    </>
  )
}
