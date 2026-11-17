import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { CreditCard } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, DataTable, EmptyState, FilterBar, FormField, Input, Modal, PageHeader, Pagination, Select, useConfirm, useToast, type Column } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, formatMoney, humanize } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useAdminAuth } from '../useAdminAuth'
import { adminApi, adminKeys, type AdminInvoice, type AdminPayment } from '../api'

/** /admin/billing — payments (verify bank transfers) and subscription invoices. */
export default function AdminBillingPage() {
  const [tab, setTab] = useState<'payments' | 'invoices'>('payments')
  return (
    <>
      <PageHeader title="Billing" description="Verify bank transfers and follow every subscription invoice." />
      <div role="tablist" aria-label="Billing" className="mb-4 inline-flex rounded-md border border-line bg-surface p-1">
        {(['payments', 'invoices'] as const).map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
            className={cn('rounded px-3 py-1.5 text-sm font-medium', tab === t ? 'bg-brand-500 text-white' : 'text-ink hover:text-brand-500')}>
            {t === 'payments' ? 'Payments' : 'Invoices'}
          </button>
        ))}
      </div>
      {tab === 'payments' ? <Payments /> : <Invoices />}
    </>
  )
}

function Payments() {
  const { admin } = useAdminAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [f, setF] = useState<{ page: number; status?: string }>({ page: 1, status: 'pending' })
  const [rejecting, setRejecting] = useState<AdminPayment | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const q = useQuery({ queryKey: adminKeys.payments(f), queryFn: () => adminApi.payments(f), placeholderData: (p) => p })
  const canAct = ['super_admin', 'billing'].includes(admin?.role ?? '')

  const verify = async (p: AdminPayment) => {
    const ok = await confirm({
      title: `Verify ${formatMoney(p.amount)} from ${p.tenant?.name}?`,
      description: `Only if the money is in the AvantPOS account (ref. ${p.reference}). Invoice ${p.invoice?.number} becomes paid and the subscription active.`,
      confirmLabel: 'Verify payment',
    })
    if (!ok) return
    try {
      toast.success(await adminApi.verifyPayment(p.id))
      await queryClient.invalidateQueries({ queryKey: adminKeys.all })
    } catch (e) {
      setError(ApiError.from(e))
    }
  }

  const columns: Column<AdminPayment>[] = [
    { key: 'tenant', header: 'Business', render: (p) => (p.tenant ? <Link to={`/admin/tenants/${p.tenant.id}`} className="text-brand-500 hover:underline" onClick={(e) => e.stopPropagation()}>{p.tenant.name}</Link> : '—') },
    { key: 'invoice', header: 'Invoice', render: (p) => `${p.invoice?.number ?? '—'} · ${p.invoice?.plan ?? ''}` },
    { key: 'how', header: 'Paid through', render: (p) => <span>{p.channel ?? humanize(p.gateway)}<span className="block text-xs text-ink-subtle">ref. {p.reference ?? '—'}{p.paid_on ? ` · sent ${formatDate(p.paid_on)}` : ''}</span></span> },
    { key: 'amount', header: 'Amount', align: 'right', render: (p) => <span className={p.invoice && p.amount !== p.invoice.total ? 'text-[#ff3e1d]' : ''}>{formatMoney(p.amount)}{p.invoice && p.amount !== p.invoice.total && <span className="block text-xs">invoice {formatMoney(p.invoice.total)}</span>}</span> },
    { key: 'when', header: 'When', className: 'hidden lg:table-cell', render: (p) => formatDateTime(p.paid_at ?? p.submitted_at) },
    {
      key: 'status',
      header: 'Status',
      render: (p) =>
        p.status === 'pending' && canAct ? (
          <span className="flex gap-1">
            <Button size="sm" onClick={() => verify(p)}>Verify</Button>
            <Button size="sm" variant="ghost" onClick={() => setRejecting(p)}>Reject</Button>
          </span>
        ) : (
          <Badge tone={p.status === 'paid' ? 'success' : p.status === 'pending' ? 'warning' : 'danger'}>{p.status === 'paid' ? `Paid${p.verified_by ? ` · ${p.verified_by}` : ''}` : humanize(p.status)}</Badge>
        ),
    },
  ]

  return (
    <Card>
      <FilterBar>
        <Select aria-label="Status" className="sm:w-48" value={f.status ?? ''} onChange={(e) => setF({ page: 1, status: e.target.value || undefined })}>
          <option value="pending">To verify</option>
          <option value="paid">Paid</option>
          <option value="failed">Failed / rejected</option>
          <option value="">All payments</option>
        </Select>
      </FilterBar>
      <ApiErrorAlert error={error} className="m-4" />
      {q.isError ? <ApiErrorAlert error={ApiError.from(q.error)} className="m-4" /> : (
        <>
          <DataTable columns={columns} rows={q.data?.items} rowKey={(p) => p.id} loading={q.isFetching} caption="Payments" empty={<EmptyState icon={<CreditCard className="size-5" />} title="Nothing here" />} />
          <Pagination meta={q.data?.pagination} onPageChange={(page) => setF({ ...f, page })} disabled={q.isFetching} className="border-t border-line-soft" />
        </>
      )}
      {rejecting && (
        <RejectModal
          payment={rejecting}
          onClose={() => setRejecting(null)}
          onDone={async () => {
            setRejecting(null)
            await queryClient.invalidateQueries({ queryKey: adminKeys.all })
            toast.success('Payment rejected; the business sees the reason.')
          }}
        />
      )}
    </Card>
  )
}

