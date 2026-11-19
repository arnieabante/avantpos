export const TENANT_TONE: Record<string, 'success' | 'brand' | 'warning' | 'danger' | 'neutral'> = {
  active: 'success',
  trial: 'brand',
  past_due: 'warning',
  suspended: 'danger',
  cancelled: 'neutral',
}

export const ADMIN_FEATURE_LABELS: Record<string, string> = {
  pos: 'Point of sale',
  inventory: 'Inventory',
  customers: 'Customers',
  receipts_thermal: 'Thermal receipts',
  offline_pos: 'Offline selling',
  multi_branch: 'Multiple branches',
  stock_transfers: 'Stock transfers',
  purchasing: 'Purchasing & payables',
  expenses: 'Expenses',
  loyalty: 'Loyalty points',
  credit_sales: 'Customer credit',
  batch_expiry: 'Batches & expiry',
  unit_conversions: 'Unit conversions',
  advanced_reports: 'Advanced reports',
  report_exports: 'Report exports',
  custom_roles: 'Custom roles',
  custom_subdomain: 'Custom subdomain',
  audit_logs: 'Audit logs',
  api_access: 'API access',
  priority_support: 'Priority support',
}

export const ADMIN_ROLES: Record<'super_admin' | 'billing' | 'support', { label: string; tone: 'brand' | 'warning' | 'neutral'; description: string }> = {
  super_admin: { label: 'Super admin', tone: 'brand', description: 'Everything, including businesses, settings and this admin team.' },
  billing: { label: 'Billing', tone: 'warning', description: 'Plans, payments and business subscriptions (including enabling access).' },
  support: { label: 'Support', tone: 'neutral', description: 'Support tickets and the activity log; can view businesses.' },
}
