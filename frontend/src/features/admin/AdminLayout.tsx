import { useState, type ComponentType } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import {
  Building2,
  ChevronDown,
  CreditCard,
  Layers,
  LayoutDashboard,
  LifeBuoy,
  LogOut,
  Menu,
  ScrollText,
  Settings,
  ShieldCheck,
  UserCog,
  X,
} from 'lucide-react'
import { AppFooter } from '@/components/layout/AppFooter'
import { Dropdown } from '@/components/ui'
import { Logo } from '@/features/auth/AuthLayout'
import { cn } from '@/lib/utils'
import { useAdminAuth } from './useAdminAuth'

type Icon = ComponentType<{ className?: string }>
interface NavLinkItem {
  label: string
  to: string
  icon: Icon
  description?: string
  end?: boolean
  superOnly?: boolean
}
interface NavGroup {
  label: string
  icon: Icon
  children: NavLinkItem[]
}
type NavEntry = NavLinkItem | NavGroup

/** Platform-admin navigation: direct links, and groups shown as dropdown menus. */
const adminNav: NavEntry[] = [
  { label: 'Overview', to: '/admin', icon: LayoutDashboard, end: true },
  { label: 'Businesses', to: '/admin/tenants', icon: Building2 },
  {
    label: 'Billing',
    icon: CreditCard,
    children: [
      { label: 'Payments & invoices', to: '/admin/billing', icon: CreditCard, description: 'Verify bank transfers, follow invoices' },
      { label: 'Plans', to: '/admin/plans', icon: Layers, description: 'Prices, limits and features' },
    ],
  },
  {
    label: 'Support',
    icon: LifeBuoy,
    children: [
      { label: 'Tickets', to: '/admin/tickets', icon: LifeBuoy, description: 'Questions from businesses' },
      { label: 'Activity log', to: '/admin/activity', icon: ScrollText, description: 'Who did what, and when' },
    ],
  },
  {
    label: 'Settings',
    icon: Settings,
    children: [
      { label: 'Platform settings', to: '/admin/settings', icon: Settings, description: 'Bank details, support contacts, sign-ups' },
      { label: 'Admin team', to: '/admin/team', icon: UserCog, description: 'AvantPOS staff and their roles', superOnly: true },
    ],
  },
]

const isGroup = (entry: NavEntry): entry is NavGroup => 'children' in entry
const matches = (item: NavLinkItem, pathname: string) => (item.end ? pathname === item.to : pathname === item.to || pathname.startsWith(`${item.to}/`))

