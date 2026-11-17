import type { LucideIcon } from 'lucide-react'
import { BarChart3, FileBarChart, HandCoins, House, LifeBuoy, MonitorSmartphone, Package, Receipt, Settings, ShoppingCart, UserRound, Users, Wallet, Warehouse } from 'lucide-react'
import { REPORT_PERMISSIONS } from '@/features/reports/permissions'
import { CATALOG_SECTIONS } from '@/features/catalog/catalogSections'
import { CUSTOMER_SECTIONS } from '@/features/customers/customersSections'
import { INVENTORY_SECTIONS } from '@/features/inventory/inventorySections'
import { PURCHASING_SECTIONS } from '@/features/purchasing/purchasingSections'
import { SETTINGS_PAGES } from '@/features/settings/settingsPages'
import { USERS_SECTIONS } from '@/features/users/usersSections'

export interface NavChild {
  label: string
  to: string
  /** Shown when the user has ANY of these permissions (omit = everyone). */
  permissions?: string[]
  /** Plan feature key required, e.g. 'stock_transfers'. */
  feature?: string
  end?: boolean
}

export interface NavItem {
  label: string
  to: string
  icon: LucideIcon
  /** Shown when the user has ANY of these permissions (omit = everyone). */
  permissions?: string[]
  /** Plan feature key required (see config/avantpos/plans.php), e.g. 'purchasing'. */
  feature?: string
  /** Match the path exactly (for "/"). */
  end?: boolean
  /** Sub-pages: the item becomes an expandable group. */
  children?: NavChild[]
}

export interface NavSection {
  title?: string
  items: NavItem[]
}

/**
 * Sidebar navigation. Each module adds its entry here when its pages exist
 * (Phase 4: products, inventory, suppliers, customers; Phase 5: POS, sales,
 * cash register; Phase 6: reports, billing).
 *
 * Hiding an item is a convenience only — the API enforces permissions and plan features.
 */
export const navigation: NavSection[] = [
  {
    items: [{ label: 'Dashboard', to: '/', icon: House, end: true }],
  },
  {
    title: 'Catalog',
    items: [
      {
        label: 'Products',
        to: '/products',
        icon: Package,
        children: CATALOG_SECTIONS.map((s) => ({ label: s.label, to: s.to, permissions: [...s.permissions], end: s.end })),
      },
      {
        label: 'Inventory',
        to: '/inventory',
        icon: Warehouse,
        feature: 'inventory',
        children: INVENTORY_SECTIONS.map((s) => ({
          label: s.label,
          to: s.to,
          permissions: [...s.permissions],
          end: s.end,
          feature: 'feature' in s ? s.feature : undefined,
        })),
      },
      {
        label: 'Purchasing',
        to: '/purchasing',
        icon: ShoppingCart,
        feature: 'purchasing',
        children: PURCHASING_SECTIONS.map((s) => ({ label: s.label, to: s.to, permissions: [...s.permissions], end: s.end })),
      },
    ],
  },
  {
    title: 'Sales',
    items: [
      { label: 'POS', to: '/pos', icon: MonitorSmartphone, permissions: ['pos.sell', 'cash.open_session'], feature: 'pos' },
      { label: 'Expenses', to: '/expenses', icon: HandCoins, permissions: ['expenses.view', 'expenses.create', 'expenses.approve'], feature: 'expenses' },
      { label: 'Reports', to: '/reports', icon: BarChart3, permissions: [...REPORT_PERMISSIONS] },
      { label: 'Sales history', to: '/sales', icon: Receipt, permissions: ['sales.view_own', 'sales.view_all'], feature: 'pos' },
      { label: 'Cash shifts', to: '/cash-sessions', icon: Wallet, permissions: ['cash.open_session', 'cash.view_all_sessions'], feature: 'pos' },
      { label: 'X / Z readings', to: '/readings', icon: FileBarChart, permissions: ['cash.z_reading', 'cash.view_all_sessions'], feature: 'pos' },
      {
        label: 'Customers',
        to: '/customers',
        icon: UserRound,
        feature: 'customers',
        children: CUSTOMER_SECTIONS.map((s) => ({ label: s.label, to: s.to, permissions: [...s.permissions], end: s.end })),
      },
    ],
  },
  {
    title: 'Administration',
    items: [
      {
        label: 'Users & roles',
        to: '/users',
        icon: Users,
        children: USERS_SECTIONS.map((s) => ({ label: s.label, to: s.to, permissions: [...s.permissions], end: s.end })),
      },
      {
        label: 'Settings',
        to: '/settings',
        icon: Settings,
        children: SETTINGS_PAGES.map((p) => ({ label: p.label, to: `/settings/${p.to}`, permissions: p.permissions })),
      },
      { label: 'Help & support', to: '/support', icon: LifeBuoy },
    ],
  },
]

/**
 * Items (and sub-items) the user may see. A group is shown when at least one
 * of its children is visible; plain items use their own permissions.
 */
export function visibleNavigation(
  sections: NavSection[],
  canAny: (permissions: string[]) => boolean,
  features: Record<string, boolean> | null | undefined,
): NavSection[] {
  const allowed = (permissions?: string[]) => !permissions || canAny(permissions)

  return sections
    .map((section) => ({
      ...section,
      items: section.items
        .filter((item) => allowed(item.permissions) && (!item.feature || Boolean(features?.[item.feature])))
        .map((item) =>
          item.children
            ? { ...item, children: item.children.filter((child) => allowed(child.permissions) && (!child.feature || Boolean(features?.[child.feature]))) }
            : item,
        )
        .filter((item) => !item.children || item.children.length > 0),
    }))
    .filter((section) => section.items.length > 0)
}
