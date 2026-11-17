import { api } from '@/lib/api/client'
import type { Customer } from '@/features/customers/types'
import type { CartRequest, HeldCartSummary, PosItem, PosSetup, Quote, Sale } from './types'

const V1 = '/api/v1'

export const posKeys = {
  all: ['pos'] as const,
  setup: ['pos', 'setup'] as const,
  items: (q: string, categoryId: number | null) => ['pos', 'items', q, categoryId] as const,
  quote: (body: CartRequest) => ['pos', 'quote', body] as const,
  held: ['pos', 'held'] as const,
  sale: (id: number) => ['pos', 'sale', id] as const,
}

export const posApi = {
  setup: () => api.get<PosSetup>(`${V1}/pos/setup`),
  items: (q: string, categoryId: number | null) =>
    api.get<PosItem[]>(`${V1}/pos/items`, { params: { ...(q ? { q } : {}), ...(categoryId ? { category_id: categoryId } : {}) } }),
  scan: (code: string) => api.get<PosItem>(`${V1}/pos/items/scan`, { params: { code } }),
  quote: (body: CartRequest, signal?: AbortSignal) => api.post<Quote>(`${V1}/pos/quote`, body, { signal }),
  checkout: (body: CartRequest & { client_uuid: string; payments: Array<{ payment_method_id: number; amount: string; reference_no?: string | null }>; overrides?: Record<string, string> }) =>
    api.post<Sale>(`${V1}/pos/sales`, body),
  sale: (id: number) => api.get<Sale>(`${V1}/sales/${id}`),
  customers: (q: string) => api.get<Customer[]>(`${V1}/customers/lookup`, { params: { q } }),
  override: (body: { supervisor_id: number; pin: string; permission: string }) =>
    api.post<{ override_token: string; expires_at: string; supervisor: { id: number; name: string } }>(`${V1}/auth/override`, body),
  held: () => api.get<HeldCartSummary[]>(`${V1}/pos/held-carts`),
  hold: (body: { client_uuid: string; label: string | null; customer_id: number | null; cart: unknown }) =>
    api.post<HeldCartSummary>(`${V1}/pos/held-carts`, body),
  resume: (id: number) => api.post<HeldCartSummary & { cart: unknown }>(`${V1}/pos/held-carts/${id}/resume`),
  discard: (id: number) => api.send('delete', `${V1}/pos/held-carts/${id}`),
}
