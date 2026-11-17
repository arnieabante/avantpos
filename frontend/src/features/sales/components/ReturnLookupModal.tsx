import { useState } from 'react'
import { Search } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Input, Modal } from '@/components/ui'
import type { Sale } from '@/features/pos/types'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { salesApi } from '../api'
import { SaleStatusBadge } from './SaleStatusBadge'

/** Find the customer's receipt by its invoice number to process a return. */
export function ReturnLookupModal({ onPick, onClose }: { onPick: (sale: Sale) => void; onClose: () => void }) {
  const [invoiceNo, setInvoiceNo] = useState('')
  const [results, setResults] = useState<Sale[] | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const find = async () => {
    if (!invoiceNo.trim()) return
    setBusy(true)
    setError(null)
    try {
      const found = await salesApi.lookup(invoiceNo.trim())
      if (found.length === 1 && found[0]!.can?.refund) onPick(found[0]!)
      else setResults(found)
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <Modal open onClose={onClose} size="md" title="Return / refund" description="Enter the invoice number printed on the customer's receipt.">
      <div className="space-y-4">
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            void find()
          }}
        >
          <Input
            aria-label="Invoice number"
            placeholder="e.g. 000000015"
            value={invoiceNo}
            onChange={(e) => setInvoiceNo(e.target.value)}
            leftAddon={<Search className="size-4" />}
            data-autofocus
          />
          <Button type="submit" loading={busy}>
            Find
          </Button>
        </form>
        <ApiErrorAlert error={error} />
        {results && results.length === 0 && <p className="text-sm text-ink-subtle">No sale with that invoice number at this branch.</p>}
        {results && results.length > 0 && (
          <ul className="divide-y divide-line rounded-md border border-line">
            {results.map((s) => (
              <li key={s.id} className="flex items-center gap-3 p-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-ink">
                    {s.invoice_no} · {formatMoney(s.totals.total)}
                  </p>
                  <p className="text-xs text-ink-subtle">
                    {formatDateTime(s.sold_at)} · {s.terminal?.name} · {s.customer?.name ?? 'Walk-in'}
                  </p>
                </div>
                <SaleStatusBadge status={s.status} />
                <Button size="sm" disabled={!s.can?.refund} onClick={() => onPick(s)}>
                  Return items
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  )
}
