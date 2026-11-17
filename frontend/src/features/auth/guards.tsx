import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation, useSearchParams } from 'react-router'
import { RefreshCw, ShieldAlert } from 'lucide-react'
import { Button, EmptyState, PageLoader } from '@/components/ui'
import { safeRedirectPath } from '@/lib/utils'
import { SessionBlockedScreen } from './SessionBlockedScreen'
import { useAuth } from './useAuth'

function SessionLoadFailed() {
  const { loadError, retry } = useAuth()
  return (
    <div className="flex min-h-dvh items-center justify-center p-4">
      <EmptyState
        icon={<RefreshCw className="size-5" />}
        title="We couldn't load your account"
        description={loadError?.message}
        action={<Button onClick={retry}>Try again</Button>}
      />
    </div>
  )
}

/** Signed-in tenant users only. Guests go to /login?redirect=<current page>. */
export function RequireAuth() {
  const { session, isLoading, loadError } = useAuth()
  const location = useLocation()

  if (isLoading) return <PageLoader fullScreen label="Loading AvantPOS…" />
  if (loadError && !session) return <SessionLoadFailed />

  if (!session || session.status === 'guest') {
    const redirect = `${location.pathname}${location.search}`
    return <Navigate to={redirect === '/' ? '/login' : `/login?redirect=${encodeURIComponent(redirect)}`} replace />
  }

  if (session.status === 'blocked') return <SessionBlockedScreen error={session.error} />

  return <Outlet />
}

/** Login/register pages: signed-in users are sent on to the app. */
export function GuestOnly() {
  const { session, isLoading } = useAuth()
  const [params] = useSearchParams()

  if (isLoading) return <PageLoader fullScreen />
  if (session?.status === 'authenticated') {
    return <Navigate to={safeRedirectPath(params.get('redirect'))} replace />
  }

  return <Outlet />
}

/**
 * UI gate for screens/actions. The API enforces every permission itself;
 * this only avoids showing users things they cannot do.
 */
export function RequirePermission({ permission, children }: { permission: string | string[]; children: ReactNode }) {
  const { canAny } = useAuth()
  const list = Array.isArray(permission) ? permission : [permission]

  if (!canAny(list)) {
    return (
      <EmptyState
        icon={<ShieldAlert className="size-5" />}
        title="You don't have access to this page"
        description="Ask your business owner or administrator to give your role access."
      />
    )
  }

  return <>{children}</>
}

/** Renders children only when the user has the permission (for buttons, menu items...). */
export function Can({ permission, children, fallback = null }: { permission: string | string[]; children: ReactNode; fallback?: ReactNode }) {
  const { canAny } = useAuth()
  return <>{canAny(Array.isArray(permission) ? permission : [permission]) ? children : fallback}</>
}
