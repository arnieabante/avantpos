import { api } from '@/lib/api/client'
import type { PlanLimitKey } from '@/features/auth/types'

const V1 = '/api/v1/billing'

export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'grace' | 'expired' | 'cancelled'
export type Cycle = 'monthly' | 'annual'

export interface BillingPlan {
  code: string
  name: string
  description: string | null
  monthly_price: string
  annual_price: string
  trial_days: number
  limits: Record<PlanLimitKey, number | null>
  features: Record<string, boolean>
  free: boolean
  current: boolean
}

export interface BillingInvoice {
  id: number
  number: string
  kind: 'new' | 'renewal' | 'upgrade'
  plan: { code: string; name: string } | null
  billing_cycle: Cycle
  period_start: string | null
  period_end: string | null
  subtotal: string
  credit: string
  vat_amount: string
  total: string
  status: 'open' | 'paid' | 'void' | 'expired'
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
  gateway: string | null
  checkout_url: string | null
  payments: Array<{ gateway: string; channel: string | null; reference: string | null; amount: string; status: 'pending' | 'paid' | 'failed'; paid_at: string | null; rejection_reason: string | null }>
  bill_to?: { name: string; tin: string | null; email: string | null; address: string | null }
  vat_registered?: boolean
}

export interface Billing {
  subscription: {
    status: SubscriptionStatus
    plan: { code: string | null; name: string | null; free: boolean }
    billing_cycle: Cycle
    trial_ends_at: string | null
    current_period_start: string | null
    current_period_end: string | null
    grace_ends_at: string | null
    cancel_at_period_end: boolean
    pending_plan: { code: string; name: string } | null
    pending_billing_cycle: Cycle | null
  }
  plans: BillingPlan[]
  usage: Record<PlanLimitKey, number>
  invoices: BillingInvoice[]
  payment: { gateway: 'manual' | 'xendit' | 'paymongo'; bank_details: string | null }
}

export const billingKeys = {
  all: ['billing'] as const,
  invoice: (id: number) => ['billing', 'invoice', id] as const,
}

export const billingApi = {
  get: () => api.get<Billing>(V1),
  invoice: (id: number) => api.get<BillingInvoice>(`${V1}/invoices/${id}`),
  checkout: (plan: string, billing_cycle: Cycle) =>
    api.post<{ invoice: BillingInvoice | null; checkout_url: string | null; scheduled: boolean; message: string }>(`${V1}/checkout`, { plan, billing_cycle }),
  pay: (id: number) => api.post<{ checkout_url: string | null; invoice: BillingInvoice }>(`${V1}/invoices/${id}/pay`),
  manualPayment: (id: number, body: { reference: string; channel: string; amount: string; paid_on: string; notes: string | null }) =>
    api.post<BillingInvoice>(`${V1}/invoices/${id}/manual-payment`, body),
  cancel: () => api.send('post', `${V1}/cancel`),
  resume: () => api.send('post', `${V1}/resume`),
}

/** Plan features, as shown to business owners. */
export const FEATURE_LABELS: Record<string, string> = {
  pos: 'Point of sale',
  inventory: 'Inventory',
  customers: 'Customers',
  receipts_thermal: 'Thermal receipts',
  offline_pos: 'Offline selling',
  multi_branch: 'Multiple branches',
  stock_transfers: 'Stock transfers',
  purchasing: 'Purchasing & supplier bills',
  expenses: 'Expenses',
  loyalty: 'Loyalty points',
  credit_sales: 'Customer credit (utang)',
  batch_expiry: 'Batches & expiry dates',
  unit_conversions: 'Units (box, pack, kilo)',
  advanced_reports: 'Advanced reports',
  report_exports: 'CSV / Excel exports',
  custom_roles: 'Custom roles',
  custom_subdomain: 'Own web address',
  audit_logs: 'Audit log',
  api_access: 'API access',
  priority_support: 'Priority support',
}

export const STATUS_LABEL: Record<SubscriptionStatus, { label: string; tone: 'brand' | 'success' | 'warning' | 'danger' | 'neutral' }> = {
  trialing: { label: 'Free trial', tone: 'brand' },
  active: { label: 'Active', tone: 'success' },
  past_due: { label: 'Payment due', tone: 'warning' },
  grace: { label: 'Payment due', tone: 'warning' },
  expired: { label: 'Expired', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}
