import { api } from '@/lib/api/client'
import type { RegisterReading, Sale, SaleRefund, SaleStatus } from '@/features/pos/types'

const V1 = '/api/v1'

export interface SaleFilters {
  page?: number
  search?: string
  status?: SaleStatus
  sync_status?: 'flagged' | 'reviewed'
  from?: string
  to?: string
}

export interface RefundInput {
  client_uuid: string
  reason: string
  items: Array<{ sale_item_id: number; qty: string; restock: 'sellable' | 'damaged' | 'none' }>
  payment_method_id: number | null
  override_token?: string | null
}

function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>
}

export const salesKeys = {
  all: ['sales'] as const,
  list: (f: SaleFilters) => ['sales', 'list', f] as const,
  sale: (id: number) => ['sales', 'sale', id] as const,
  readings: (f: { page?: number; type?: 'X' | 'Z' }) => ['sales', 'readings', f] as const,
}

export const salesApi = {
  list: (f: SaleFilters) => api.getPage<Sale>(`${V1}/sales`, clean({ per_page: 25, ...f })),
  sale: (id: number) => api.get<Sale>(`${V1}/sales/${id}`),
  lookup: (invoiceNo: string) => api.get<Sale[]>(`${V1}/sales/lookup`, { params: { invoice_no: invoiceNo } }),
  reviewSync: (id: number, notes: string) => api.post<Sale>(`${V1}/sales/${id}/review-sync`, { notes }),
  reprint: (id: number) => api.post<Sale>(`${V1}/sales/${id}/reprint`),
  void: (id: number, body: { reason: string; override_token?: string | null }) => api.post<Sale>(`${V1}/sales/${id}/void`, body),
  refund: (id: number, body: RefundInput) => api.post<{ refund: SaleRefund; sale: Sale }>(`${V1}/sales/${id}/refunds`, body),
  readings: (f: { page?: number; type?: 'X' | 'Z' }) => api.getPage<RegisterReading>(`${V1}/pos/readings`, clean({ per_page: 25, ...f })),
  xReading: (sessionId: number) => api.post<RegisterReading>(`${V1}/cash-sessions/${sessionId}/x-reading`),
  zReading: (terminalId: number) => api.post<RegisterReading>(`${V1}/pos/z-readings`, { terminal_id: terminalId }),
}
