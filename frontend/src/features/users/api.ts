import { api } from '@/lib/api/client'
import type { Invitation, PermissionGroup, Role, TeamUser } from './types'

const V1 = '/api/v1'

export interface UserFilters {
  page?: number
  search?: string
  status?: 'active' | 'inactive'
  role_id?: number
  branch_id?: number
}

export interface UserInput {
  name: string
  phone: string
  role_id: number | ''
  branch_ids: number[]
}

export interface InviteInput {
  email: string
  name: string
  role_id: number | ''
  branch_ids: number[]
}

export interface NewUserInput {
  name: string
  email: string
  phone: string
  role_id: number | ''
  branch_ids: number[]
  password: string
  password_confirmation: string
}

export interface RoleInput {
  name: string
  description: string
  permissions: string[]
}

export const usersKeys = {
  list: (filters: UserFilters) => ['users', filters] as const,
  invitations: (page: number) => ['invitations', page] as const,
  roles: ['roles'] as const,
  role: (id: number) => ['roles', id] as const,
  assignableRoles: ['roles', 'assignable'] as const,
  permissions: ['permissions'] as const,
}

/** Drops empty filters so they are not sent as "". */
function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '' && v !== null)) as Partial<T>
}

export const usersApi = {
  /* Users */
  list: (filters: UserFilters) => api.getPage<TeamUser>(`${V1}/users`, clean({ per_page: 25, ...filters })),
  create: (data: NewUserInput) => api.post<TeamUser>(`${V1}/users`, { ...data, email: data.email.trim(), phone: data.phone.trim() || null }),
  update: (id: number, data: Partial<UserInput>) =>
    api.put<TeamUser>(`${V1}/users/${id}`, { ...data, phone: data.phone === '' ? null : data.phone }),
  action: (id: number, action: 'activate' | 'deactivate') => api.post<TeamUser>(`${V1}/users/${id}/${action}`),
  clearPin: (id: number) => api.send('delete', `${V1}/users/${id}/pin`),
  sendPasswordReset: (id: number) => api.send('post', `${V1}/users/${id}/password-reset`),
  setPassword: (id: number, password: string, password_confirmation: string) => api.send('put', `${V1}/users/${id}/password`, { password, password_confirmation }),
  assignableRoles: () => api.get<Role[]>(`${V1}/roles/assignable`),

  /* Invitations */
  invitations: (page: number) => api.getPage<Invitation>(`${V1}/invitations`, { page, per_page: 25 }),
  invite: (data: InviteInput) => api.post<Invitation>(`${V1}/invitations`, { ...data, name: data.name || null }),
  resendInvitation: (id: number) => api.post<Invitation>(`${V1}/invitations/${id}/resend`),
  revokeInvitation: (id: number) => api.delete(`${V1}/invitations/${id}`),

  /* Roles */
  roles: () => api.get<Role[]>(`${V1}/roles`),
  role: (id: number) => api.get<Role>(`${V1}/roles/${id}`),
  createRole: (data: RoleInput) => api.post<Role>(`${V1}/roles`, { ...data, description: data.description || null }),
  updateRole: (id: number, data: RoleInput) => api.put<Role>(`${V1}/roles/${id}`, { ...data, description: data.description || null }),
  deleteRole: (id: number) => api.delete(`${V1}/roles/${id}`),
  permissions: () => api.get<PermissionGroup[]>(`${V1}/permissions`),
}
