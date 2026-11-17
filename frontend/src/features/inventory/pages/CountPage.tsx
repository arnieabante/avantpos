import { useState, type KeyboardEvent } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft, Check } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, ButtonLink, Card, DataTable, FilterBar, Input, PageHeader, PageLoader, Pagination, SearchInput, Select, Spinner, useConfirm, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { trimDecimal } from '@/features/catalog/productForm'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { inventoryApi, inventoryKeys, type CountItemFilters } from '../api'
import { StatusBadge } from '../components/StatusBadge'
import { formatQty, formatSignedQty, isNegative, isZeroQty } from '../format'
import type { StockCount, StockCountItem } from '../types'

/** /inventory/counts/:countId — the count sheet. */
export default function CountPage() {
  const id = Number(useParams().countId)
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [filters, setFilters] = useState<CountItemFilters>({ page: 1 })
  const setFilter = (patch: Partial<CountItemFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))
  const [busy, setBusy] = useState<'finalize' | 'cancel' | null>(null)
  const [actionError, setActionError] = useState<ApiError | null>(null)

  const count = useQuery({ queryKey: inventoryKeys.count(id), queryFn: () => inventoryApi.count(id), enabled: Number.isFinite(id) })
  const items = useQuery({ queryKey: inventoryKeys.countItems(id, filters), queryFn: () => inventoryApi.countItems(id, filters), placeholderData: (prev) => prev, enabled: Number.isFinite(id) })

  if (count.isPending) return <PageLoader />
  if (count.isError) return <ApiErrorAlert error={ApiError.from(count.error)} />

  const doc = count.data
  const editable = doc.status === 'counting' && can('inventory.count')

  const refresh = async (saved?: StockCount) => {
    if (saved) queryClient.setQueryData(inventoryKeys.count(id), saved)
    await queryClient.invalidateQueries({ queryKey: ['inventory', 'count', id, 'items'] })
  }

  const saveEntry = async (itemId: number, value: string | null): Promise<string | undefined> => {
    try {
      await refresh(await inventoryApi.recordCounts(id, { [itemId]: value }))
      return undefined
    } catch (e) {
      const apiError = ApiError.from(e)
      return apiError.errors[`entries.${itemId}`]?.[0] ?? apiError.message
    }
  }

  const act = async (action: 'finalize' | 'cancel') => {
    const ok = await confirm(
      action === 'finalize'
        ? {
            title: `Finalize ${doc.number}?`,
            description: `${doc.summary.counted} of ${doc.summary.items} items were counted; ${doc.summary.variances} differ from the system. The differences are posted to stock now. Uncounted items stay as they are.`,
            confirmLabel: 'Finalize and post',
          }
        : { title: `Cancel ${doc.number}?`, description: 'Nothing is posted. The count is kept for the record.', confirmLabel: 'Cancel count', danger: true },
    )
    if (!ok) return
    setBusy(action)
    setActionError(null)
    try {
      const saved = await inventoryApi.countAction(id, action)
      await refresh(saved)
      await queryClient.invalidateQueries({ queryKey: inventoryKeys.all, predicate: (q) => q.queryKey[1] !== 'count' })
      toast.success(action === 'finalize' ? `${doc.number} finalized. Stock is updated.` : `${doc.number} cancelled.`)
    } catch (e) {
      setActionError(ApiError.from(e))
    } finally {
      setBusy(null)
    }
  }

  const columns: Column<StockCountItem>[] = [
    {
      key: 'item',
      header: 'Item',
      render: (i) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink">{i.variant.name}</p>
          <p className="truncate text-xs text-ink-subtle">{i.variant.sku}</p>
        </div>
      ),
    },
    { key: 'expected', header: 'Expected', align: 'right', render: (i) => <span className="whitespace-nowrap text-ink-muted">{formatQty(i.system_qty, i.variant.unit)}</span> },
    {
      key: 'counted',
      header: 'Counted',
      className: 'w-44',
      render: (i) =>
        editable ? (
          <CountInput key={i.id} item={i} onSave={(value) => saveEntry(i.id, value)} />
        ) : (
          <span>{i.counted_qty === null ? <span className="text-ink-subtle">Not counted</span> : formatQty(i.counted_qty, i.variant.unit)}</span>
        ),
    },
    {
      key: 'variance',
      header: 'Difference',
      align: 'right',
      render: (i) =>
        i.variance_qty === null ? (
          <span className="text-ink-subtle">—</span>
        ) : isZeroQty(i.variance_qty) ? (
          <span className="inline-flex items-center gap-1 text-[#56ca00]">
            <Check className="size-4" aria-hidden="true" /> Match
          </span>
        ) : (
          <span className={cn('font-medium whitespace-nowrap', isNegative(i.variance_qty) ? 'text-[#ff3e1d]' : 'text-[#03c3ec]')}>{formatSignedQty(i.variance_qty, i.variant.unit)}</span>
        ),
    },
  ]

  return (
    <div className="space-y-6">
      <ButtonLink to="/inventory/counts" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Stock counts
      </ButtonLink>
      <PageHeader
        title={`Stock count ${doc.number}`}
        description={`${doc.branch?.name ?? ''} · started ${formatDateTime(doc.created_at)} by ${doc.created_by?.name ?? '—'}${doc.notes ? ` · ${doc.notes}` : ''}`}
        actions={<StatusBadge status={doc.status} />}
      />
      <ApiErrorAlert error={actionError} />
      {editable && (
        <Alert tone="info">
          Scan or search an item, type what is on the shelf and press Enter. Each figure is saved right away, and the difference is measured at that moment, so you can keep selling while you count.
        </Alert>
      )}
      {doc.status === 'finalized' && doc.finalized_at && (
        <Alert tone="success">
          Finalized {formatDateTime(doc.finalized_at)} by {doc.finalized_by?.name ?? '—'}. {doc.summary.variances} difference{doc.summary.variances === 1 ? ' was' : 's were'} posted to stock.
        </Alert>
      )}

      <div className="grid gap-4 sm:grid-cols-3">
        <Summary label="Items in this count" value={doc.summary.items} />
        <Summary label="Counted" value={`${doc.summary.counted} of ${doc.summary.items}`} />
        <Summary label="With a difference" value={doc.summary.variances} />
      </div>

      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Scan a barcode, or name / SKU…" debounceMs={200} />
          <Select aria-label="Show" className="sm:w-48" value={filters.filter ?? ''} onChange={(e) => setFilter({ filter: (e.target.value || undefined) as CountItemFilters['filter'] })}>
            <option value="">All items</option>
            <option value="uncounted">Not counted yet</option>
            <option value="counted">Counted</option>
            <option value="variance">With a difference</option>
          </Select>
        </FilterBar>
        <DataTable columns={columns} rows={items.data?.items} rowKey={(i) => i.id} loading={items.isFetching && !items.data} caption="Count sheet" empty={<p className="px-5 py-8 text-center text-sm text-ink-subtle">No items match.</p>} />
        <Pagination meta={items.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={items.isFetching} className="border-t border-line-soft" />
      </Card>

      {editable && (
        <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap justify-end gap-2 border-t border-line-soft bg-canvas/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
          <Button variant="ghost" className="mr-auto text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={() => act('cancel')} loading={busy === 'cancel'} disabled={busy !== null}>
            Cancel count
          </Button>
          <Button onClick={() => act('finalize')} loading={busy === 'finalize'} disabled={busy !== null || doc.summary.counted === 0}>
            Finalize count
          </Button>
        </div>
      )}
    </div>
  )
}

