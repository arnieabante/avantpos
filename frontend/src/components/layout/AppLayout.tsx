import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router'
import { X } from 'lucide-react'
import { IconButton } from '@/components/ui'
import { EmailVerificationBanner } from '@/features/auth/EmailVerificationBanner'
import { SubscriptionBanner } from '@/features/billing/components/SubscriptionBanner'
import { AppFooter } from './AppFooter'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

/** Shell for signed-in tenant pages: fixed sidebar on desktop, drawer on mobile/tablet. */
export function AppLayout() {
  // Remember on which page the drawer was opened: navigating elsewhere closes it
  // (derived during render instead of a setState-in-effect).
  const [drawerOpenedAt, setDrawerOpenedAt] = useState<string | null>(null)
  const { pathname } = useLocation()
  const drawerOpen = drawerOpenedAt === pathname
  const setDrawerOpen = (open: boolean) => setDrawerOpenedAt(open ? pathname : null)

  useEffect(() => {
    if (!drawerOpen) return
    const onKeyDown = (event: KeyboardEvent) => event.key === 'Escape' && setDrawerOpenedAt(null)
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [drawerOpen])

  return (
    <div className="min-h-dvh">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] focus:rounded-lg focus:bg-surface focus:px-4 focus:py-2 focus:shadow">
        Skip to content
      </a>

      {/* Desktop sidebar */}
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-[16.25rem] lg:block print:hidden">
        <Sidebar />
      </aside>

      {/* Mobile drawer */}
      {drawerOpen && (
        <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="absolute inset-0 animate-fade-in bg-[#22303e]/50" onClick={() => setDrawerOpen(false)} aria-hidden="true" />
          <aside className="relative h-full w-[16.25rem] max-w-[85vw] animate-slide-up shadow-raised">
            <Sidebar onNavigate={() => setDrawerOpen(false)} />
            <IconButton label="Close menu" onClick={() => setDrawerOpen(false)} className="absolute top-3 right-3">
              <X className="size-5" />
            </IconButton>
          </aside>
        </div>
      )}

      <div className="flex min-h-dvh flex-col lg:pl-[16.25rem] print:pl-0">
        <div className="contents print:hidden">
          <Topbar onOpenSidebar={() => setDrawerOpen(true)} />
        </div>
        <main id="main" className="mx-auto w-full max-w-[1440px] min-w-0 flex-1 px-4 pt-4 pb-8 sm:px-6">
          <EmailVerificationBanner />
          <SubscriptionBanner />
          <Outlet />
        </main>
        <AppFooter className="border-t border-line" />
      </div>
    </div>
  )
}
