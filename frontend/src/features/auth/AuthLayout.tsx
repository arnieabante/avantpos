import type { ReactNode } from 'react'
import { Link, Outlet } from 'react-router'
import logoColored from '@/assets/brand/avantpos-logo-colored.png'
import logoWhite from '@/assets/brand/colored-light-avantlogo.png'
import { AppFooter } from '@/components/layout/AppFooter'
import { cn } from '@/lib/utils'

/**
 * AvantPOS logo. `inverted` = the white version for coloured / dark backgrounds
 * (sign-in side panel, admin header); otherwise the coloured version for white backgrounds.
 * Source images are 2x (326×72) for sharp rendering at the default 36px height.
 */
export function Logo({ className, inverted = false }: { className?: string; inverted?: boolean }) {
  return (
    <img
      src={inverted ? logoWhite : logoColored}
      alt="AvantPOS"
      width={163}
      height={36}
      decoding="async"
      className={cn('h-9 w-auto max-w-full select-none', className)}
      draggable={false}
    />
  )
}

export function AuthCard({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('w-full max-w-md rounded-lg bg-surface p-6 shadow-card sm:p-10', className)}>{children}</div>
}

/** Title block used at the top of every auth form. */
export function AuthHeading({ title, description }: { title: ReactNode; description?: ReactNode }) {
  return (
    <div className="mb-6">
      <h1 className="text-[1.375rem] font-medium text-ink">{title}</h1>
      {description && <p className="mt-1.5 text-sm text-ink-muted">{description}</p>}
    </div>
  )
}

/** Public pages (login, register, password reset...): branded panel + centered card. */
export function AuthLayout() {
  return (
    <div className="flex min-h-dvh">
      <aside className="relative hidden w-[42%] max-w-xl flex-col justify-between overflow-hidden bg-brand-500 p-10 text-white lg:flex">
        <div className="absolute -top-24 -right-24 size-80 rounded-full bg-white/10" aria-hidden="true" />
        <div className="absolute -bottom-32 -left-16 size-96 rounded-full bg-brand-700/40" aria-hidden="true" />
        <Link to="/" className="relative">
          <Logo inverted />
        </Link>
        <div className="relative">
          <p className="text-3xl leading-tight font-semibold">Sell faster. Track every peso. Grow your branches.</p>
          <p className="mt-4 text-brand-100">
            Point of sale, inventory and reports for Philippine businesses — from the sari-sari store to the multi-branch hardware.
          </p>
        </div>
        <span aria-hidden="true" />
      </aside>

      <div className="flex flex-1 flex-col">
        <main className="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
          <Link to="/" className="mb-8 lg:hidden">
            <Logo />
          </Link>
          <Outlet />
        </main>
        <AppFooter />
      </div>
    </div>
  )
}
