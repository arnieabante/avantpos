export type ColumnType = 'text' | 'date' | 'int' | 'qty' | 'money' | 'percent'

export interface ReportColumn {
  key: string
  label: string
  type: ColumnType
}

export type ReportRow = Record<string, string | number | null>

export interface ReportData {
  key: string
  title: string
  columns: ReportColumn[]
  rows: ReportRow[]
  totals: ReportRow | null
  notes: string[]
  filters: { from: string; to: string; branches: Array<{ id: number; name: string }>; group_by: string; timezone: string }
}

export interface ReportFilters {
  from: string
  to: string
  branch_id: number | 'all'
  group_by?: 'day' | 'week' | 'month' | 'year'
  sort?: 'net_sales' | 'qty'
  group?: 'none' | 'category'
}

export interface ReportAccess {
  key: string
  allowed: boolean
  feature: string | null
  available: boolean
}

export interface Kpis {
  transactions: number
  gross_sales: string
  discounts: string
  sc_pwd_vat: string
  returns: string
  net_sales: string
  vat: string
  revenue: string
  cogs?: string
  gross_profit?: string
  margin?: string | null
  average_sale: string
  expenses?: string
  operating_profit?: string
  cash_collected: string
}

export interface Dashboard {
  today: Kpis
  yesterday: Kpis
  month: Kpis
  series: Array<{ date: string; net_sales: string; transactions: number }>
  top_products: ReportRow[] | null
  payments_today: ReportRow[]
  low_stock: number
  open_shifts: number
  branches: Array<{ id: number; name: string }>
  can_see_costs: boolean
}
