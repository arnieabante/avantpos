/** Pages of the Inventory sidebar group and who may open them (UI gate; the API enforces). */
export const INVENTORY_SECTIONS = [
  { to: '/inventory', label: 'Stock levels', permissions: ['inventory.view'], end: true },
  { to: '/inventory/movements', label: 'Movements', permissions: ['inventory.view'], end: false },
  { to: '/inventory/adjustments', label: 'Adjustments', permissions: ['inventory.view', 'inventory.adjust'], end: false },
  {
    to: '/inventory/transfers',
    label: 'Transfers',
    permissions: ['inventory.view', 'inventory.transfer', 'inventory.receive_transfer'],
    end: false,
    feature: 'stock_transfers',
  },
  { to: '/inventory/counts', label: 'Stock counts', permissions: ['inventory.view', 'inventory.count'], end: false },
  { to: '/inventory/valuation', label: 'Valuation', permissions: ['inventory.valuation'], end: false },
] as const
