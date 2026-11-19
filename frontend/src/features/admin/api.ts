import { api } from '@/lib/api/client'

const A = '/api/admin/v1'
// Admin calls report 401s so an expired admin session returns to the admin login.
const opts = {}

export interface Page<T> {
  items: T[]
  pagination: { current_page?: number; last_page?: number; per_page?: number; total?: number }
}

export interface AdminDashboard {
  tenants: { total: number; by_status: Record<string, number>; new_30_days: number; new_7_days: number }
  subscriptions: { by_status: Record<string, number>; by_plan: Record<string, number>; paying: number }
  mrr: string
  arr: string
  signups: Array<{ date: string; signups: number }>
  expiring: Array<{ tenant: { id: number; name: string }; plan: string | null; status: string; ends_at: string | null; cancel_at_period_end: boolean }>
  payments: {
    last_30_days: string
    count_30_days: number
    pending_verification: number
    recent: Array<{ id: number; tenant: string | null; invoice: string | null; amount: string; gateway: string; channel: string | null; status: string; at: string | null }>
  }
  support: { open: number; waiting: number }
}

export interface TenantRow {
  id: number
  name: string
  slug: string
  subdomain: string | null
  email: string | null
  status: string
  owner: { name: string; email: string } | null
  plan: string | null
  subscription_status: string | null
  ends_at: string | null
  /** When the business next has to pay (trial end, paid until, or pay-by date when overdue). */
  due_at: string | null
  overdue: boolean
  usage: Record<'branches' | 'users' | 'terminals' | 'products', number>
  created_at: string
}

export interface TenantDetail extends TenantRow {
  registered_name: string | null
  tin: string | null
  vat_registered: boolean
  phone: string | null
  business_type: string | null
  suspended_at: string | null
  suspension_reason: string | null
  activity: { sales_30_days: number; last_sale_at: string | null; last_login_at: string | null }
  subscription: {
    status: string
    plan: { code: string | null; name: string | null }
    billing_cycle: 'monthly' | 'annual'
    trial_ends_at: string | null
    current_period_start: string | null
    current_period_end: string | null
    grace_ends_at: string | null
    cancel_at_period_end: boolean
    pending_plan: string | null
    due_at: string | null
    overdue: boolean
  } | null
  invoices: Array<{ id: number; number: string; kind: string; plan: string | null; total: string; status: string; due_at: string | null; paid_at: string | null }>
  history: Array<{ type: string; from: string | null; to: string | null; by: string | null; effective_at: string | null }>
}

export interface AdminPlan {
  id: number
  code: string
  name: string
  description: string | null
  monthly_price: string
  annual_price: string
  trial_days: number
  grace_days: number
  max_branches: number | null
  max_users: number | null
  max_terminals: number | null
  max_products: number | null
  is_public: boolean
  is_active: boolean
  sort_order: number
  features: Record<string, boolean>
  businesses?: number
}

export interface AdminPayment {
  id: number
  tenant: { id: number; name: string } | null
  invoice: { id: number; number: string; total: string; plan: string | null; status: string } | null
  gateway: string
  channel: string | null
  reference: string | null
  amount: string
  status: 'pending' | 'paid' | 'failed'
  paid_at: string | null
  submitted_at: string | null
  paid_on: string | null
  notes: string | null
  verified_by: string | null
  rejection_reason: string | null
}

export interface AdminInvoice {
  id: number
  number: string
  tenant: { id: number; name: string } | null
  plan: string | null
  billing_cycle: string
  kind: string
  total: string
  status: string
  gateway: string | null
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
}

export interface ActivityRow {
  id: number
  event: string
  tenant: { id: number; name: string | null } | null
  actor: { type: string | null; name: string | null }
  ip: string | null
  subject: string | null
  meta: Record<string, unknown> | null
  created_at: string
}

export interface AdminTicket {
  id: number
  number: string
  subject: string
  category: string | null
  priority: string
  status: 'open' | 'pending' | 'resolved' | 'closed'
  tenant: { id: number; name: string } | null
  opened_by: { name: string; email: string } | null
  assigned_to: { id: number; name: string } | null
  created_at: string
  updated_at: string
  messages?: Array<{ id: number; from: 'business' | 'support'; author: string; body: string; created_at: string }>
}

export interface PlatformSettingsData {
  billing: { bank_details: string | null }
  support: { email: string | null; phone: string | null }
  platform: { announcement: string | null; registrations_open: boolean }
  gateways: { active: string; xendit_configured: boolean; paymongo_configured: boolean }
}

const clean = (p: Record<string, unknown>) => Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== '' && v !== null))

export type AdminRole = 'super_admin' | 'billing' | 'support'

export interface TeamAdmin {
  id: number
  name: string
  email: string
  role: AdminRole
  is_active: boolean
  two_factor: boolean
  last_login_at: string | null
  created_at: string | null
}

