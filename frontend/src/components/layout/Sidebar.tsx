import { useState } from 'react'
import { NavLink, useLocation } from 'react-router'
import { ChevronDown, ChevronRight, Sparkles } from 'lucide-react'
import { Logo } from '@/features/auth/AuthLayout'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { daysUntil } from '@/lib/format'
import { cn } from '@/lib/utils'
import { navigation, visibleNavigation, type NavItem } from './navigation'

/** Active item: tinted pill + an accent bar on the sidebar's right edge. */
const itemBase = 'relative flex w-full items-center gap-3 rounded-md px-4 py-2.5 text-[0.9375rem] transition-colors'
const itemIdle = 'text-ink-muted hover:bg-[#f0f2f4] hover:text-ink'
const itemActive =
  'bg-brand-500/15 font-medium text-brand-500 after:absolute after:top-0 after:-right-4 after:h-full after:w-1 after:rounded-l after:bg-brand-500'

export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const account = useAccount()
  const { canAny } = useAuth()
  const sections = visibleNavigation(navigation, canAny, account.subscription?.plan.features)

  return (
    <div className="flex h-full flex-col bg-surface shadow-card">
      <div className="flex h-[4.5rem] shrink-0 items-center px-7">
        <Logo />
      </div>

      <div className="mx-4 mb-2 rounded-md bg-canvas px-4 py-2.5">
        <p className="truncate text-sm font-medium text-ink" title={account.tenant.name}>
          {account.tenant.name}
        </p>
        <p className="truncate text-xs text-ink-subtle">{account.roles.join(', ') || 'No role assigned'}</p>
      </div>

      <nav aria-label="Main" className="flex-1 overflow-x-hidden overflow-y-auto px-4 pt-2 pb-4">
        {sections.map((section, index) => (
          <div key={section.title ?? index}>
            {section.title && (
              <p className="mt-5 mb-2 flex items-center gap-3 text-[0.75rem] tracking-[0.06em] text-ink-subtle uppercase before:-ml-4 before:block before:h-px before:w-4 before:bg-line">
                {section.title}
              </p>
            )}
            <ul className="space-y-1">
              {section.items.map((item) => (
                <li key={item.to}>{item.children ? <NavGroup item={item} onNavigate={onNavigate} /> : <NavLeaf item={item} onNavigate={onNavigate} />}</li>
              ))}
            </ul>
          </div>
        ))}
      </nav>

      <PlanFooter />
    </div>
  )
}

function NavLeaf({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  return (
    <NavLink to={item.to} end={item.end} onClick={onNavigate} className={({ isActive }) => cn(itemBase, isActive ? itemActive : itemIdle)}>
      <item.icon className="size-5 shrink-0" aria-hidden="true" />
      {item.label}
    </NavLink>
  )
}

/** Expandable group with bulleted sub-pages; open while one of its pages is active. */
function NavGroup({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  const { pathname } = useLocation()
  const containsActive = pathname === item.to || pathname.startsWith(`${item.to}/`)
  const [toggled, setToggled] = useState<boolean | null>(null)
  const open = toggled ?? containsActive
  const listId = `nav-${item.to.replace(/\W/g, '')}`

  return (
    <>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => setToggled(!open)}
        className={cn(itemBase, containsActive ? itemActive : itemIdle)}
      >
        <item.icon className="size-5 shrink-0" aria-hidden="true" />
        <span className="flex-1 text-left">{item.label}</span>
        {open ? <ChevronDown className="size-4 shrink-0" aria-hidden="true" /> : <ChevronRight className="size-4 shrink-0" aria-hidden="true" />}
      </button>
      {open && (
        <ul id={listId} aria-label={`${item.label} pages`} className="mt-1 space-y-0.5">
          {item.children!.map((child) => (
            <li key={child.to}>
              <NavLink
                to={child.to}
                end={child.end}
                onClick={onNavigate}
                className={({ isActive }) =>
                  cn(
                    'group flex items-center gap-4 rounded-md py-2.5 pr-4 pl-6 text-[0.9375rem] transition-colors',
                    isActive ? 'font-medium text-ink' : 'text-ink-muted hover:bg-[#f0f2f4] hover:text-ink',
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    <span
                      aria-hidden="true"
                      className={cn(
                        'shrink-0 rounded-full transition-all',
                        isActive ? 'size-2 bg-brand-500 ring-4 ring-brand-500/15' : 'size-1.5 bg-ink-subtle/70',
                      )}
                    />
                    {child.label}
                  </>
                )}
              </NavLink>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function PlanFooter() {
  const { subscription } = useAccount()
  if (!subscription) return null

  const trialDays = subscription.status === 'trialing' ? daysUntil(subscription.trial_ends_at) : null

  return (
    <div className="p-4">
      <div className="rounded-md bg-brand-500/10 p-3.5">
        <p className="flex items-center gap-1.5 text-sm font-medium text-brand-500">
          <Sparkles className="size-4" aria-hidden="true" />
          {subscription.plan.name ?? 'Subscription'} plan
        </p>
        <p className="mt-0.5 text-xs text-ink-muted">
          {trialDays !== null
            ? trialDays > 0
              ? `Free trial · ${trialDays} day${trialDays === 1 ? '' : 's'} left`
              : 'Free trial ends today'
            : `Status: ${subscription.status.replace(/_/g, ' ')}`}
        </p>
      </div>
    </div>
  )
}
