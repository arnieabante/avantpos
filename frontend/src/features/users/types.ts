/** Payloads of the user, invitation and role APIs. */

export interface TeamUser {
  id: number
  name: string
  email: string
  phone: string | null
  status: 'active' | 'inactive'
  email_verified: boolean
  has_pin: boolean
  is_owner: boolean
  role: { id: number; key: string | null; name: string } | null
  all_branches: boolean
  branches: Array<{ id: number; code: string; name: string }>
  last_login_at: string | null
  created_at: string | null
}

export type InvitationStatus = 'pending' | 'accepted' | 'revoked' | 'expired'

export interface Invitation {
  id: number
  email: string
  name: string | null
  role: { id: number; name: string } | null
  branch_ids: number[]
  status: InvitationStatus
  expires_at: string
  accepted_at: string | null
  created_at: string | null
}

export interface Role {
  id: number
  key: string | null
  name: string
  description: string | null
  is_system: boolean
  is_locked: boolean
  permissions?: string[]
  users_count?: number
}

export interface PermissionGroup {
  key: string
  label: string
  permissions: Array<{ name: string; label: string }>
}
