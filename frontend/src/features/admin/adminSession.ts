import { createContext } from 'react'
import { api } from '@/lib/api/client'
import { ApiError } from '@/lib/api/errors'

/**
 * SaaS platform administrators use a separate login and guard (`platform`)
 * and the /api/admin/v1 API. Their session is independent of tenant users.
 */

export interface PlatformAdmin {
  id: number
  name: string
  email: string
  role: string
  /** Two-factor sign-in is on. */
  two_factor?: boolean
}

const BASE = '/api/admin/v1/auth'
export const ADMIN_SESSION_KEY = ['admin-session'] as const

export const adminAuthApi = {
  me: () => api.get<PlatformAdmin>(`${BASE}/me`, { skipSessionEvents: true }),
  login: (payload: { email: string; password: string; code?: string }) => api.post<PlatformAdmin>(`${BASE}/login`, payload),
  logout: () => api.send('post', `${BASE}/logout`, undefined, { skipSessionEvents: true }),
}

export interface AdminAuthValue {
  admin: PlatformAdmin | null
  isLoading: boolean
  signIn: (admin: PlatformAdmin) => void
  signOut: () => Promise<void>
  /** Reload the signed-in admin (e.g. after turning two-factor sign-in on). */
  refresh: () => Promise<void>
}

export const AdminAuthContext = createContext<AdminAuthValue | null>(null)

export async function fetchAdmin(): Promise<PlatformAdmin | null> {
  try {
    return await adminAuthApi.me()
  } catch (error) {
    if (ApiError.from(error).status === 401) return null
    throw error
  }
}

