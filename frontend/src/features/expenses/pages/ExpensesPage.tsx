import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Plus, Receipt, Tags } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, DataTable, EmptyState, FilterBar, Input, Modal, PageHeader, Pagination, SearchInput, Select, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney } from '@/lib/format'
import { EXPENSE_STATUS, expenseKeys, expensesApi, type Expense, type ExpenseFilters, type ExpenseStatus } from '../api'
import { ExpenseFormModal } from '../components/ExpenseFormModal'

/** /expenses — expenses of the user's branches; approvers see what waits for them. */
export default function ExpensesPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const approver = can('expenses.approve')
  const [filters, setFilters] = useState<ExpenseFilters>({ page: 1 })
  const [creating, setCreating] = useState(false)
  const [categories, setCategories] = useState(false)
  const setFilter = (patch: Partial<ExpenseFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const list = useQuery({ queryKey: expenseKeys.list(filters), queryFn: () => expensesApi.list(filters), placeholderData: (p) => p })

  const columns: Column<Expense>[] = [
    { key: 'date', header: 'Date', render: (e) => <span className="whitespace-nowrap">{formatDate(`${e.expense_date}T00:00:00+08:00`)}</span> },
    { key: 'number', header: 'No.', className: 'hidden md:table-cell', render: (e) => <span className="whitespace-nowrap">{e.number}</span> },
    { key: 'category', header: 'Category', render: (e) => e.category?.name ?? '—' },
    { key: 'vendor', header: 'Paid to', className: 'hidden lg:table-cell', render: (e) => e.vendor ?? '—' },
    { key: 'branch', header: 'Branch', className: 'hidden xl:table-cell', render: (e) => e.branch?.name ?? '—' },
    { key: 'by', header: 'Recorded by', className: 'hidden xl:table-cell', render: (e) => e.submitted_by?.name ?? '—' },
    { key: 'amount', header: 'Amount', align: 'right', render: (e) => <span className="tabular-nums">{formatMoney(e.amount)}</span> },
    { key: 'status', header: 'Status', render: (e) => <Badge tone={EXPENSE_STATUS[e.status].tone} className="whitespace-nowrap">{EXPENSE_STATUS[e.status].label}</Badge> },
  ]

  return (
    <>
      <PageHeader
        title="Expenses"
        description={approver ? 'Record expenses and approve the ones your staff submit. Only approved expenses count in reports.' : 'Record expenses for approval.'}
        actions={
          <>
            {approver && (
              <Button variant="outline" leftIcon={<Tags className="size-4" />} onClick={() => setCategories(true)}>
                Categories
              </Button>
            )}
            <Button leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
              Record expense
            </Button>
          </>
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search: search || undefined })} placeholder="No., paid to or notes" />
          <Select aria-label="Status" className="sm:w-44" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as ExpenseStatus | undefined })}>
            <option value="">All statuses</option>
            {(Object.keys(EXPENSE_STATUS) as ExpenseStatus[]).map((s) => (
              <option key={s} value={s}>
                {EXPENSE_STATUS[s].label}
              </option>
            ))}
          </Select>
          <Input type="date" aria-label="From date" className="sm:w-40" value={filters.from ?? ''} max={filters.to} onChange={(e) => setFilter({ from: e.target.value || undefined })} />
          <Input type="date" aria-label="To date" className="sm:w-40" value={filters.to ?? ''} min={filters.from} onChange={(e) => setFilter({ to: e.target.value || undefined })} />
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(e) => e.id}
              loading={list.isFetching}
              onRowClick={(e) => navigate(`/expenses/${e.id}`)}
              caption="Expenses"
              empty={<EmptyState icon={<Receipt className="size-5" />} title="No expenses yet" description="Record rent, utilities, supplies and other costs here." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {creating && (
        <ExpenseFormModal
          expense={null}
          onClose={() => setCreating(false)}
          onSaved={(e) => {
            setCreating(false)
            void queryClient.invalidateQueries({ queryKey: expenseKeys.all })
            toast.success(`${e.number} ${e.status === 'draft' ? 'saved as draft' : e.status === 'approved' ? 'recorded' : 'submitted for approval'}`)
          }}
        />
      )}
      {categories && <CategoriesModal onClose={() => setCategories(false)} />}
    </>
  )
}

function CategoriesModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const cats = useQuery({ queryKey: expenseKeys.categories, queryFn: expensesApi.categories })
  const [name, setName] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const run = async (action: () => Promise<unknown>) => {
    setBusy(true)
    setError(null)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['expenses'] })
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <Modal open onClose={onClose} size="md" title="Expense categories" description="Inactive categories stay on past expenses but cannot be chosen.">
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (name.trim()) void run(async () => {
              await expensesApi.saveCategory({ name: name.trim(), is_active: true })
              setName('')
            })
          }}
        >
          <Input aria-label="New category" placeholder="New category" value={name} maxLength={80} onChange={(e) => setName(e.target.value)} />
          <Button type="submit" loading={busy} disabled={!name.trim()}>
            Add
          </Button>
        </form>
        <ul className="max-h-80 divide-y divide-line-soft overflow-y-auto rounded-md border border-line">
          {(cats.data ?? []).map((c) => (
            <li key={c.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
              <span className={c.is_active ? 'text-ink' : 'text-ink-subtle line-through'}>{c.name}</span>
              <Button
                size="sm"
                variant="ghost"
                aria-label={c.is_active ? `Deactivate ${c.name}` : `Activate ${c.name}`}
                disabled={busy}
                onClick={() => run(() => expensesApi.saveCategory({ name: c.name, is_active: !c.is_active }, c.id))}
              >
                {c.is_active ? 'Deactivate' : 'Activate'}
              </Button>
            </li>
          ))}
        </ul>
      </div>
    </Modal>
  )
}
