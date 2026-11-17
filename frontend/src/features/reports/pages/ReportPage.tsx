import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft, FileSpreadsheet, FileText, Printer } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, ButtonLink, Card, FilterBar, Input, PageHeader, Select, Spinner, useToast } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime } from '@/lib/format'
import { reportKeys, reportsApi } from '../api'
import { reportDef } from '../catalog'
import { BarChart } from '../components/BarChart'
import { ReportTable } from '../components/ReportTable'
import { PRESETS, presetRange, type Preset } from '../dates'
import type { ReportFilters } from '../types'

/** /reports/:key — one report with date, branch and grouping filters; print and export. */
export default function ReportPage() {
  const key = useParams().reportKey ?? ''
  const def = reportDef(key)
  const { account, activeBranch } = useAuth()
  const toast = useToast()
  const branches = account?.branches ?? []
  const [preset, setPreset] = useState<Preset | 'custom'>('this_month')
  const [filters, setFilters] = useState<ReportFilters>(() => ({
    ...presetRange('this_month'),
    branch_id: branches.length > 1 ? 'all' : (activeBranch?.id ?? 'all'),
    ...(def?.groupBy ? { group_by: 'day' as const } : {}),
    ...(key === 'expenses' ? { group: 'none' as const } : {}),
  }))
  const [exporting, setExporting] = useState<'csv' | 'xlsx' | null>(null)
  const [openedAt] = useState(() => new Date().toISOString())
  const set = (patch: Partial<ReportFilters>) => setFilters((f) => ({ ...f, ...patch }))

  const access = useQuery({ queryKey: reportKeys.access, queryFn: reportsApi.access, staleTime: 60_000 })
  const report = useQuery({ queryKey: reportKeys.report(key, filters), queryFn: () => reportsApi.report(key, filters), placeholderData: keepPreviousData, enabled: !!def })

  const exportAs = async (format: 'csv' | 'xlsx') => {
    setExporting(format)
    try {
      await reportsApi.download(key, filters, format)
    } catch (e) {
      toast.error('Export failed', ApiError.from(e).message)
    }
    setExporting(null)
  }

  if (!def) return <ApiErrorAlert error={new ApiError('Report not found.', { status: 404, code: 'NOT_FOUND' })} />
  const data = report.data
  const chart =
    key === 'sales' && data && data.rows.length > 1 && data.rows.length <= 62
      ? data.rows.map((r) => ({ label: String(r.period), value: String(r.net_sales ?? '0'), hint: String(r.period) }))
      : null

  return (
    <div className="space-y-6">
      <ButtonLink to="/reports" variant="link" leftIcon={<ArrowLeft className="size-4" />} className="print:hidden">
        Reports
      </ButtonLink>
      <PageHeader
        title={def.title}
        description={def.description}
        actions={
          <div className="flex gap-2 print:hidden">
            <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={() => window.print()} disabled={!data}>
              Print
            </Button>
            {access.data?.can_export && (
              <>
                <Button variant="outline" leftIcon={<FileText className="size-4" />} loading={exporting === 'csv'} onClick={() => exportAs('csv')} disabled={!data}>
                  CSV
                </Button>
                <Button variant="outline" leftIcon={<FileSpreadsheet className="size-4" />} loading={exporting === 'xlsx'} onClick={() => exportAs('xlsx')} disabled={!data}>
                  Excel
                </Button>
              </>
            )}
          </div>
        }
      />

      <Card className="print:hidden">
        <FilterBar>
          {!def.current && (
            <>
              <Select
                aria-label="Period"
                className="sm:w-44"
                value={preset}
                onChange={(e) => {
                  const p = e.target.value as Preset | 'custom'
                  setPreset(p)
                  if (p !== 'custom') set(presetRange(p))
                }}
              >
                {PRESETS.map((p) => (
                  <option key={p.key} value={p.key}>
                    {p.label}
                  </option>
                ))}
                <option value="custom">Custom dates</option>
              </Select>
              <Input type="date" aria-label="From date" className="sm:w-40" value={filters.from} max={filters.to} onChange={(e) => {
                setPreset('custom')
                if (e.target.value) set({ from: e.target.value })
              }} />
              <Input type="date" aria-label="To date" className="sm:w-40" value={filters.to} min={filters.from} onChange={(e) => {
                setPreset('custom')
                if (e.target.value) set({ to: e.target.value })
              }} />
            </>
          )}
          {branches.length > 1 && (
            <Select aria-label="Branch" className="sm:w-48" value={String(filters.branch_id)} onChange={(e) => set({ branch_id: e.target.value === 'all' ? 'all' : Number(e.target.value) })}>
              <option value="all">All my branches</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </Select>
          )}
          {def.groupBy && (
            <Select aria-label="Group by" className="sm:w-36" value={filters.group_by} onChange={(e) => set({ group_by: e.target.value as ReportFilters['group_by'] })}>
              <option value="day">Daily</option>
              <option value="week">Weekly</option>
              <option value="month">Monthly</option>
              <option value="year">Annual</option>
            </Select>
          )}
          {key === 'products' && (
            <Select aria-label="Sort by" className="sm:w-44" value={filters.sort ?? 'net_sales'} onChange={(e) => set({ sort: e.target.value as ReportFilters['sort'] })}>
              <option value="net_sales">Highest sales</option>
              <option value="qty">Most units</option>
            </Select>
          )}
          {key === 'expenses' && (
            <Select aria-label="Show" className="sm:w-44" value={filters.group ?? 'none'} onChange={(e) => set({ group: e.target.value as ReportFilters['group'] })}>
              <option value="none">Each expense</option>
              <option value="category">By category</option>
            </Select>
          )}
          {report.isFetching && <Spinner size="sm" />}
        </FilterBar>
      </Card>

      {report.isError && <ApiErrorAlert error={ApiError.from(report.error)} />}

      {data && (
        <Card>
          <div className="border-b border-line-soft px-5 py-3 text-sm">
            <p className="font-medium text-ink">
              {account?.tenant.name} · {data.title}
            </p>
            <p>
              {def.current ? `As of ${formatDateTime(openedAt)}` : `${formatDate(`${data.filters.from}T00:00:00+08:00`)} – ${formatDate(`${data.filters.to}T00:00:00+08:00`)}`}
              {' · '}
              {data.filters.branches.map((b) => b.name).join(', ')}
            </p>
          </div>
          {chart && (
            <div className="border-b border-line-soft px-5 py-4 print:hidden">
              <BarChart data={chart} label="Net sales per period" />
            </div>
          )}
          <ReportTable columns={data.columns} rows={data.rows} totals={data.totals} />
          {data.notes.length > 0 && (
            <ul className="space-y-0.5 border-t border-line-soft px-5 py-3 text-xs text-ink-subtle">
              {data.notes.map((n) => (
                <li key={n}>{n}</li>
              ))}
            </ul>
          )}
        </Card>
      )}
    </div>
  )
}
