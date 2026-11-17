import { api } from '@/lib/api/client'
import type { GoodsReceipt, PoStatus, PurchasableItem, PurchaseOrder, Supplier, SupplierOption } from './types'

const V1 = '/api/v1'

function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null)) as Partial<T>
}

export interface SupplierFilters {
  page?: number
  search?: string
  status?: 'active' | 'inactive'
}

export interface SupplierInput {
  code: string
  name: string
  contact_person: string
  phone: string
  email: string
  tin: string
  vat_registered: boolean
  address: string
  payment_terms_days: string
  notes: string
}

export interface PurchaseFilters {
  page?: number
  search?: string
  status?: PoStatus | 'open' | 'posted' | 'reversed'
  supplier_id?: number
}

export interface PurchaseOrderPayload {
  supplier_id: number
  order_date: string | null
  expected_date: string | null
  notes: string | null
  approve: boolean
  items: Array<{ product_variant_id: number; product_unit_id: number | null; qty: string; unit_cost: string }>
}

export interface GoodsReceiptPayload {
  purchase_order_id: number | null
  supplier_id: number | null
  supplier_invoice_no: string | null
  supplier_invoice_date: string | null
  notes: string | null
  items: Array<{
    purchase_order_item_id?: number
    product_variant_id?: number
    product_unit_id?: number | null
    qty: string
    unit_cost?: string | null
    batch_no?: string | null
    expiry_date?: string | null
  }>
}

/** Purchasing changes stock and costs, so mutations also refresh inventory queries. */
export const purchasingKeys = {
  all: ['purchasing'] as const,
  suppliers: (f: SupplierFilters) => ['purchasing', 'suppliers', f] as const,
  supplierOptions: ['purchasing', 'supplier-options'] as const,
  orders: (f: PurchaseFilters) => ['purchasing', 'orders', f] as const,
  order: (id: number) => ['purchasing', 'order', id] as const,
  receipts: (f: PurchaseFilters) => ['purchasing', 'receipts', f] as const,
  receipt: (id: number) => ['purchasing', 'receipt', id] as const,
  lowStock: ['purchasing', 'low-stock'] as const,
}

const blank = (v: string) => (v.trim() === '' ? null : v.trim())

export const purchasingApi = {
  suppliers: (f: SupplierFilters) => api.getPage<Supplier>(`${V1}/suppliers`, clean({ per_page: 25, ...f })),
  supplierOptions: () => api.get<SupplierOption[]>(`${V1}/suppliers/options`),
  saveSupplier: (input: SupplierInput, id?: number) => {
    const body = {
      code: blank(input.code),
      name: input.name.trim(),
      contact_person: blank(input.contact_person),
      phone: blank(input.phone),
      email: blank(input.email),
      tin: blank(input.tin),
      vat_registered: input.vat_registered,
      address: blank(input.address),
      payment_terms_days: input.payment_terms_days.trim() === '' ? 0 : Number(input.payment_terms_days),
      notes: blank(input.notes),
    }
    return id ? api.put<Supplier>(`${V1}/suppliers/${id}`, body) : api.post<Supplier>(`${V1}/suppliers`, body)
  },
  supplierAction: (id: number, action: 'activate' | 'deactivate') => api.post<Supplier>(`${V1}/suppliers/${id}/${action}`),
  deleteSupplier: (id: number) => api.delete(`${V1}/suppliers/${id}`),

  searchItems: (q: string) => api.get<PurchasableItem[]>(`${V1}/purchasing/items`, { params: { q } }),
  lowStockItems: () => api.get<PurchasableItem[]>(`${V1}/purchasing/items`, { params: { low_stock: 1 } }),

  orders: (f: PurchaseFilters) => api.getPage<PurchaseOrder>(`${V1}/purchase-orders`, clean({ per_page: 25, ...f })),
  order: (id: number) => api.get<PurchaseOrder>(`${V1}/purchase-orders/${id}`),
  saveOrder: (payload: PurchaseOrderPayload, id?: number) =>
    id ? api.put<PurchaseOrder>(`${V1}/purchase-orders/${id}`, payload) : api.post<PurchaseOrder>(`${V1}/purchase-orders`, payload),
  orderAction: (id: number, action: 'approve' | 'cancel' | 'close') => api.post<PurchaseOrder>(`${V1}/purchase-orders/${id}/${action}`),

  receipts: (f: PurchaseFilters) => api.getPage<GoodsReceipt>(`${V1}/goods-receipts`, clean({ per_page: 25, ...f })),
  receipt: (id: number) => api.get<GoodsReceipt>(`${V1}/goods-receipts/${id}`),
  receive: (payload: GoodsReceiptPayload) => api.post<GoodsReceipt>(`${V1}/goods-receipts`, payload),
  reverseReceipt: (id: number, reason: string) => api.post<GoodsReceipt>(`${V1}/goods-receipts/${id}/reverse`, { reason }),
}
