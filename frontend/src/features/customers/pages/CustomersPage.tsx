import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Plus, UserRound } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, Checkbox, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatMoney, formatNumber, formatPhone } from '@/lib/format'
import { customerApi, customerKeys, type CustomerFilters } from '../api'
import { CustomerFormModal } from '../components/CustomerFormModal'
import { SC_PWD_LABELS, type Customer } from '../types'

export default function CustomersPage() {
  const { can } = useAuth()
  const account = useAccount()
  const navigate = useNavigate()
  const canManage = can('customers.manage')
  const features = account.subscription?.plan.features ?? {}
  const [filters, setFilters] = useState<CustomerFilters>({ page: 1 })
  const [adding, setAdding] = useState(false)
  const setFilter = (patch: Partial<CustomerFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))

  const list = useQuery({ queryKey: customerKeys.list(filters), queryFn: () => customerApi.list(filters), placeholderData: (prev) => prev })
  const groups = useQuery({ queryKey: customerKeys.groups, queryFn: customerApi.groups, staleTime: 60_000 })

  const columns: Column<Customer>[] = [
    {
      key: 'name',
      header: 'Customer',
      render: (c) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink">{c.name}</p>
          <p className="truncate text-xs text-ink-subtle">{[c.phone ? formatPhone(c.phone) : null, c.code].filter(Boolean).join(' · ') || '—'}</p>
        </div>
      ),
    },
    { key: 'group', header: 'Group', className: 'hidden md:table-cell', render: (c) => c.group?.name ?? '—' },
    ...(features.credit_sales
      ? [{ key: 'balance', header: 'Owes', align: 'right' as const, render: (c: Customer) => (c.balance !== '0.00' ? <span className="font-medium text-[#ff3e1d]">{formatMoney(c.balance)}</span> : '—') }]
      : []),
    ...(features.loyalty ? [{ key: 'points', header: 'Points', align: 'right' as const, className: 'hidden lg:table-cell', render: (c: Customer) => formatNumber(c.loyalty_points) }] : []),
    {
      key: 'tags',
      header: 'Status',
      render: (c) => (
        <div className="flex flex-wrap gap-1">
          {!c.is_active && <Badge>Inactive</Badge>}
          {c.sc_pwd && <Badge tone="info">{SC_PWD_LABELS[c.sc_pwd.type]}</Badge>}
          {c.allow_credit && <Badge tone="warning">Credit</Badge>}
          {c.is_active && !c.sc_pwd && !c.allow_credit && <Badge tone="success">Active</Badge>}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Customers"
        description="Regulars, Senior Citizen / PWD customers and accounts that buy on credit."
        actions={
          canManage && (
            <Button leftIcon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
              Add customer
            </Button>
          )
        }
      />
      <Card>
        <FilterBar className="flex-wrap">
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Name, mobile number or code…" />
          {(groups.data?.length ?? 0) > 0 && (
            <Select aria-label="Group" className="sm:w-44" value={filters.customer_group_id ?? ''} onChange={(e) => setFilter({ customer_group_id: e.target.value ? Number(e.target.value) : undefined })}>
              <option value="">All groups</option>
              {groups.data!.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </Select>
          )}
          <Select aria-label="Status" className="sm:w-36" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as CustomerFilters['status'] })}>
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
          {features.credit_sales && <Checkbox label="Owes money" checked={!!filters.with_balance} onChange={(e) => setFilter({ with_balance: e.target.checked })} />}
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(c) => c.id}
              loading={list.isFetching}
              onRowClick={(c) => navigate(`/customers/${c.id}`)}
              caption="Customers"
              empty={
                <EmptyState
                  icon={<UserRound className="size-5" />}
                  title={filters.search || filters.with_balance ? 'No customers found' : 'No customers yet'}
                  description="Add regular customers to give group prices, track utang and record Senior / PWD IDs."
                  action={canManage && !filters.search ? <Button onClick={() => setAdding(true)}>Add customer</Button> : undefined}
                />
              }
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {adding && <CustomerFormModal customer={null} onClose={() => setAdding(false)} onSaved={(c) => navigate(`/customers/${c.id}`)} />}
    </>
  )
}