/** Platform-admin shell: top bar with dropdown menus on desktop, a menu panel on small screens. */
export function AdminLayout() {
  const { admin, signOut } = useAdminAuth()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  // The mobile panel remembers the page it was opened on, so navigating closes it.
  const [panelOpenedAt, setPanelOpenedAt] = useState<string | null>(null)
  const panelOpen = panelOpenedAt === pathname

  const allowed = (item: NavLinkItem) => !item.superOnly || admin?.role === 'super_admin'
  const nav = adminNav
    .map((entry) => (isGroup(entry) ? { ...entry, children: entry.children.filter(allowed) } : entry))
    .filter((entry) => (isGroup(entry) ? entry.children.length > 0 : allowed(entry)))

  const leave = async () => {
    await signOut()
    navigate('/admin/login', { replace: true })
  }
  const topLink = (active: boolean) =>
    cn('flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors', active ? 'bg-white/10 text-white' : 'text-line hover:bg-white/5 hover:text-white')

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="sticky top-0 z-30 border-b border-slate-800 bg-slate-900 text-white print:hidden">
        <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
          <NavLink to="/admin" className="shrink-0" aria-label="Admin overview">
            <Logo inverted className="h-8" />
          </NavLink>
          <span className="hidden rounded bg-white/10 px-2 py-0.5 text-xs font-medium tracking-wide uppercase xl:inline">Platform admin</span>

          {/* Desktop menu */}
          <nav aria-label="Admin" className="ml-2 hidden flex-1 items-center gap-1 lg:flex">
            {nav.map((entry) =>
              isGroup(entry) ? (
                <Dropdown
                  key={entry.label}
                  align="left"
                  items={entry.children.map((child) => ({
                    key: child.to,
                    label: child.label,
                    description: child.description,
                    icon: <child.icon className="size-4" />,
                    active: matches(child, pathname),
                    onSelect: () => navigate(child.to),
                  }))}
                  trigger={(props) => (
                    <button type="button" {...props} className={topLink(entry.children.some((c) => matches(c, pathname)))}>
                      <entry.icon className="size-4" aria-hidden="true" />
                      {entry.label}
                      <ChevronDown className={cn('size-3.5 opacity-70 transition-transform', props['aria-expanded'] && 'rotate-180')} aria-hidden="true" />
                    </button>
                  )}
                />
              ) : (
                <NavLink key={entry.to} to={entry.to} end={entry.end} className={({ isActive }) => topLink(isActive)}>
                  <entry.icon className="size-4" aria-hidden="true" />
                  {entry.label}
                </NavLink>
              ),
            )}
          </nav>

          <div className="ml-auto flex items-center gap-2">
            <Dropdown
              header={
                <div>
                  <p className="truncate font-medium text-ink">{admin?.name}</p>
                  <p className="truncate text-xs text-ink-subtle">{admin?.email}</p>
                  <p className="mt-1 text-xs text-ink-subtle">{admin?.role === 'super_admin' ? 'Super admin' : admin?.role === 'billing' ? 'Billing' : 'Support'}</p>
                </div>
              }
              items={[
                { key: '2fa', label: admin?.two_factor ? 'Two-factor sign-in: on' : 'Turn on two-factor sign-in', icon: <ShieldCheck className="size-4" />, onSelect: () => navigate('/admin/settings') },
                { key: 'out', label: 'Sign out', icon: <LogOut className="size-4" />, danger: true, onSelect: () => void leave() },
              ]}
              trigger={(props) => (
                <button type="button" {...props} className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-line hover:bg-white/5 hover:text-white" aria-label="Account menu">
                  <span className="flex size-8 items-center justify-center rounded-full bg-brand-500 text-xs font-semibold text-white" aria-hidden="true">
                    {(admin?.name ?? admin?.email ?? '?').slice(0, 1).toUpperCase()}
                  </span>
                  <span className="hidden max-w-48 truncate md:inline">{admin?.email}</span>
                  <ChevronDown className="size-3.5 opacity-70" aria-hidden="true" />
                </button>
              )}
            />
            <button
              type="button"
              className="rounded-lg p-2 text-line hover:bg-white/5 hover:text-white lg:hidden"
              aria-label={panelOpen ? 'Close menu' : 'Open menu'}
              aria-expanded={panelOpen}
              onClick={() => setPanelOpenedAt(panelOpen ? null : pathname)}
            >
              {panelOpen ? <X className="size-5" /> : <Menu className="size-5" />}
            </button>
          </div>
        </div>

        {/* Small screens: every page, grouped. */}
        {panelOpen && (
          <nav aria-label="Admin" className="border-t border-slate-800 px-4 pb-4 sm:px-6 lg:hidden">
            {nav.map((entry) =>
              isGroup(entry) ? (
                <div key={entry.label} className="mt-3">
                  <p className="px-3 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase">{entry.label}</p>
                  {entry.children.map((child) => (
                    <NavLink key={child.to} to={child.to} className={({ isActive }) => cn(topLink(isActive), 'w-full')}>
                      <child.icon className="size-4" aria-hidden="true" />
                      {child.label}
                    </NavLink>
                  ))}
                </div>
              ) : (
                <NavLink key={entry.to} to={entry.to} end={entry.end} className={({ isActive }) => cn(topLink(isActive), 'mt-1 w-full')}>
                  <entry.icon className="size-4" aria-hidden="true" />
                  {entry.label}
                </NavLink>
              ),
            )}
          </nav>
        )}
      </header>
      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
        <Outlet />
      </main>
      <AppFooter className="border-t border-line" />
    </div>
  )
}