function RejectModal({ payment, onDone, onClose }: { payment: AdminPayment; onDone: () => void; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  return (
    <Modal open onClose={onClose} size="sm" title="Reject transfer" description={`${payment.tenant?.name} · ref. ${payment.reference}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button variant="danger" loading={busy} disabled={reason.trim().length < 3} onClick={async () => {
            setBusy(true)
            try {
              await adminApi.rejectPayment(payment.id, reason.trim())
              onDone()
            } catch (e) {
              setError(ApiError.from(e))
              setBusy(false)
            }
          }}>Reject</Button>
        </>
      }>
      <ApiErrorAlert error={error} />
      <FormField label="Reason" required hint="Shown to the business, e.g. 'No transfer with this reference found'.">
        <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} data-autofocus />
      </FormField>
    </Modal>
  )
}

function Invoices() {
  const [f, setF] = useState<{ page: number; status?: string }>({ page: 1 })
  const q = useQuery({ queryKey: adminKeys.invoices(f), queryFn: () => adminApi.invoices(f), placeholderData: (p) => p })
  const columns: Column<AdminInvoice>[] = [
    { key: 'number', header: 'Invoice', render: (i) => <span className="font-medium text-ink">{i.number}</span> },
    { key: 'tenant', header: 'Business', render: (i) => (i.tenant ? <Link to={`/admin/tenants/${i.tenant.id}`} className="text-brand-500 hover:underline">{i.tenant.name}</Link> : '—') },
    { key: 'plan', header: 'Plan', render: (i) => `${i.plan} · ${i.billing_cycle} · ${humanize(i.kind)}` },
    { key: 'total', header: 'Total', align: 'right', render: (i) => formatMoney(i.total) },
    { key: 'dates', header: 'Issued / due', className: 'hidden lg:table-cell', render: (i) => `${formatDate(i.issued_at)} / ${formatDate(i.due_at)}` },
    { key: 'status', header: 'Status', render: (i) => <Badge tone={i.status === 'paid' ? 'success' : i.status === 'open' ? 'info' : 'neutral'}>{i.status === 'paid' ? `Paid ${formatDate(i.paid_at)}` : humanize(i.status)}</Badge> },
  ]
  return (
    <Card>
      <FilterBar>
        <Select aria-label="Status" className="sm:w-44" value={f.status ?? ''} onChange={(e) => setF({ page: 1, status: e.target.value || undefined })}>
          <option value="">All invoices</option>
          <option value="open">Open</option>
          <option value="paid">Paid</option>
          <option value="void">Void</option>
        </Select>
      </FilterBar>
      {q.isError ? <ApiErrorAlert error={ApiError.from(q.error)} className="m-4" /> : (
        <>
          <DataTable columns={columns} rows={q.data?.items} rowKey={(i) => i.id} loading={q.isFetching} caption="Invoices" empty={<EmptyState icon={<CreditCard className="size-5" />} title="No invoices" />} />
          <Pagination meta={q.data?.pagination} onPageChange={(page) => setF({ ...f, page })} disabled={q.isFetching} className="border-t border-line-soft" />
        </>
      )}
    </Card>
  )
}
