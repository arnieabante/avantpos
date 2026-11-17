import { createContext } from 'react'
import { ApiError, ErrorCode, SESSION_BLOCKING_CODES } from '@/lib/api/errors'
import { authApi } from './api'
import type { Account, BranchSummary } from './types'

/**
 * Tenant-user session state, loaded from GET /api/v1/auth/me.
 *
 *  - guest:         not signed in
 *  - authenticated: signed in, `account` describes user/business/permissions/branches
 *  - blocked:       signed in, but the business is suspended/cancelled, the user is
 *                   deactivated, or the app is opened on another business's subdomain
 */
export type SessionState =
  | { status: 'guest' }
  | { status: 'authenticated'; account: Account }
  | { status: 'blocked'; error: ApiError }

export const SESSION_QUERY_KEY = ['session'] as const

export interface AuthContextValue {
  /** undefined while the first /me request is in flight */
  session: SessionState | undefined
  isLoading: boolean
  /** Network/server failure while loading the session (not "signed out"). */
  loadError: ApiError | null
  retry: () => void
  account: Account | null
  /** Store the account returned by login/register/accept-invitation. */
  signIn: (account: Account) => void
  signOut: () => Promise<void>
  refresh: () => Promise<void>
  can: (permission: string) => boolean
  canAny: (permissions: string[]) => boolean
  activeBranch: BranchSummary | null
  setActiveBranch: (branchId: number) => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

const ACCOUNT_CACHE_KEY = 'avantpos.account'

/**
 * The last signed-in account, kept so the POS can start while the internet is
 * down (offline selling). Cleared on sign-out and when the server says the
 * session ended.
 */
export const accountCache = {
  save(account: Account): void {
    try {
      window.localStorage.setItem(ACCOUNT_CACHE_KEY, JSON.stringify(account))
    } catch {
      // storage unavailable: offline start-up just is not possible
    }
  },
  load(): Account | null {
    try {
      const raw = window.localStorage.getItem(ACCOUNT_CACHE_KEY)
      return raw ? (JSON.parse(raw) as Account) : null
    } catch {
      return null
    }
  },
  clear(): void {
    try {
      window.localStorage.removeItem(ACCOUNT_CACHE_KEY)
    } catch {
      // ignore
    }
  },
}

export async function fetchSession(): Promise<SessionState> {
  try {
    const account = await authApi.me()
    accountCache.save(account)
    return { status: 'authenticated', account }
  } catch (error) {
    const apiError = ApiError.from(error)
    // No connection: carry on with the last account (the server re-checks everything when back online).
    if (apiError.code === ErrorCode.Network) {
      const cached = accountCache.load()
      if (cached) return { status: 'authenticated', account: cached }
    }
    accountCache.clear()
    if (apiError.status === 401) return { status: 'guest' }
    if (apiError.status === 403 && SESSION_BLOCKING_CODES.includes(apiError.code)) {
      return { status: 'blocked', error: apiError }
    }
    throw apiError
  }
}

export const branchStorageKey = (userId: number) => `avantpos.branch.${userId}`

/** The stored branch if the user still has access to it, else the main branch, else the first. */
export function pickActiveBranch(account: Account, storedId: number | null): BranchSummary | null {
  const { branches } = account
  return branches.find((b) => b.id === storedId) ?? branches.find((b) => b.is_main) ?? branches[0] ?? null
}
