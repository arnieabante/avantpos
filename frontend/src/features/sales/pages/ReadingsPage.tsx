import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FileBarChart } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, DataTable, EmptyState, FilterBar, FormField, Modal, PageHeader, Pagination, Select, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { cashApi, cashKeys } from '@/features/cash/api'
import type { RegisterReading } from '@/features/pos/types'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney } from '@/lib/format'
import { salesApi, salesKeys } from '../api'
import { ReadingModal } from '../components/ReadingReport'

/** /pos/readings — X and Z readings of the branch's terminals; Z reading at day end. */
export default function ReadingsPage() {
  const { can, account } = useAuth()
  const [filters, setFilters] = useState<{ page?: number; type?: 'X' | 'Z' }>({ page: 1 })
  const [open, setOpen] = useState<RegisterReading | null>(null)
  const [generating, setGenerating] = useState(false)
  const list = useQuery({ queryKey: salesKeys.readings(filters), queryFn: () => salesApi.readings(filters), placeholderData: (prev) => prev })

  const columns: Column<RegisterReading>[] = [
    { key: 'no', header: 'Reading', render: (r) => <span className="font-medium whitespace-nowrap text-ink">{r.type}-{String(r.reading_no).padStart(4, '0')}</span> },
    { key: 'date', header: 'Business date', render: (r) => <span className="whitespace-nowrap">{formatDate(r.business_date)}</span> },
    { key: 'terminal', header: 'Terminal', render: (r) => r.terminal?.name ?? '—' },
    { key: 'by', header: 'By', className: 'hidden lg:table-cell', render: (r) => r.generated_by?.name ?? '—' },
    { key: 'tx', header: 'Sales', align: 'right', className: 'hidden md:table-cell', render: (r) => r.transaction_count },
    { key: 'net', header: 'Net sales', align: 'right', render: (r) => <span className="tabular-nums">{formatMoney(r.net_sales)}</span> },
    { key: 'type', header: '', render: (r) => <Badge tone={r.type === 'Z' ? 'brand' : 'neutral'}>{r.type === 'Z' ? 'End of day' : 'Shift'}</Badge> },
  ]

  return (
    <>
      <PageHeader
        title="X and Z readings"
        description="X readings summarise a shift; the Z reading closes the day for a terminal and adds to its grand total."
        actions={can('cash.z_reading') && <Button onClick={() => setGenerating(true)}>Z reading (end of day)</Button>}
      />
      <Card>
        <FilterBar>
          <Select aria-label="Type" className="sm:w-44" value={filters.type ?? ''} onChange={(e) => setFilters({ page: 1, type: (e.target.value || undefined) as 'X' | 'Z' | undefined })}>
            <option value="">All readings</option>
            <option value="Z">Z (end of day)</option>
            <option value="X">X (shift)</option>
          </Select>
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(r) => r.id}
              loading={list.isFetching}
              onRowClick={setOpen}
              caption="Readings"
              empty={<EmptyState icon={<FileBarChart className="size-5" />} title="No readings yet" description="Run the Z reading after the last shift of the day is closed." />}
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {open && <ReadingModal reading={open} businessName={account?.tenant.name ?? ''} onClose={() => setOpen(null)} />}
      {generating && <ZReadingModal onClose={() => setGenerating(false)} onDone={(r) => {
        setGenerating(false)
        setOpen(r)
      }} />}
    </>
  )
}

function ZReadingModal({ onClose, onDone }: { onClose: () => void; onDone: (r: RegisterReading) => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const terminals = useQuery({ queryKey: cashKeys.terminals, queryFn: cashApi.terminals })
  const [terminalId, setTerminalId] = useState<number | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const list = terminals.data?.terminals ?? []
  const chosen = list.find((t) => t.id === (terminalId ?? list[0]?.id))

  const run = async () => {
    if (!chosen) return
    setBusy(true)
    setError(null)
    try {
      const reading = await salesApi.zReading(chosen.id)
      await queryClient.invalidateQueries({ queryKey: ['sales', 'readings'] })
      toast.success(`Z reading #${reading.reading_no} done for ${chosen.name}`)
      onDone(reading)
    } catch (e) {
      setError(ApiError.from(e))
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!busy}
      title="Z reading (end of day)"
      description="Totals every sale and return on the terminal since its last Z reading. Do this once a day, after the last shift is closed. It cannot be undone."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={run} loading={busy} disabled={!chosen || !!chosen.in_use_by}>
            Run Z reading
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        {terminals.isError && <ApiErrorAlert error={ApiError.from(terminals.error)} />}
        <FormField label="Terminal">
          <Select value={chosen?.id ?? ''} onChange={(e) => setTerminalId(Number(e.target.value))}>
            {list.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} ({t.code})
              </option>
            ))}
          </Select>
        </FormField>
        {chosen?.in_use_by && (
          <Alert tone="warning">
            {chosen.in_use_by.name} still has a shift open on {chosen.name}. Close it first.
          </Alert>
        )}
      </div>
    </Modal>
  )
}
