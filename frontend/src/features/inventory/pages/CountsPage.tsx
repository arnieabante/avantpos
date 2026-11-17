import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { ClipboardList, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Card, DataTable, EmptyState, FilterBar, FormField, Modal, PageHeader, Pagination, Select, Textarea, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { catalogApi, catalogKeys } from '@/features/catalog/api'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { inventoryApi, inventoryKeys, type DocumentFilters } from '../api'
import { StatusBadge } from '../components/StatusBadge'
import type { StockCount } from '../types'

export default function CountsPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<DocumentFilters>({ page: 1 })
  const [starting, setStarting] = useState(false)
  const setFilter = (patch: Partial<DocumentFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const canCount = can('inventory.count')

  const list = useQuery({ queryKey: inventoryKeys.counts(filters), queryFn: () => inventoryApi.counts(filters), placeholderData: (prev) => prev })

  const columns: Column<StockCount>[] = [
    { key: 'number', header: 'Number', render: (c) => <span className="font-medium text-ink">{c.number}</span> },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
    { key: 'progress', header: 'Counted', align: 'right', render: (c) => `${c.summary.counted} of ${c.summary.items}` },
    { key: 'variances', header: 'Variances', align: 'right', className: 'hidden sm:table-cell', render: (c) => c.summary.variances },
    { key: 'notes', header: 'Notes', className: 'hidden lg:table-cell', render: (c) => <span className="line-clamp-1 max-w-64">{c.notes ?? '—'}</span> },
    { key: 'date', header: 'Started', className: 'hidden md:table-cell', render: (c) => <span className="whitespace-nowrap">{formatDateTime(c.created_at)}</span> },
  ]

  return (
    <>
      <PageHeader
        title="Stock counts"
        description={`Physical counts of ${activeBranch?.name ?? 'this branch'}. Differences are posted when a count is finalized.`}
        actions={
          canCount && (
            <Button leftIcon={<Plus className="size-4" />} onClick={() => setStarting(true)}>
              Start count
            </Button>
          )
        }
      />
      <Card>
        <FilterBar>
          <Select aria-label="Status" className="sm:w-44" value={filters.status ?? ''} onChange={(e) => setFilter({ status: e.target.value || undefined })}>
            <option value="">All statuses</option>
            <option value="counting">Counting</option>
            <option value="finalized">Finalized</option>
            <option value="cancelled">Cancelled</option>
          </Select>
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
              onRowClick={(c) => navigate(`/inventory/counts/${c.id}`)}
              caption="Stock counts"
              empty={<EmptyState icon={<ClipboardList className="size-5" />} title="No stock counts yet" description="Count your shelves regularly to catch losses early." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {starting && <StartCountModal onClose={() => setStarting(false)} />}
    </>
  )
}

function StartCountModal({ onClose }: { onClose: () => void }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const categories = useQuery({ queryKey: catalogKeys.categories, queryFn: catalogApi.categories, staleTime: 60_000, enabled: can('products.view') || can('products.manage') })
  const topLevel = (categories.data ?? []).filter((c) => c.parent_id === null)
  const [categoryId, setCategoryId] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [fieldError, setFieldError] = useState<string | undefined>()
  const [busy, setBusy] = useState(false)

  const start = async () => {
    setBusy(true)
    setError(null)
    setFieldError(undefined)
    try {
      const count = await inventoryApi.startCount({ category_id: categoryId ? Number(categoryId) : null, notes: notes.trim() || null })
      await queryClient.invalidateQueries({ queryKey: inventoryKeys.all })
      navigate(`/inventory/counts/${count.id}`)
    } catch (e) {
      const apiError = ApiError.from(e)
      if (apiError.errors.category_id?.[0]) setFieldError(apiError.errors.category_id[0])
      else setError(apiError)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Start a stock count"
      description="Every stock-tracked item in scope is listed. Uncounted items are left unchanged when you finalize."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Close
          </Button>
          <Button onClick={start} loading={busy}>
            Start count
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <FormField label="What to count" error={fieldError}>
          <Select value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
            <option value="">Everything (full count)</option>
            {topLevel.map((c) => (
              <option key={c.id} value={c.id}>
                Category: {c.name}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Notes">
          <Textarea rows={2} value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Month-end count" />
        </FormField>
      </div>
    </Modal>
  )
}
