/** Pages of the Customers sidebar group (UI gate; the API enforces). */
export const CUSTOMER_SECTIONS = [
  { to: '/customers', label: 'All customers', permissions: ['customers.view', 'customers.manage', 'customers.credit_manage'], end: true },
  { to: '/customers/groups', label: 'Groups', permissions: ['customers.manage'], end: false },
] as const
