import { api, http } from '@/lib/api/client'
import type { Dashboard, ReportAccess, ReportData, ReportFilters } from './types'

const V1 = '/api/v1'

const params = (f: Partial<ReportFilters>) => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== ''))

export const reportKeys = {
  all: ['reports'] as const,
  access: ['reports', 'access'] as const,
  report: (key: string, f: ReportFilters) => ['reports', key, f] as const,
  dashboard: (branch: number | 'all') => ['reports', 'dashboard', branch] as const,
}

export const reportsApi = {
  access: async () => {
    const res = await http.get<{ data: ReportAccess[]; meta: { can_export: boolean; can_see_costs: boolean } }>(`${V1}/reports`)
    return { reports: res.data.data, ...res.data.meta }
  },
  report: (key: string, f: ReportFilters) => api.get<ReportData>(`${V1}/reports/${key}`, { params: params(f) }),
  dashboard: (branch: number | 'all') => api.get<Dashboard>(`${V1}/reports/dashboard`, { params: { branch_id: branch } }),
  /** Download the report as CSV or Excel (saved by the browser). */
  async download(key: string, f: ReportFilters, format: 'csv' | 'xlsx'): Promise<void> {
    const res = await http.get<Blob>(`${V1}/reports/${key}`, { params: { ...params(f), format }, responseType: 'blob' })
    const disposition = String(res.headers['content-disposition'] ?? '')
    const name = /filename="([^"]+)"/.exec(disposition)?.[1] ?? `${key}.${format}`
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    a.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}
