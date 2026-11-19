import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { onSessionEvent, setActiveBranchId } from '@/lib/api/client'
import { ApiError } from '@/lib/api/errors'
import { storage } from '@/lib/utils'
import { authApi } from './api'
import {
  AuthContext,
  SESSION_QUERY_KEY,
  branchStorageKey,
  accountCache,
  fetchSession,
  pickActiveBranch,
  type AuthContextValue,
  type SessionState,
} from './session'
import type { Account } from './types'

/** Provides the tenant-user session (see ./session.ts) to everything below it. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: SESSION_QUERY_KEY,
    queryFn: fetchSession,
    staleTime: 5 * 60_000,
    retry: (failureCount, error) => ApiError.from(error).code === 'NETWORK_ERROR' && failureCount < 2,
  })

  const session = query.data
  const account = session?.status === 'authenticated' ? session.account : null

  /** Drop every cached query except the session (business data must never leak across users/branches). */
  const clearBusinessData = useCallback(() => {
    queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== SESSION_QUERY_KEY[0] })
  }, [queryClient])

  const setSession = useCallback(
    (next: SessionState) => queryClient.setQueryData<SessionState>(SESSION_QUERY_KEY, next),
    [queryClient],
  )

  // Any tenant API call answering 401 / "business suspended" updates the session.
  useEffect(
    () =>
      onSessionEvent((event) => {
        if (event.scope !== 'tenant') return
        if (event.type === 'branch-invalid' || event.type === 'password-change-required') {
          // Reload the account: its branch list no longer contains the selected branch,
          // so pickActiveBranch() falls back to an accessible one.
          void queryClient.invalidateQueries({ queryKey: SESSION_QUERY_KEY })
          return
        }
        clearBusinessData()
        setSession(event.type === 'unauthenticated' ? { status: 'guest' } : { status: 'blocked', error: event.error })
      }),
    [clearBusinessData, setSession, queryClient],
  )

  // Branch selection, remembered per user on this device. The in-memory choice is
  // keyed by user so it never carries over to the next person signing in.
  const [selection, setSelection] = useState<{ userId: number; branchId: number } | null>(null)
  const storedBranchId = account
    ? selection?.userId === account.user.id
      ? selection.branchId
      : Number(storage.get(branchStorageKey(account.user.id))) || null
    : null
  const activeBranch = account ? pickActiveBranch(account, storedBranchId) : null

  // Set synchronously during render so the very first child request already
  // carries X-Branch-Id (child effects run before parent effects).
  setActiveBranchId(activeBranch?.id ?? null)

  const setActiveBranch = useCallback(
    (branchId: number) => {
      if (!account || !account.branches.some((b) => b.id === branchId)) return
      storage.set(branchStorageKey(account.user.id), String(branchId))
      setActiveBranchId(branchId)
      clearBusinessData()
      setSelection({ userId: account.user.id, branchId })
    },
    [account, clearBusinessData],
  )

  const signIn = useCallback(
    (next: Account) => {
      clearBusinessData()
      accountCache.save(next)
      setSession({ status: 'authenticated', account: next })
    },
    [clearBusinessData, setSession],
  )

  const signOut = useCallback(async () => {
    try {
      await authApi.logout()
    } catch {
      // Already signed out / network issue: still clear local state.
    }
    setActiveBranchId(null)
    accountCache.clear()
    clearBusinessData()
    setSession({ status: 'guest' })
  }, [clearBusinessData, setSession])

  const refresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: SESSION_QUERY_KEY })
  }, [queryClient])

  const permissions = useMemo(() => new Set(account?.permissions ?? []), [account])
  const can = useCallback((permission: string) => permissions.has(permission), [permissions])
  const canAny = useCallback((list: string[]) => list.some((p) => permissions.has(p)), [permissions])

  const { isPending, isError, error, refetch } = query
  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      isLoading: isPending,
      loadError: isError ? ApiError.from(error) : null,
      retry: () => void refetch(),
      account,
      signIn,
      signOut,
      refresh,
      can,
      canAny,
      activeBranch,
      setActiveBranch,
    }),
    [session, isPending, isError, error, refetch, account, signIn, signOut, refresh, can, canAny, activeBranch, setActiveBranch],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
