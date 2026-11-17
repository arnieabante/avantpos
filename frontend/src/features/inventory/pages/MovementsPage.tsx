import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Card, FilterBar, Input, PageHeader, Pagination, SearchInput, Select } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { inventoryApi, inventoryKeys, type MovementFilters } from '../api'
import { MovementsTable } from '../components/MovementsTable'
import { MOVEMENT_LABELS, MOVEMENT_TYPES, type MovementType } from '../types'

export default function MovementsPage() {
  const { can, activeBranch } = useAuth()
  const navigate = useNavigate()
  const [filters, setFilters] = useState<MovementFilters>({ page: 1 })
  const setFilter = (patch: Partial<MovementFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))

  const movements = useQuery({ queryKey: inventoryKeys.movements(filters), queryFn: () => inventoryApi.movements(filters), placeholderData: (prev) => prev })

  return (
    <>
      <PageHeader title="Stock movements" description={`Every change to stock at ${activeBranch?.name ?? 'this branch'}, newest first. Entries are permanent.`} />
      <Card>
        <FilterBar className="flex-wrap">
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Item name or SKU…" />
          <Select aria-label="Movement type" className="sm:w-48" value={filters.type ?? ''} onChange={(e) => setFilter({ type: (e.target.value || undefined) as MovementType | undefined })}>
            <option value="">All movements</option>
            {MOVEMENT_TYPES.map((t) => (
              <option key={t} value={t}>
                {MOVEMENT_LABELS[t]}
              </option>
            ))}
          </Select>
          <Input type="date" aria-label="From date" className="sm:w-40" value={filters.from ?? ''} max={filters.to} onChange={(e) => setFilter({ from: e.target.value || undefined })} />
          <Input type="date" aria-label="To date" className="sm:w-40" value={filters.to ?? ''} min={filters.from} onChange={(e) => setFilter({ to: e.target.value || undefined })} />
        </FilterBar>
        {movements.isError ? (
          <ApiErrorAlert error={ApiError.from(movements.error)} className="m-4" />
        ) : (
          <>
            <MovementsTable rows={movements.data?.items} loading={movements.isFetching} canSeeCost={can('inventory.valuation')} onItemClick={(id) => navigate(`/inventory/items/${id}`)} />
            <Pagination meta={movements.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={movements.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
