/** The reports, grouped as they appear on the Reports page. */
export interface ReportDef {
  key: string
  title: string
  description: string
  group: 'Sales' | 'Products' | 'Inventory' | 'Purchasing & expenses'
  /** The date range does not apply (current stock). */
  current?: boolean
  groupBy?: boolean
}

export const REPORTS: ReportDef[] = [
  { key: 'sales', title: 'Sales', description: 'Daily, weekly, monthly or annual sales: net sales, VAT, cost and gross profit.', group: 'Sales', groupBy: true },
  { key: 'payments', title: 'Payment methods', description: 'Cash, e-wallets, cards and account payments: received, refunded, collected.', group: 'Sales' },
  { key: 'discounts', title: 'Discounts', description: 'Senior/PWD, manual, preset, customer group and points discounts.', group: 'Sales' },
  { key: 'tax', title: 'VAT and tax', description: 'VATable, VAT-exempt and zero-rated sales and output VAT per day.', group: 'Sales' },
  { key: 'sc-pwd', title: 'Senior / PWD sales book', description: 'Every Senior Citizen and PWD discount with the beneficiary details.', group: 'Sales' },
  { key: 'branches', title: 'Sales by branch', description: 'Compare branches: sales, returns, profit.', group: 'Sales' },
  { key: 'cashiers', title: 'Cashier performance', description: 'Sales, average sale, discounts, voids and refunds per cashier.', group: 'Sales' },
  { key: 'products', title: 'Sales by product', description: 'Quantity, net sales and profit per product, net of returns.', group: 'Products' },
  { key: 'categories', title: 'Sales by category', description: 'Net sales and profit per product category.', group: 'Products' },
  { key: 'fast-moving', title: 'Fast-moving products', description: 'The products that sold the most units.', group: 'Products' },
  { key: 'slow-moving', title: 'Slow-moving products', description: 'Items in stock that sold the least — candidates for promos.', group: 'Products' },
  { key: 'inventory-valuation', title: 'Inventory valuation', description: 'Stock on hand at FIFO cost, per branch and item.', group: 'Inventory', current: true },
  { key: 'low-stock', title: 'Low stock', description: 'Items at or below their reorder level, with a suggested order.', group: 'Inventory', current: true },
  { key: 'stock-movements', title: 'Stock movements', description: 'Every stock in and out: sales, receipts, transfers, adjustments.', group: 'Inventory' },
  { key: 'payables', title: 'Supplier balances (aging)', description: 'What you owe each supplier, by how many days overdue.', group: 'Purchasing & expenses', current: true },
  { key: 'purchases', title: 'Purchase history', description: 'Goods received from suppliers, with input VAT.', group: 'Purchasing & expenses' },
  { key: 'expenses', title: 'Expenses', description: 'Approved and paid expenses, by date or by category.', group: 'Purchasing & expenses' },
]

export const reportDef = (key: string) => REPORTS.find((r) => r.key === key)
