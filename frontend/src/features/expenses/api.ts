import { api, http } from '@/lib/api/client'
import type { Ref } from '@/features/inventory/types'

const V1 = '/api/v1'

export type ExpenseStatus = 'draft' | 'submitted' | 'approved' | 'rejected'

export interface Expense {
  id: number
  number: string
  status: ExpenseStatus
  expense_date: string
  amount: string
  vendor: string | null
  reference_no: string | null
  description: string | null
  category: Ref | null
  branch: Ref | null
  payment_method: Ref | null
  paid_from_drawer: boolean
  submitted_by: Ref | null
  approved_by: Ref | null
  approved_at: string | null
  rejection_reason: string | null
  attachments: Array<{ id: number; name: string; mime: string; size: number }>
  created_at: string | null
  can: { edit: boolean; submit: boolean; decide: boolean; delete: boolean; attach: boolean }
}

export interface ExpenseCategory {
  id: number
  name: string
  is_active: boolean
}

export interface ExpenseOptions {
  categories: Ref[]
  payment_methods: Ref[]
  branches: Ref[]
  can_approve: boolean
}

export interface ExpenseInput {
  branch_id: number
  expense_category_id: number
  payment_method_id: number | null
  expense_date: string
  amount: string
  vendor: string | null
  reference_no: string | null
  description: string | null
}

export interface ExpenseFilters {
  page?: number
  status?: ExpenseStatus
  search?: string
  from?: string
  to?: string
}

const clean = <T extends object>(p: T) => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

export const expenseKeys = {
  all: ['expenses'] as const,
  list: (f: ExpenseFilters) => ['expenses', 'list', f] as const,
  one: (id: number) => ['expenses', 'one', id] as const,
  categories: ['expenses', 'categories'] as const,
  options: ['expenses', 'options'] as const,
}

export const expensesApi = {
  list: (f: ExpenseFilters) => api.getPage<Expense>(`${V1}/expenses`, clean({ per_page: 25, ...f })),
  get: (id: number) => api.get<Expense>(`${V1}/expenses/${id}`),
  categories: () => api.get<ExpenseCategory[]>(`${V1}/expense-categories`),
  options: () => api.get<ExpenseOptions>(`${V1}/expenses/options`),
  saveCategory: (body: { name: string; is_active: boolean }, id?: number) =>
    id ? api.put<ExpenseCategory>(`${V1}/expense-categories/${id}`, body) : api.post<ExpenseCategory>(`${V1}/expense-categories`, body),
  create: (body: ExpenseInput & { submit: boolean; paid_from_drawer: boolean }) => api.post<Expense>(`${V1}/expenses`, body),
  update: (id: number, body: ExpenseInput) => api.put<Expense>(`${V1}/expenses/${id}`, body),
  submit: (id: number, paidFromDrawer = false) => api.post<Expense>(`${V1}/expenses/${id}/submit`, { paid_from_drawer: paidFromDrawer }),
  approve: (id: number) => api.post<Expense>(`${V1}/expenses/${id}/approve`),
  reject: (id: number, reason: string) => api.post<Expense>(`${V1}/expenses/${id}/reject`, { reason }),
  remove: (id: number) => api.send('delete', `${V1}/expenses/${id}`),
  attach: (id: number, file: File) => {
    const body = new FormData()
    body.append('file', file)
    return api.post<Expense>(`${V1}/expenses/${id}/attachments`, body)
  },
  detach: (id: number, attachmentId: number) => api.send('delete', `${V1}/expenses/${id}/attachments/${attachmentId}`).then(() => expensesApi.get(id)),
  /** Open a private attachment (fetched with the session, shown in a new tab). */
  async open(id: number, attachmentId: number): Promise<void> {
    const res = await http.get<Blob>(`${V1}/expenses/${id}/attachments/${attachmentId}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    window.open(url, '_blank', 'noopener')
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  },
}

export const EXPENSE_STATUS: Record<ExpenseStatus, { label: string; tone: 'neutral' | 'info' | 'success' | 'danger' }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  submitted: { label: 'For approval', tone: 'info' },
  approved: { label: 'Approved', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
}
