import { api } from '@/lib/api/client'
import type {
  AdjustmentReason,
  ExpiringBatch,
  MovementType,
  PickerItem,
  Ref,
  StockAdjustment,
  StockAlerts,
  StockCount,
  StockCountItem,
  StockItemDetail,
  StockLevel,
  StockMovement,
  StockStatus,
  StockTransfer,
  Valuation,
} from './types'

const V1 = '/api/v1'

export interface StockFilters {
  page?: number
  search?: string
  category_id?: number
  status?: StockStatus | 'negative'
}

export interface MovementFilters {
  page?: number
  product_variant_id?: number
  type?: MovementType
  from?: string
  to?: string
  search?: string
  per_page?: number
}

export interface DocumentFilters {
  page?: number
  status?: string
  search?: string
  reason_code?: AdjustmentReason
  direction?: 'outgoing' | 'incoming'
}

export interface CountItemFilters {
  page?: number
  search?: string
  filter?: 'uncounted' | 'counted' | 'variance'
}

export interface AdjustmentPayload {
  reason_code: AdjustmentReason
  notes: string | null
  post: boolean
  items: Array<{ product_variant_id: number; qty: string; unit_cost?: string | null; batch_no?: string | null; expiry_date?: string | null }>
}

export interface TransferPayload {
  to_branch_id: number
  notes: string | null
  dispatch: boolean
  items: Array<{ product_variant_id: number; qty: string }>
}

function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null)) as Partial<T>
}

/** Every inventory query starts with 'inventory' so one invalidation refreshes them all after a stock change. */
export const inventoryKeys = {
  all: ['inventory'] as const,
  stock: (f: StockFilters) => ['inventory', 'stock', f] as const,
  item: (variantId: number) => ['inventory', 'item', variantId] as const,
  alerts: ['inventory', 'alerts'] as const,
  expiring: ['inventory', 'expiring'] as const,
  movements: (f: MovementFilters) => ['inventory', 'movements', f] as const,
  valuation: ['inventory', 'valuation'] as const,
  destinations: ['inventory', 'destinations'] as const,
  picker: (q: string) => ['inventory', 'picker', q] as const,
  adjustments: (f: DocumentFilters) => ['inventory', 'adjustments', f] as const,
  adjustment: (id: number) => ['inventory', 'adjustment', id] as const,
  transfers: (f: DocumentFilters) => ['inventory', 'transfers', f] as const,
  transfer: (id: number) => ['inventory', 'transfer', id] as const,
  counts: (f: DocumentFilters) => ['inventory', 'counts', f] as const,
  count: (id: number) => ['inventory', 'count', id] as const,
  countItems: (id: number, f: CountItemFilters) => ['inventory', 'count', id, 'items', f] as const,
}

export const inventoryApi = {
  stock: (f: StockFilters) => api.getPage<StockLevel>(`${V1}/inventory/stock`, clean({ per_page: 25, ...f })),
  item: (variantId: number) => api.get<StockItemDetail>(`${V1}/inventory/stock/${variantId}`),
  alerts: () => api.get<StockAlerts>(`${V1}/inventory/alerts`),
  expiring: () => api.get<ExpiringBatch[]>(`${V1}/inventory/expiring`),
  movements: (f: MovementFilters) => api.getPage<StockMovement>(`${V1}/inventory/movements`, clean({ per_page: 50, ...f })),
  valuation: () => api.get<Valuation>(`${V1}/inventory/valuation`),
  destinations: () => api.get<Ref[]>(`${V1}/inventory/destinations`),
  picker: (q: string) => api.get<PickerItem[]>(`${V1}/inventory/picker`, { params: { q } }),

  adjustments: (f: DocumentFilters) => api.getPage<StockAdjustment>(`${V1}/stock-adjustments`, clean({ per_page: 25, ...f })),
  adjustment: (id: number) => api.get<StockAdjustment>(`${V1}/stock-adjustments/${id}`),
  saveAdjustment: (payload: AdjustmentPayload, id?: number) =>
    id ? api.put<StockAdjustment>(`${V1}/stock-adjustments/${id}`, payload) : api.post<StockAdjustment>(`${V1}/stock-adjustments`, payload),
  adjustmentAction: (id: number, action: 'post' | 'cancel') => api.post<StockAdjustment>(`${V1}/stock-adjustments/${id}/${action}`),

  transfers: (f: DocumentFilters) => api.getPage<StockTransfer>(`${V1}/stock-transfers`, clean({ per_page: 25, ...f })),
  transfer: (id: number) => api.get<StockTransfer>(`${V1}/stock-transfers/${id}`),
  saveTransfer: (payload: TransferPayload, id?: number) =>
    id ? api.put<StockTransfer>(`${V1}/stock-transfers/${id}`, payload) : api.post<StockTransfer>(`${V1}/stock-transfers`, payload),
  transferAction: (id: number, action: 'dispatch' | 'cancel') => api.post<StockTransfer>(`${V1}/stock-transfers/${id}/${action}`),
  receiveTransfer: (id: number, items: Array<{ id: number; qty_received: string }>) =>
    api.post<StockTransfer>(`${V1}/stock-transfers/${id}/receive`, { items }),

  counts: (f: DocumentFilters) => api.getPage<StockCount>(`${V1}/stock-counts`, clean({ per_page: 25, ...f })),
  count: (id: number) => api.get<StockCount>(`${V1}/stock-counts/${id}`),
  startCount: (data: { category_id: number | null; notes: string | null }) => api.post<StockCount>(`${V1}/stock-counts`, data),
  countItems: (id: number, f: CountItemFilters) => api.getPage<StockCountItem>(`${V1}/stock-counts/${id}/items`, clean({ per_page: 50, ...f })),
  recordCounts: (id: number, entries: Record<number, string | null>) => api.put<StockCount>(`${V1}/stock-counts/${id}/entries`, { entries }),
  countAction: (id: number, action: 'finalize' | 'cancel') => api.post<StockCount>(`${V1}/stock-counts/${id}/${action}`),
}
