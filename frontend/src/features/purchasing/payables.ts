import { api } from '@/lib/api/client'
import type { Ref } from '@/features/inventory/types'

const V1 = '/api/v1'

export type BillStatus = 'open' | 'partially_paid' | 'paid' | 'void'

export interface SupplierBill {
  id: number
  number: string
  status: BillStatus
  supplier: Ref | null
  goods_receipt: { id: number; number: string } | null
  supplier_invoice_no: string | null
  bill_date: string
  due_date: string | null
  total: string
  amount_paid: string
  balance: string
  overdue: boolean
  payments?: Array<{ id: number; number: string; paid_at: string; method: string | null; amount: string; status: string }>
}

export interface SupplierPaymentRow {
  id: number
  number: string
  status: 'posted' | 'void'
  supplier: Ref | null
  paid_at: string
  amount: string
  method: string | null
  reference_no: string | null
  notes: string | null
  created_by: Ref | null
  allocations: Array<{ bill_id: number; bill_number: string | null; amount: string }>
}

export interface BillableReceipt {
  id: number
  number: string
  supplier: Ref | null
  branch: string | null
  received_at: string
  supplier_invoice_no: string | null
  total: string
}

export interface Statement {
  supplier: { id: number; name: string; payment_terms_days: number }
  balance: string
  entries: Array<{ date: string; type: 'bill' | 'payment'; id: number; number: string; reference: string | null; amount: string; balance: string }>
}

export interface BillFilters {
  page?: number
  supplier_id?: number
  status?: BillStatus | 'unpaid' | 'overdue'
  search?: string
}

const clean = <T extends object>(p: T) => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>

export const payableKeys = {
  all: ['payables'] as const,
  bills: (f: BillFilters) => ['payables', 'bills', f] as const,
  bill: (id: number) => ['payables', 'bill', id] as const,
  payments: (f: { page?: number; supplier_id?: number }) => ['payables', 'payments', f] as const,
  billable: (supplierId?: number) => ['payables', 'billable', supplierId ?? null] as const,
  statement: (id: number) => ['payables', 'statement', id] as const,
  methods: ['payables', 'methods'] as const,
}

export const payablesApi = {
  bills: (f: BillFilters) => api.getPage<SupplierBill>(`${V1}/supplier-bills`, clean({ per_page: 25, ...f })),
  bill: (id: number) => api.get<SupplierBill>(`${V1}/supplier-bills/${id}`),
  billable: (supplierId?: number) => api.get<BillableReceipt[]>(`${V1}/supplier-bills/billable-receipts`, { params: supplierId ? { supplier_id: supplierId } : {} }),
  createBill: (body: { goods_receipt_id?: number | null; supplier_id?: number | null; supplier_invoice_no?: string | null; bill_date: string; due_date?: string | null; total?: string | null }) =>
    api.post<SupplierBill>(`${V1}/supplier-bills`, body),
  voidBill: (id: number, reason: string) => api.post<SupplierBill>(`${V1}/supplier-bills/${id}/void`, { reason }),
  payments: (f: { page?: number; supplier_id?: number }) => api.getPage<SupplierPaymentRow>(`${V1}/supplier-payments`, clean({ per_page: 25, ...f })),
  methods: () => api.get<Array<{ id: number; name: string; type: string }>>(`${V1}/supplier-payments/methods`),
  pay: (body: {
    supplier_id: number
    payment_method_id: number | null
    paid_at: string
    amount: string
    reference_no: string | null
    notes: string | null
    paid_from_drawer: boolean
    allocations: Array<{ supplier_bill_id: number; amount: string }>
  }) => api.post<SupplierPaymentRow>(`${V1}/supplier-payments`, body),
  voidPayment: (id: number, reason: string) => api.post<SupplierPaymentRow>(`${V1}/supplier-payments/${id}/void`, { reason }),
  statement: (supplierId: number) => api.get<Statement>(`${V1}/suppliers/${supplierId}/statement`),
}

export const BILL_STATUS: Record<BillStatus, { label: string; tone: 'info' | 'warning' | 'success' | 'neutral' }> = {
  open: { label: 'Unpaid', tone: 'info' },
  partially_paid: { label: 'Partly paid', tone: 'warning' },
  paid: { label: 'Paid', tone: 'success' },
  void: { label: 'Void', tone: 'neutral' },
}
