import { useState } from 'react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, FormField, Modal, Textarea } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApprovalModal } from '@/features/pos/components/ApprovalModal'
import type { PosSetup, Sale } from '@/features/pos/types'
import { ApiError } from '@/lib/api/errors'
import { formatMoney } from '@/lib/format'
import { salesApi } from '../api'

/**
 * Void a whole sale of a shift that is still open. Needs "Void sales" or a
 * supervisor's PIN. Stock, cash, account charges and points are reversed.
 */
export function VoidModal({ sale, approvers, onVoided, onClose }: { sale: Sale; approvers: PosSetup['approvers']; onVoided: (s: Sale) => void; onClose: () => void }) {
  const { can, account } = useAuth()
  const [reason, setReason] = useState('')
  const [reasonError, setReasonError] = useState<string>()
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [approving, setApproving] = useState(false)

  const submit = async (token: string | null) => {
    setBusy(true)
    setError(null)
    try {
      onVoided(await salesApi.void(sale.id, { reason: reason.trim(), override_token: token }))
    } catch (e) {
      const err = ApiError.from(e)
      if (err.code === 'APPROVAL_REQUIRED' || err.code === 'OVERRIDE_REQUIRED') setApproving(true)
      else setError(err)
      setBusy(false)
    }
  }

  const start = () => {
    if (reason.trim().length < 3) {
      setReasonError('Say why the sale is voided.')
      return
    }
    setReasonError(undefined)
    if (can('sales.void')) void submit(null)
    else setApproving(true)
  }

  return (
    <>
      <Modal
        open
        onClose={onClose}
        size="sm"
        dismissible={!busy}
        title={`Void invoice ${sale.invoice_no}`}
        footer={
          <>
            <Button variant="secondary" onClick={onClose} disabled={busy}>
              Keep sale
            </Button>
            <Button variant="danger" onClick={start} loading={busy}>
              Void {formatMoney(sale.totals.total)}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Alert tone="warning">
            The whole sale is cancelled: items go back to stock and the payment is taken out of the shift. Give the customer back what they paid. This
            cannot be undone.
          </Alert>
          <ApiErrorAlert error={error} />
          <FormField label="Reason" required error={reasonError}>
            <Textarea rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} data-autofocus />
          </FormField>
        </div>
      </Modal>
      {approving && (
        <ApprovalModal
          permissions={['sales.void']}
          approvers={approvers}
          selfId={account?.user.id}
          onClose={() => setApproving(false)}
          onApproved={(tokens) => {
            setApproving(false)
            void submit(tokens['sales.void'] ?? null)
          }}
        />
      )}
    </>
  )
}