function Summary({ label, value }: { label: string; value: number | string }) {
  return (
    <Card className="p-5">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="mt-1 text-[1.375rem] leading-tight font-medium text-ink">{value}</p>
    </Card>
  )
}

/** One counted quantity: saved on Enter or when leaving the field; Enter jumps to the next row. */
function CountInput({ item, onSave }: { item: StockCountItem; onSave: (value: string | null) => Promise<string | undefined> }) {
  const initial = trimDecimal(item.counted_qty)
  const [draft, setDraft] = useState(initial)
  const [state, setState] = useState<'idle' | 'saving' | 'saved'>('idle')
  const [error, setError] = useState<string | undefined>()

  // Follow the saved value when the sheet is refetched.
  const [server, setServer] = useState(item.counted_qty)
  if (item.counted_qty !== server) {
    setServer(item.counted_qty)
    setDraft(initial)
  }

  const commit = async () => {
    const value = draft.trim()
    if (value === initial) return
    if (value !== '' && !/^\d+(\.\d{1,3})?$/.test(value)) {
      setError('Enter a number, e.g. 12')
      return
    }
    setState('saving')
    setError(undefined)
    const message = await onSave(value === '' ? null : value)
    setError(message)
    setState(message ? 'idle' : 'saved')
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key !== 'Enter') return
    event.preventDefault()
    void commit()
    const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-count-input]'))
    inputs[inputs.indexOf(event.currentTarget) + 1]?.focus()
  }

  return (
    <div>
      <Input
        data-count-input
        aria-label={`Counted quantity of ${item.variant.name}`}
        inputMode="decimal"
        value={draft}
        placeholder="—"
        aria-invalid={!!error}
        onChange={(e) => {
          setDraft(e.target.value)
          setError(undefined)
          setState('idle')
        }}
        onBlur={() => void commit()}
        onKeyDown={onKeyDown}
        rightAddon={
          state === 'saving' ? <Spinner size="sm" className="mr-3" /> : state === 'saved' ? <Check className="mr-3 size-4 text-[#56ca00]" aria-label="Saved" /> : <span className="pr-2 text-xs text-ink-subtle">{item.variant.unit}</span>
        }
      />
      {error && <p className="mt-1 text-xs text-[#ff3e1d]">{error}</p>}
    </div>
  )
}
