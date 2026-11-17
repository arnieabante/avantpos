import { api } from '@/lib/api/client'
import type { Customer, CustomerGroup, LedgerEntry, LoyaltyEntry, LoyaltySettings, PriceTier, ScPwdType } from './types'

const V1 = '/api/v1'

function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null && v !== false)) as Partial<T>
}

export interface CustomerFilters {
  page?: number
  search?: string
  customer_group_id?: number
  status?: 'active' | 'inactive'
  with_balance?: boolean
}

export interface CustomerInput {
  name: string
  code: string
  phone: string
  email: string
  tin: string
  address: string
  birthdate: string
  customer_group_id: number | ''
  sc_pwd_id_type: ScPwdType | ''
  sc_pwd_id_no: string
  allow_credit: boolean
  credit_limit: string
  notes: string
}

const blank = (v: string) => (v.trim() === '' ? null : v.trim())

/** Form → API body. Credit settings are only sent by users allowed to change them. */
export function customerPayload(v: CustomerInput, includeCredit: boolean) {
  return {
    name: v.name.trim(),
    code: blank(v.code),
    phone: blank(v.phone),
    email: blank(v.email),
    tin: blank(v.tin),
    address: blank(v.address),
    birthdate: v.birthdate || null,
    customer_group_id: v.customer_group_id === '' ? null : v.customer_group_id,
    sc_pwd_id_type: v.sc_pwd_id_type || null,
    sc_pwd_id_no: v.sc_pwd_id_type ? blank(v.sc_pwd_id_no) : null,
    notes: blank(v.notes),
    ...(includeCredit ? { allow_credit: v.allow_credit, credit_limit: v.credit_limit.trim() || '0' } : {}),
  }
}

export const customerKeys = {
  all: ['customers'] as const,
  list: (f: CustomerFilters) => ['customers', 'list', f] as const,
  detail: (id: number) => ['customers', 'detail', id] as const,
  ledger: (id: number, page: number) => ['customers', 'ledger', id, page] as const,
  loyalty: (id: number, page: number) => ['customers', 'loyalty', id, page] as const,
  groups: ['customers', 'groups'] as const,
  loyaltySettings: ['customers', 'loyalty-settings'] as const,
}

export const customerApi = {
  list: (f: CustomerFilters) => api.getPage<Customer>(`${V1}/customers`, clean({ per_page: 25, ...f, with_balance: f.with_balance ? 1 : undefined })),
  get: (id: number) => api.get<Customer>(`${V1}/customers/${id}`),
  save: (input: CustomerInput, includeCredit: boolean, id?: number) =>
    id ? api.put<Customer>(`${V1}/customers/${id}`, customerPayload(input, includeCredit)) : api.post<Customer>(`${V1}/customers`, customerPayload(input, includeCredit)),
  action: (id: number, action: 'activate' | 'deactivate') => api.post<Customer>(`${V1}/customers/${id}/${action}`),
  remove: (id: number) => api.delete(`${V1}/customers/${id}`),

  ledger: (id: number, page: number) => api.getPage<LedgerEntry>(`${V1}/customers/${id}/ledger`, { page, per_page: 20 }),
  openingBalance: (id: number, amount: string, notes: string | null) => api.post<Customer>(`${V1}/customers/${id}/opening-balance`, { amount, notes }),
  pay: (id: number, body: { amount: string; payment_method_id: number; reference_no: string | null; notes: string | null }) =>
    api.post<{ number: string; amount: string; balance_after: string; customer: Customer }>(`${V1}/customers/${id}/payments`, body),
  adjustBalance: (id: number, amount: string, notes: string) => api.post<Customer>(`${V1}/customers/${id}/balance-adjustments`, { amount, notes }),

  loyalty: (id: number, page: number) => api.getPage<LoyaltyEntry>(`${V1}/customers/${id}/loyalty`, { page, per_page: 20 }),
  adjustPoints: (id: number, points: string, notes: string) => api.post<Customer>(`${V1}/customers/${id}/loyalty-adjustments`, { points, notes }),

  groups: () => api.get<CustomerGroup[]>(`${V1}/customer-groups`),
  saveGroup: (data: { name: string; price_tier: PriceTier; discount_percent: string }, id?: number) =>
    id ? api.put<CustomerGroup>(`${V1}/customer-groups/${id}`, data) : api.post<CustomerGroup>(`${V1}/customer-groups`, data),
  deleteGroup: (id: number) => api.delete(`${V1}/customer-groups/${id}`),

  loyaltySettings: () => api.get<LoyaltySettings>(`${V1}/loyalty-settings`),
  saveLoyaltySettings: (s: LoyaltySettings) => api.put<LoyaltySettings>(`${V1}/loyalty-settings`, s),
}