export const adminKeys = {
  all: ['admin'] as const,
  dashboard: ['admin', 'dashboard'] as const,
  tenants: (f: Record<string, unknown>) => ['admin', 'tenants', f] as const,
  tenant: (id: number) => ['admin', 'tenant', id] as const,
  plans: ['admin', 'plans'] as const,
  payments: (f: Record<string, unknown>) => ['admin', 'payments', f] as const,
  invoices: (f: Record<string, unknown>) => ['admin', 'invoices', f] as const,
  activity: (f: Record<string, unknown>) => ['admin', 'activity', f] as const,
  tickets: (f: Record<string, unknown>) => ['admin', 'tickets', f] as const,
  ticket: (id: number) => ['admin', 'ticket', id] as const,
  settings: ['admin', 'settings'] as const,
  team: ['admin', 'team'] as const,
}

export const adminApi = {
  dashboard: () => api.get<AdminDashboard>(`${A}/dashboard`, opts),
  tenants: (f: Record<string, unknown>) => api.getPage<TenantRow>(`${A}/tenants`, clean({ per_page: 25, ...f }), opts),
  tenant: (id: number) => api.get<TenantDetail>(`${A}/tenants/${id}`, opts),
  suspend: (id: number, reason: string) => api.post<TenantDetail>(`${A}/tenants/${id}/suspend`, { reason }, opts),
  activate: (id: number) => api.post<TenantDetail>(`${A}/tenants/${id}/activate`, undefined, opts),
  adjustSubscription: (id: number, body: Record<string, unknown>) => api.put<TenantDetail>(`${A}/tenants/${id}/subscription`, body, opts),
  plans: () => api.get<{ plans: AdminPlan[]; feature_keys: string[] }>(`${A}/plans`, opts),
  savePlan: (body: Omit<AdminPlan, 'id' | 'businesses'>, id?: number) => (id ? api.put<AdminPlan>(`${A}/plans/${id}`, body, opts) : api.post<AdminPlan>(`${A}/plans`, body, opts)),
  payments: (f: Record<string, unknown>) => api.getPage<AdminPayment>(`${A}/payments`, clean({ per_page: 25, ...f }), opts),
  verifyPayment: (id: number) => api.send('post', `${A}/payments/${id}/verify`, undefined, opts),
  rejectPayment: (id: number, reason: string) => api.send('post', `${A}/payments/${id}/reject`, { reason }, opts),
  invoices: (f: Record<string, unknown>) => api.getPage<AdminInvoice>(`${A}/invoices`, clean({ per_page: 25, ...f }), opts),
  activity: (f: Record<string, unknown>) => api.getPage<ActivityRow>(`${A}/activity`, clean({ per_page: 50, ...f }), opts),
  tickets: (f: Record<string, unknown>) => api.getPage<AdminTicket>(`${A}/tickets`, clean({ per_page: 25, ...f }), opts),
  ticket: (id: number) => api.get<AdminTicket>(`${A}/tickets/${id}`, opts),
  replyTicket: (id: number, body: string, status: string | null) => api.post<AdminTicket>(`${A}/tickets/${id}/reply`, { body, status }, opts),
  updateTicket: (id: number, body: Record<string, unknown>) => api.patch<AdminTicket>(`${A}/tickets/${id}`, body, opts),
  settings: () => api.get<PlatformSettingsData>(`${A}/settings`, opts),
  saveSettings: (body: Partial<Omit<PlatformSettingsData, 'gateways'>>) => api.put<PlatformSettingsData>(`${A}/settings`, body, opts),
  team: () => api.get<TeamAdmin[]>(`${A}/admins`, opts),
  addAdmin: (body: { name: string; email: string; role: AdminRole; password: string; password_confirmation: string }) => api.post<TeamAdmin>(`${A}/admins`, body, opts),
  updateAdmin: (id: number, body: Partial<Pick<TeamAdmin, 'name' | 'role' | 'is_active'>>) => api.patch<TeamAdmin>(`${A}/admins/${id}`, body, opts),
  resetAdminPassword: (id: number, password: string, confirmation: string) => api.send('post', `${A}/admins/${id}/password`, { password, password_confirmation: confirmation }, opts),
  resetAdminTwoFactor: (id: number) => api.post<TeamAdmin>(`${A}/admins/${id}/reset-two-factor`, undefined, opts),
  twoFactorSetup: () => api.post<{ secret: string; otpauth_uri: string }>(`${A}/auth/2fa/setup`, undefined, opts),
  twoFactorConfirm: (code: string) => api.post<{ two_factor: boolean }>(`${A}/auth/2fa/confirm`, { code }, opts),
  twoFactorDisable: (password: string, code: string) => api.post<{ two_factor: boolean }>(`${A}/auth/2fa/disable`, { password, code }, opts),
}
