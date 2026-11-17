/** Pages of the Purchasing sidebar group and who may open them (UI gate; the API enforces). */
export const PURCHASING_SECTIONS = [
  { to: '/purchasing/orders', label: 'Purchase orders', permissions: ['po.view', 'po.create', 'po.approve', 'po.receive'], end: false },
  { to: '/purchasing/receipts', label: 'Goods received', permissions: ['po.view', 'po.receive'], end: false },
  { to: '/purchasing/suppliers', label: 'Suppliers', permissions: ['suppliers.view', 'suppliers.manage'], end: false },
  { to: '/purchasing/bills', label: 'Supplier bills', permissions: ['supplier_payments.manage', 'reports.financial'], end: false },
  { to: '/purchasing/payments', label: 'Supplier payments', permissions: ['supplier_payments.manage', 'reports.financial'], end: true },
] as const
