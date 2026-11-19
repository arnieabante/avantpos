import { useCallback, useEffect, useMemo, type ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { PageLoader } from '@/components/ui'
import { ChangePasswordRequiredScreen } from '@/features/auth/ChangePasswordRequiredScreen'
import { onSessionEvent } from '@/lib/api/client'
import { ADMIN_SESSION_KEY, AdminAuthContext, adminAuthApi, fetchAdmin, type AdminAuthValue, type PlatformAdmin } from './adminSession'
import { useAdminAuth } from './useAdminAuth'

export function AdminAuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ADMIN_SESSION_KEY, queryFn: fetchAdmin, staleTime: 5 * 60_000, retry: false })

  const setAdmin = useCallback((admin: PlatformAdmin | null) => queryClient.setQueryData(ADMIN_SESSION_KEY, admin), [queryClient])

  useEffect(
    () =>
      onSessionEvent((event) => {
        if (event.scope !== 'admin') return
        if (event.type === 'unauthenticated') setAdmin(null)
        if (event.type === 'password-change-required') void queryClient.invalidateQueries({ queryKey: ADMIN_SESSION_KEY })
      }),
    [setAdmin, queryClient],
  )

  const signOut = useCallback(async () => {
    try {
      await adminAuthApi.logout()
    } catch {
      /* already signed out */
    }
    queryClient.removeQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('admin') })
    setAdmin(null)
  }, [queryClient, setAdmin])

  const refresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: ADMIN_SESSION_KEY })
  }, [queryClient])

  const value = useMemo<AdminAuthValue>(
    () => ({ admin: query.data ?? null, isLoading: query.isPending, signIn: setAdmin, signOut, refresh }),
    [query.data, query.isPending, setAdmin, signOut, refresh],
  )

  return <AdminAuthContext.Provider value={value}>{children}</AdminAuthContext.Provider>
}

export function RequireAdmin() {
  const { admin, isLoading, signIn, signOut } = useAdminAuth()
  const location = useLocation()

  if (isLoading) return <PageLoader fullScreen />
  if (!admin) return <Navigate to={`/admin/login?redirect=${encodeURIComponent(location.pathname)}`} replace />
  if (admin.must_change_password) {
    return (
      <ChangePasswordRequiredScreen
        dark
        email={admin.email}
        onSubmit={async (values) => signIn(await adminAuthApi.changePassword(values))}
        onSignOut={() => void signOut()}
      />
    )
  }
  return <Outlet />
}

export function AdminGuestOnly() {
  const { admin, isLoading } = useAdminAuth()
  if (isLoading) return <PageLoader fullScreen />
  if (admin) return <Navigate to="/admin" replace />
  return <Outlet />
}
