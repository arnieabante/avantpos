import { NavLink, Outlet, useNavigate } from 'react-router'
import { Building2, CreditCard, Layers, LayoutDashboard, LifeBuoy, LogOut, ScrollText, Settings } from 'lucide-react'
import { AppFooter } from '@/components/layout/AppFooter'
import { Button } from '@/components/ui'
import { Logo } from '@/features/auth/AuthLayout'
import { cn } from '@/lib/utils'
import { useAdminAuth } from './useAdminAuth'

/** Platform-admin shell. */
const adminNav = [
  { label: 'Overview', to: '/admin', icon: LayoutDashboard, end: true },
  { label: 'Businesses', to: '/admin/tenants', icon: Building2, end: false },
  { label: 'Billing', to: '/admin/billing', icon: CreditCard, end: false },
  { label: 'Plans', to: '/admin/plans', icon: Layers, end: false },
  { label: 'Support', to: '/admin/tickets', icon: LifeBuoy, end: false },
  { label: 'Activity', to: '/admin/activity', icon: ScrollText, end: false },
  { label: 'Settings', to: '/admin/settings', icon: Settings, end: false },
]

export function AdminLayout() {
  const { admin, signOut } = useAdminAuth()
  const navigate = useNavigate()

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="sticky top-0 z-30 border-b border-slate-800 bg-slate-900 text-white">
        <div className="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
          <Logo inverted />
          <span className="hidden rounded bg-white/10 px-2 py-0.5 text-xs font-medium tracking-wide uppercase sm:inline">Platform admin</span>
          <nav aria-label="Admin" className="flex flex-1 gap-1 overflow-x-auto">
            {adminNav.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  cn('flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium', isActive ? 'bg-white/10 text-white' : 'text-line hover:text-white')
                }
              >
                <item.icon className="size-4" aria-hidden="true" />
                {item.label}
              </NavLink>
            ))}
          </nav>
          <span className="hidden truncate text-sm text-line md:block">{admin?.email}</span>
          <Button
            variant="ghost"
            size="sm"
            className="text-slate-200 hover:bg-white/10 hover:text-white"
            leftIcon={<LogOut className="size-4" />}
            onClick={async () => {
              await signOut()
              navigate('/admin/login', { replace: true })
            }}
          >
            Sign out
          </Button>
        </div>
      </header>
      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
        <Outlet />
      </main>
      <AppFooter className="border-t border-line" />
    </div>
  )
}
