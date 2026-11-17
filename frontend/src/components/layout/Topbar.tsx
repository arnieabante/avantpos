import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Building2, Check, ChevronDown, LogOut, Menu, UserCog } from 'lucide-react'
import { Dropdown, IconButton } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { initials } from '@/lib/format'

export function Topbar({ onOpenSidebar }: { onOpenSidebar: () => void }) {
  return (
    // Floating bar: a white card detached from the page edges, over a blurred backdrop.
    <header className="sticky top-0 z-30 shrink-0 bg-gradient-to-b from-canvas via-canvas/80 to-transparent px-4 pt-3 pb-2 sm:px-6">
      <div className="flex h-[3.875rem] items-center gap-3 rounded-lg bg-surface/95 px-4 shadow-card backdrop-blur sm:px-6">
        <IconButton label="Open menu" onClick={onOpenSidebar} className="-ml-2 lg:hidden">
          <Menu className="size-5" />
        </IconButton>
        <div className="flex min-w-0 flex-1 items-center">
          <BranchSwitcher />
        </div>
        <UserMenu />
      </div>
    </header>
  )
}

function BranchSwitcher() {
  const account = useAccount()
  const { activeBranch, setActiveBranch } = useAuth()

  if (!activeBranch) {
    return <span className="text-sm text-ink-subtle">No branch assigned</span>
  }

  const label = (
    <span className="flex min-w-0 items-center gap-2">
      <Building2 className="size-5 shrink-0 text-ink-subtle" aria-hidden="true" />
      <span className="truncate text-ink">{activeBranch.name}</span>
    </span>
  )

  if (account.branches.length <= 1) {
    return <div className="flex min-w-0 items-center text-sm">{label}</div>
  }

  return (
    <Dropdown
      align="left"
      header={<p className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">Switch branch</p>}
      items={account.branches.map((branch) => ({
        key: String(branch.id),
        label: branch.name,
        description: branch.is_main ? `${branch.code} · Main branch` : branch.code,
        active: branch.id === activeBranch.id,
        icon: branch.id === activeBranch.id ? <Check className="size-4" /> : <span className="block size-4" />,
        onSelect: () => setActiveBranch(branch.id),
      }))}
      trigger={(props) => (
        <button type="button" {...props} className="flex max-w-xs min-w-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-[0.9375rem] hover:bg-brand-500/10">
          {label}
          <ChevronDown className="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
          <span className="sr-only">Change branch</span>
        </button>
      )}
    />
  )
}

function UserMenu() {
  const account = useAccount()
  const { signOut } = useAuth()
  const navigate = useNavigate()
  const [signingOut, setSigningOut] = useState(false)

  async function handleSignOut() {
    setSigningOut(true)
    await signOut()
    navigate('/login', { replace: true })
  }

  return (
    <Dropdown
      header={
        <div className="flex min-w-0 items-center gap-3">
          <Avatar name={account.user.name} />
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-ink">{account.user.name}</p>
            <p className="truncate text-xs text-ink-subtle">{account.roles[0] ?? account.user.email}</p>
          </div>
        </div>
      }
      items={[
        { key: 'account', label: 'My account', icon: <UserCog className="size-4" />, onSelect: () => navigate('/account') },
        { key: 'logout', label: signingOut ? 'Signing out…' : 'Sign out', icon: <LogOut className="size-4" />, danger: true, disabled: signingOut, onSelect: handleSignOut },
      ]}
      trigger={(props) => (
        <button type="button" {...props} className="rounded-full focus-visible:outline-offset-4">
          <Avatar name={account.user.name} />
          <span className="sr-only">Open user menu</span>
        </button>
      )}
    />
  )
}

/** Initials avatar with a green "online" dot. */
function Avatar({ name }: { name: string }) {
  return (
    <span className="relative inline-flex shrink-0">
      <span className="flex size-10 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-500" aria-hidden="true">
        {initials(name)}
      </span>
      <span className="absolute right-0 bottom-0 size-2.5 rounded-full bg-[#71dd37] ring-2 ring-white" aria-hidden="true" />
    </span>
  )
}
