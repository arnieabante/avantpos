import { Navigate, Outlet, useLocation } from 'react-router'
import { PageHeader } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { USERS_SECTIONS } from './usersSections'

const DESCRIPTIONS: Record<string, string> = {
  '/users': 'Everyone who can sign in to your business, their role and branches.',
  '/users/invitations': 'Invite staff by e-mail; they set their own password.',
  '/users/roles': 'A role is a set of permissions. Each user has one role.',
}

/**
 * "Users & roles". Navigation between Team / Invitations / Roles lives in the
 * sidebar group; this layout shows the section header.
 */
export function UsersLayout() {
  const { canAny } = useAuth()
  const { pathname } = useLocation()
  const sections = USERS_SECTIONS.filter((s) => canAny([...s.permissions]))

  // Someone who may only invite lands on the Invitations section.
  if (pathname === '/users' && sections.length > 0 && !sections.some((s) => s.to === '/users')) {
    return <Navigate to={sections[0]!.to} replace />
  }

  const current = [...USERS_SECTIONS].reverse().find((s) => pathname === s.to || pathname.startsWith(`${s.to}/`))

  return (
    <>
      <PageHeader
        title={current && current.to !== '/users' ? current.label : 'Team'}
        description={DESCRIPTIONS[current?.to ?? '/users']}
      />
      <Outlet />
    </>
  )
}
