/** Mirrors backend `App\Http\Resources\AccountResource` (GET /api/v1/auth/me). */

export interface AccountUser {
  id: number
  name: string
  email: string
  phone: string | null
  email_verified: boolean
  has_pin: boolean
  is_owner: boolean
  last_login_at: string | null
}

export type TenantStatus = 'active' | 'suspended' | 'cancelled' | string

export interface AccountTenant {
  id: number
  name: string
  subdomain: string | null
  status: TenantStatus
  business_type: string | null
  vat_registered: boolean
  timezone: string
  currency: string
  logo_url: string | null
}

export interface BranchSummary {
  id: number
  code: string
  name: string
  is_main: boolean
}

export type PlanLimitKey = 'branches' | 'users' | 'terminals' | 'products'

export interface AccountSubscription {
  status: string
  billing_cycle: string | null
  trial_ends_at: string | null
  current_period_end: string | null
  plan: {
    code: string | null
    name: string | null
    /** null = unlimited */
    limits: Record<PlanLimitKey, number | null> | null
    features: Record<string, boolean> | null
  }
}

export interface Account {
  user: AccountUser
  tenant: AccountTenant
  roles: string[]
  permissions: string[]
  branches: BranchSummary[]
  subscription: AccountSubscription | null
}

export interface LoginPayload {
  email: string
  password: string
  remember: boolean
}

export interface RegisterPayload {
  business_name: string
  business_type: string
  subdomain: string
  vat_registered: boolean
  owner_name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
  accept_terms: boolean
}

export interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export interface InvitationPreview {
  business_name: string
  email: string
  name: string | null
  role: string | null
  expires_at: string
}

export interface AcceptInvitationPayload {
  name: string
  phone: string
  password: string
  password_confirmation: string
}

export interface SetPinPayload {
  current_password: string
  pin: string
  pin_confirmation: string
}

/** Business types accepted by RegisterTenantRequest::BUSINESS_TYPES. */
export const BUSINESS_TYPES: Array<{ value: string; label: string }> = [
  { value: 'sari_sari', label: 'Sari-sari store' },
  { value: 'grocery', label: 'Grocery / minimart' },
  { value: 'hardware', label: 'Hardware / construction supply' },
  { value: 'motor_shop', label: 'Motor parts & accessories' },
  { value: 'apparel', label: 'Apparel / boutique' },
  { value: 'pharmacy', label: 'Pharmacy / drugstore' },
  { value: 'electronics', label: 'Electronics / gadgets' },
  { value: 'bookstore', label: 'Bookstore / school supplies' },
  { value: 'general_merchandise', label: 'General merchandise' },
  { value: 'other', label: 'Other' },
]
