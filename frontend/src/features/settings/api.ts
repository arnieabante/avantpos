import { api, http } from '@/lib/api/client'
import type {
  AddressInput,
  Branch,
  Business,
  CashRegister,
  DiscountPreset,
  OperatingHours,
  PaymentMethod,
  PsgcOption,
  ReceiptTemplate,
  TaxRate,
  Terminal,
} from './types'

const V1 = '/api/v1'

/** Query keys for this module (invalidated after mutations). */
export const settingsKeys = {
  business: ['business'] as const,
  branches: (params?: object) => ['branches', params ?? {}] as const,
  terminals: ['terminals'] as const,
  cashRegisters: ['cash-registers'] as const,
  taxRates: ['tax-rates'] as const,
  paymentMethods: ['payment-methods'] as const,
  discounts: ['discount-presets'] as const,
  receipts: ['receipt-templates'] as const,
  psgc: (level: string, parent: string) => ['psgc', level, parent] as const,
}

/** Empty strings become null so optional fields are cleared, not stored as "". */
function nullable<T extends object>(data: T): T {
  return Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])) as T
}

export type BusinessInput = AddressInput & {
  name: string
  business_type: string
  subdomain: string
  registered_name: string
  business_style: string
  tin: string
  tin_branch_code: string
  rdo_code: string
  vat_registered: boolean
  email: string
  phone: string
  timezone: string
  date_format: string
  time_format: string
}

export type BranchInput = AddressInput & {
  code: string
  name: string
  phone: string
  email: string
  bir_branch_code: string
  operating_hours: OperatingHours | null
}

export type TerminalInput = {
  branch_id: number | ''
  code: string
  name: string
  min: string
  serial_no: string
  ptu_no: string
  ptu_issued_at: string
  ptu_valid_until: string
  create_cash_register?: boolean
}

export type CashRegisterInput = { branch_id: number | ''; terminal_id: number | '' | null; name: string }
export type TaxRateInput = { name: string; type: TaxRate['type']; rate: string; is_active?: boolean }
export type PaymentMethodInput = { type?: PaymentMethod['type']; name: string; requires_reference: boolean; is_active: boolean; sort_order?: number }
export type DiscountInput = {
  name: string
  type: DiscountPreset['type']
  value: string
  scope: DiscountPreset['scope']
  requires_permission: boolean
  max_amount: string
  is_active: boolean
}
export type ReceiptTemplateInput = Pick<ReceiptTemplate, 'name' | 'paper' | 'show_logo' | 'layout'> & { header_text: string; footer_text: string }

export const settingsApi = {
  /* Business */
  business: () => api.get<Business>(`${V1}/business`),
  updateBusiness: (data: BusinessInput) => api.put<Business>(`${V1}/business`, nullable(data)),
  uploadLogo: (file: File) => {
    const body = new FormData()
    body.append('logo', file)
    return api.post<Business>(`${V1}/business/logo`, body)
  },
  removeLogo: () => http.delete<{ data: Business }>(`${V1}/business/logo`).then((r) => r.data.data),

  /* Branches */
  branches: (params?: { status?: 'active' | 'inactive'; search?: string }) => api.get<Branch[]>(`${V1}/branches`, { params }),
  createBranch: (data: BranchInput) => api.post<Branch>(`${V1}/branches`, nullable(data)),
  updateBranch: (id: number, data: BranchInput) => api.put<Branch>(`${V1}/branches/${id}`, nullable(data)),
  branchAction: (id: number, action: 'activate' | 'deactivate' | 'make-main') => api.post<Branch>(`${V1}/branches/${id}/${action}`),

  /* Terminals & drawers */
  terminals: () => api.get<Terminal[]>(`${V1}/terminals`),
  createTerminal: (data: TerminalInput) => api.post<Terminal>(`${V1}/terminals`, nullable(data)),
  updateTerminal: (id: number, data: TerminalInput) => api.put<Terminal>(`${V1}/terminals/${id}`, nullable(data)),
  terminalAction: (id: number, action: 'activate' | 'deactivate' | 'retire') => api.post<Terminal>(`${V1}/terminals/${id}/${action}`),
  revokeDevice: (id: number) => api.delete(`${V1}/terminals/${id}/device`),
  cashRegisters: () => api.get<CashRegister[]>(`${V1}/cash-registers`),
  createCashRegister: (data: CashRegisterInput) => api.post<CashRegister>(`${V1}/cash-registers`, nullable(data)),
  updateCashRegister: (id: number, data: CashRegisterInput) => api.put<CashRegister>(`${V1}/cash-registers/${id}`, nullable(data)),
  cashRegisterAction: (id: number, action: 'activate' | 'deactivate') => api.post<CashRegister>(`${V1}/cash-registers/${id}/${action}`),

  /* Tax rates */
  taxRates: () => api.get<TaxRate[]>(`${V1}/tax-rates`),
  createTaxRate: (data: TaxRateInput) => api.post<TaxRate>(`${V1}/tax-rates`, data),
  updateTaxRate: (id: number, data: TaxRateInput) => api.put<TaxRate>(`${V1}/tax-rates/${id}`, data),
  makeDefaultTaxRate: (id: number) => api.post<TaxRate>(`${V1}/tax-rates/${id}/make-default`),

  /* Payment methods */
  paymentMethods: () => api.get<PaymentMethod[]>(`${V1}/payment-methods`),
  createPaymentMethod: (data: PaymentMethodInput) => api.post<PaymentMethod>(`${V1}/payment-methods`, data),
  updatePaymentMethod: (id: number, data: PaymentMethodInput) => api.put<PaymentMethod>(`${V1}/payment-methods/${id}`, data),

  /* Discounts */
  discounts: () => api.get<DiscountPreset[]>(`${V1}/discount-presets`),
  createDiscount: (data: DiscountInput) => api.post<DiscountPreset>(`${V1}/discount-presets`, nullable(data)),
  updateDiscount: (id: number, data: DiscountInput) => api.put<DiscountPreset>(`${V1}/discount-presets/${id}`, nullable(data)),

  /* Receipt templates */
  receiptTemplates: () => api.get<ReceiptTemplate[]>(`${V1}/receipt-templates`),
  createReceiptTemplate: (data: ReceiptTemplateInput) => api.post<ReceiptTemplate>(`${V1}/receipt-templates`, nullable(data)),
  updateReceiptTemplate: (id: number, data: ReceiptTemplateInput) => api.put<ReceiptTemplate>(`${V1}/receipt-templates/${id}`, nullable(data)),
  makeDefaultReceiptTemplate: (id: number) => api.post<ReceiptTemplate>(`${V1}/receipt-templates/${id}/make-default`),
  deleteReceiptTemplate: (id: number) => api.delete(`${V1}/receipt-templates/${id}`),

  /* PSGC */
  regions: () => api.get<PsgcOption[]>(`${V1}/ph/regions`),
  provinces: (region_code: string) => api.get<PsgcOption[]>(`${V1}/ph/provinces`, { params: { region_code } }),
  cities: (params: { region_code?: string; province_code?: string }) => api.get<PsgcOption[]>(`${V1}/ph/cities`, { params }),
  barangays: (city_code: string) => api.get<PsgcOption[]>(`${V1}/ph/barangays`, { params: { city_code } }),
}
