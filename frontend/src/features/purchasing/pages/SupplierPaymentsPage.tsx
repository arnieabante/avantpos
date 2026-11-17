import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Banknote } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, DataTable, EmptyState, FormField, Input, Modal, PageHeader, Pagination, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatMoney } from '@/lib/format'
import { PaySupplierModal } from '../components/PayablesModals'
import { payableKeys, payablesApi, type SupplierPaymentRow } from '../payables'

/** /purchasing/payments — payments made to suppliers. */
export default function SupplierPaymentsPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const canManage = can('supplier_payments.manage')
  const [page, setPage] = useState(1)
  const [paying, setPaying] = useState(false)
  const [voiding, setVoiding] = useState<SupplierPaymentRow | null>(null)
  const list = useQuery({ queryKey: payableKeys.payments({ page }), queryFn: () => payablesApi.payments({ page }), placeholderData: (p) => p })

  const columns: Column<SupplierPaymentRow>[] = [
    { key: 'number', header: 'No.', render: (p) => <span className="font-medium whitespace-nowrap text-ink">{p.number}</span> },
    { key: 'date', header: 'Paid', render: (p) => <span className="whitespace-nowrap">{formatDate(`${p.paid_at}T00:00:00+08:00`)}</span> },
    { key: 'supplier', header: 'Supplier', render: (p) => p.supplier?.name ?? '—' },
    { key: 'bills', header: 'Bills', className: 'hidden lg:table-cell', render: (p) => p.allocations.map((a) => a.bill_number).join(', ') },
    { key: 'method', header: 'Paid by', className: 'hidden md:table-cell', render: (p) => [p.method, p.reference_no].filter(Boolean).join(' · ') || '—' },
    { key: 'amount', header: 'Amount', align: 'right', render: (p) => <span className={p.status === 'void' ? 'line-through tabular-nums' : 'tabular-nums'}>{formatMoney(p.amount)}</span> },
    {
      key: 'actions',
      header: '',
      render: (p) =>
        p.status === 'void' ? (
          <Badge tone="neutral">Void</Badge>
        ) : canManage ? (
          <Button size="sm" variant="ghost" onClick={() => setVoiding(p)}>
            Void
          </Button>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title="Supplier payments"
        actions={
          canManage && (
            <Button leftIcon={<Banknote className="size-4" />} onClick={() => setPaying(true)}>
              Pay supplier
            </Button>
          )
        }
      />
      <Card>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable columns={columns} rows={list.data?.items} rowKey={(p) => p.id} loading={list.isFetching} caption="Supplier payments"
              empty={<EmptyState icon={<Banknote className="size-5" />} title="No payments yet" />} />
            <Pagination meta={list.data?.pagination} onPageChange={setPage} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {paying && (
        <PaySupplierModal
          onClose={() => setPaying(false)}
          onSaved={(p) => {
            setPaying(false)
            void queryClient.invalidateQueries({ queryKey: payableKeys.all })
            toast.success(`Payment ${p.number} recorded`)
          }}
        />
      )}
      {voiding && (
        <VoidPaymentModal
          payment={voiding}
          onClose={() => setVoiding(null)}
          onVoided={() => {
            setVoiding(null)
            void queryClient.invalidateQueries({ queryKey: payableKeys.all })
            toast.success('Payment voided', 'Its amounts are owed again on the bills.')
          }}
        />
      )}
    </>
  )
}

function VoidPaymentModal({ payment, onVoided, onClose }: { payment: SupplierPaymentRow; onVoided: () => void; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={`Void payment ${payment.number}`}
      description="Use when a payment was entered by mistake or did not go through (e.g. a bounced check)."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="danger"
            loading={busy}
            disabled={reason.trim().length < 3}
            onClick={async () => {
              setBusy(true)
              try {
                await payablesApi.voidPayment(payment.id, reason.trim())
                onVoided()
              } catch (e) {
                setError(ApiError.from(e))
                setBusy(false)
              }
            }}
          >
            Void payment
          </Button>
        </>
      }
    >
      <ApiErrorAlert error={error} />
      <FormField label="Reason" required>
        <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} data-autofocus />
      </FormField>
    </Modal>
  )
}
