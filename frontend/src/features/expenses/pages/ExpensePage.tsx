import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { ArrowLeft, Check, FileText, Paperclip, Pencil, Send, Trash2, X } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, ButtonLink, Card, CardBody, CardHeader, DetailList, FormField, IconButton, Modal, PageHeader, PageLoader, Textarea, useConfirm, useToast } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { EXPENSE_STATUS, expenseKeys, expensesApi, type Expense } from '../api'
import { ExpenseFormModal } from '../components/ExpenseFormModal'

/** /expenses/:expenseId — details, approval, receipts. */
export default function ExpensePage() {
  const id = Number(useParams().expenseId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const fileRef = useRef<HTMLInputElement>(null)
  const q = useQuery({ queryKey: expenseKeys.one(id), queryFn: () => expensesApi.get(id), enabled: Number.isFinite(id) })
  const [dialog, setDialog] = useState<'edit' | 'reject' | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<ApiError | null>(null)

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const e = q.data

  const act = async (name: string, action: () => Promise<Expense | string | void>, message: string) => {
    setBusy(name)
    setError(null)
    try {
      const result = await action()
      if (result && typeof result === 'object') queryClient.setQueryData(expenseKeys.one(id), result)
      await queryClient.invalidateQueries({ queryKey: ['expenses', 'list'] })
      toast.success(message)
    } catch (err) {
      setError(ApiError.from(err))
    }
    setBusy(null)
  }

  const remove = async () => {
    if (!(await confirm({ title: `Delete draft ${e.number}?`, confirmLabel: 'Delete', danger: true }))) return
    await act('delete', () => expensesApi.remove(e.id), 'Draft deleted')
    navigate('/expenses')
  }

  return (
    <div className="space-y-6">
      <ButtonLink to="/expenses" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Expenses
      </ButtonLink>
      <PageHeader
        title={`${e.number} · ${formatMoney(e.amount)}`}
        description={`${e.category?.name ?? ''} · ${formatDate(`${e.expense_date}T00:00:00+08:00`)} · ${e.branch?.name ?? ''}`}
        actions={
          <>
            <Badge tone={EXPENSE_STATUS[e.status].tone}>{EXPENSE_STATUS[e.status].label}</Badge>
            {e.can.edit && (
              <Button variant="outline" leftIcon={<Pencil className="size-4" />} onClick={() => setDialog('edit')}>
                Edit
              </Button>
            )}
            {e.can.delete && (
              <Button variant="outline" leftIcon={<Trash2 className="size-4" />} onClick={remove} loading={busy === 'delete'}>
                Delete
              </Button>
            )}
            {e.can.submit && (
              <Button leftIcon={<Send className="size-4" />} loading={busy === 'submit'} onClick={() => act('submit', () => expensesApi.submit(e.id), 'Submitted for approval')}>
                Submit
              </Button>
            )}
            {e.can.decide && (
              <>
                <Button variant="danger" leftIcon={<X className="size-4" />} onClick={() => setDialog('reject')}>
                  Reject
                </Button>
                <Button leftIcon={<Check className="size-4" />} loading={busy === 'approve'} onClick={() => act('approve', () => expensesApi.approve(e.id), 'Expense approved')}>
                  Approve
                </Button>
              </>
            )}
          </>
        }
      />
      <ApiErrorAlert error={error} />
      {e.status === 'rejected' && <Alert tone="danger" title="Rejected">{e.rejection_reason}</Alert>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Details" />
          <CardBody className="py-2">
            <DetailList
              items={[
                { label: 'Paid to', value: e.vendor ?? '—' },
                { label: 'Paid by', value: `${e.payment_method?.name ?? 'Not specified'}${e.paid_from_drawer ? ' (cash drawer payout)' : ''}` },
                { label: 'Receipt / bill no.', value: e.reference_no ?? '—' },
                { label: 'Notes', value: e.description ?? '—' },
                { label: 'Recorded by', value: `${e.submitted_by?.name ?? '—'}${e.created_at ? ` · ${formatDateTime(e.created_at)}` : ''}` },
                ...(e.approved_by ? [{ label: e.status === 'rejected' ? 'Rejected by' : 'Approved by', value: `${e.approved_by.name} · ${formatDateTime(e.approved_at)}` }] : []),
              ]}
            />
          </CardBody>
        </Card>
        <Card>
          <CardHeader
            title="Receipts"
            actions={
              e.can.attach && (
                <>
                  <input
                    ref={fileRef}
                    type="file"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    className="hidden"
                    aria-label="Attach a receipt"
                    onChange={(ev) => {
                      const file = ev.target.files?.[0]
                      ev.target.value = ''
                      if (file) void act('attach', () => expensesApi.attach(e.id, file), 'Receipt attached')
                    }}
                  />
                  <Button size="sm" variant="outline" leftIcon={<Paperclip className="size-4" />} loading={busy === 'attach'} onClick={() => fileRef.current?.click()}>
                    Attach
                  </Button>
                </>
              )
            }
          />
          <CardBody>
            {e.attachments.length === 0 ? (
              <p className="text-sm text-ink-subtle">No receipt attached. Photos (JPG, PNG) or PDF, up to 5 MB.</p>
            ) : (
              <ul className="space-y-2">
                {e.attachments.map((a) => (
                  <li key={a.id} className="flex items-center gap-2 text-sm">
                    <FileText className="size-4 text-ink-subtle" aria-hidden="true" />
                    <button type="button" className="min-w-0 flex-1 truncate text-left text-brand-500 hover:underline" onClick={() => void expensesApi.open(e.id, a.id).catch((err) => setError(ApiError.from(err)))}>
                      {a.name}
                    </button>
                    {e.can.edit && (
                      <IconButton label={`Remove ${a.name}`} size="sm" onClick={() => act('detach', () => expensesApi.detach(e.id, a.id), 'Receipt removed')}>
                        <Trash2 className="size-3.5" />
                      </IconButton>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      {dialog === 'edit' && (
        <ExpenseFormModal
          expense={e}
          onClose={() => setDialog(null)}
          onSaved={(saved) => {
            queryClient.setQueryData(expenseKeys.one(id), saved)
            void queryClient.invalidateQueries({ queryKey: ['expenses', 'list'] })
            setDialog(null)
            toast.success('Expense saved')
          }}
        />
      )}
      {dialog === 'reject' && (
        <RejectModal
          onClose={() => setDialog(null)}
          onReject={async (reason) => {
            await act('reject', () => expensesApi.reject(e.id, reason), 'Expense rejected')
            setDialog(null)
          }}
        />
      )}
    </div>
  )
}

function RejectModal({ onReject, onClose }: { onReject: (reason: string) => Promise<void>; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Reject expense"
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
              await onReject(reason.trim())
              setBusy(false)
            }}
          >
            Reject
          </Button>
        </>
      }
    >
      <FormField label="Reason" required hint="The person who recorded it will see this.">
        <Textarea rows={3} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} data-autofocus />
      </FormField>
    </Modal>
  )
}
