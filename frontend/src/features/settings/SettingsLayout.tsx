import { Navigate, Outlet } from 'react-router'
import { EmptyState } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { SETTINGS_PAGES } from './settingsPages'

/**
 * Settings pages. Navigation between them lives in the sidebar
 * (Settings group); each page has its own header.
 */
export function SettingsLayout() {
  const { canAny } = useAuth()

  if (!SETTINGS_PAGES.some((p) => canAny(p.permissions))) {
    return <EmptyState title="No settings available" description="Your role has no access to business settings." />
  }

  return <Outlet />
}

/** /settings → first page the user may open. */
export function SettingsIndex() {
  const { canAny } = useAuth()
  const first = SETTINGS_PAGES.find((p) => canAny(p.permissions))
  return first ? <Navigate to={first.to} replace /> : null
}
