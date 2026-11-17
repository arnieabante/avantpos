import { Building, Building2, CreditCard, Gift, Monitor, Percent, Receipt, Sparkles, Tag, type LucideIcon } from 'lucide-react'

export interface SettingsPage {
  to: string
  label: string
  description: string
  icon: LucideIcon
  /** Any of these permissions shows the page (the API enforces the details). */
  permissions: string[]
}

export const SETTINGS_PAGES: SettingsPage[] = [
  { to: 'business', label: 'Business profile', description: 'Name, BIR registration, address, logo', icon: Building, permissions: ['business.view', 'business.manage'] },
  { to: 'branches', label: 'Branches', description: 'Stores and their opening hours', icon: Building2, permissions: ['branches.view', 'branches.manage'] },
  { to: 'terminals', label: 'POS terminals', description: 'Devices, BIR machine info, cash drawers', icon: Monitor, permissions: ['terminals.manage'] },
  { to: 'taxes', label: 'Taxes', description: 'VAT and percentage tax rates', icon: Percent, permissions: ['settings.tax', 'business.view'] },
  { to: 'payment-methods', label: 'Payment methods', description: 'Cash, GCash, Maya, bank, cards', icon: CreditCard, permissions: ['settings.payment_methods', 'business.view'] },
  { to: 'discounts', label: 'Discounts', description: 'Senior/PWD and store discounts', icon: Tag, permissions: ['settings.discounts', 'business.view'] },
  { to: 'loyalty', label: 'Loyalty points', description: 'How customers earn and redeem points', icon: Gift, permissions: ['settings.discounts', 'business.view'] },
  { to: 'receipts', label: 'Receipts', description: 'Receipt and invoice layouts', icon: Receipt, permissions: ['settings.receipt', 'business.view'] },
  { to: 'billing', label: 'Subscription', description: 'Your AvantPOS plan, invoices and payments', icon: Sparkles, permissions: ['billing.view', 'billing.manage'] },
]

/** All permissions that give access to at least one settings page (used by the sidebar). */
export const SETTINGS_PERMISSIONS = [...new Set(SETTINGS_PAGES.flatMap((p) => p.permissions))]
