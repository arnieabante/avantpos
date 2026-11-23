import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { Package, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, ButtonLink, Card, DataTable, EmptyState, FilterBar, PageHeader, Pagination, SearchInput, Select, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatMoney } from '@/lib/format'
import { catalogApi, catalogKeys, type ProductFilters } from '../api'
import type { ProductListItem } from '../types'

function priceRange(p: ProductListItem): string {
  if (!p.price_min) return '—'
  return p.price_min === p.price_max ? formatMoney(p.price_min) : `${formatMoney(p.price_min)} – ${formatMoney(p.price_max)}`
}

export default function ProductsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const canManage = can('products.manage')
  const [filters, setFilters] = useState<ProductFilters>({ page: 1 })
  const setFilter = (patch: Partial<ProductFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))

  const products = useQuery({ queryKey: catalogKeys.products(filters), queryFn: () => catalogApi.products(filters), placeholderData: (prev) => prev })
  const categories = useQuery({ queryKey: catalogKeys.categories, queryFn: catalogApi.categories, staleTime: 60_000 })
  const topLevel = (categories.data ?? []).filter((c) => c.parent_id === null)

  const statusBadges = (p: ProductListItem) => (
    <div className="flex flex-wrap gap-1">
      <Badge tone={p.is_active ? 'success' : 'neutral'}>{p.is_active ? 'Active' : 'Inactive'}</Badge>
      {p.type === 'service' && <Badge tone="info">Service</Badge>}
    </div>
  )

  const columns: Column<ProductListItem>[] = [
    {
      key: 'product',
      header: 'Product',
      render: (p) => (
        <div className="flex items-start gap-3 md:items-center">
          <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-canvas md:size-11">
            {p.image_url ? <img src={p.image_url} alt="" className="size-full object-cover" /> : <Package className="size-5 text-ink-subtle" aria-hidden="true" />}
          </div>
          <div className="min-w-0 flex-1">
            <p className="font-medium whitespace-normal break-words text-ink md:truncate">{p.name}</p>
            <p className="truncate text-xs text-ink-subtle">
              {p.variants_count > 1 ? `${p.variants_count} variants` : p.sku}
              {p.brand && ` · ${p.brand.name}`}
            </p>
            {/* Phones: the other columns are hidden, so their details stack here. */}
            <div className="mt-1 space-y-1 text-sm md:hidden">
              <p className="text-ink-subtle">{p.category?.name ?? 'No category'}</p>
              <p className="font-medium text-ink">{priceRange(p)}</p>
              {statusBadges(p)}
            </div>
          </div>
        </div>
      ),
    },
    { key: 'category', header: 'Category', className: 'hidden md:table-cell', render: (p) => p.category?.name ?? '—' },
    { key: 'price', header: 'Price', align: 'right', className: 'hidden md:table-cell', render: (p) => <span className="whitespace-nowrap text-ink">{priceRange(p)}</span> },
    { key: 'unit', header: 'Unit', className: 'hidden lg:table-cell', render: (p) => p.base_unit ?? '—' },
    { key: 'status', header: 'Status', className: 'hidden md:table-cell', render: statusBadges },
  ]

  return (
    <>
      <PageHeader
        title="Products"
        description="Everything you sell. Scan a barcode into the search box to find an item."
        actions={
          canManage && (
            <ButtonLink to="/products/new" leftIcon={<Plus className="size-4" />}>
              Add product
            </ButtonLink>
          )
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Name, SKU or barcode…" />
          <Select aria-label="Category" className="sm:w-52" value={filters.category_id ?? ''} onChange={(e) => setFilter({ category_id: e.target.value ? Number(e.target.value) : undefined })}>
            <option value="">All categories</option>
            {topLevel.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <Select
            aria-label="Status"
            className="sm:w-40"
            value={filters.status ?? ''}
            onChange={(e) => setFilter({ status: (e.target.value || undefined) as ProductFilters['status'] })}
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </FilterBar>
        {products.isError ? (
          <ApiErrorAlert error={ApiError.from(products.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={products.data?.items}
              rowKey={(p) => p.id}
              loading={products.isFetching}
              onRowClick={canManage ? (p) => navigate(`/products/${p.id}`) : undefined}
              caption="Products"
              empty={
                <EmptyState
                  icon={<Package className="size-5" />}
                  title={filters.search || filters.category_id || filters.status ? 'No products found' : 'No products yet'}
                  description={filters.search ? 'Try another name, SKU or barcode.' : 'Add the items you sell to start using the POS.'}
                  action={canManage && !filters.search ? <ButtonLink to="/products/new" leftIcon={<Plus className="size-4" />}>Add product</ButtonLink> : undefined}
                />
              }
            />
            <Pagination meta={products.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={products.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
    </>
  )
}
