import { CloudUpload, WifiOff, X } from 'lucide-react'
import { Alert, Button, IconButton } from '@/components/ui'
import { formatDateTime } from '@/lib/format'
import type { useOfflinePos } from './useOfflinePos'

type Offline = ReturnType<typeof useOfflinePos>

/** Connection state, sales waiting to upload, and what happened on the last upload. */
export function OfflineBar({ offline, isOffline }: { offline: Offline; isOffline: boolean }) {
  const waiting = offline.queue.filter((s) => !s.error)
  const refused = offline.queue.filter((s) => s.error)
  const flagged = offline.lastResults.filter((r) => r.status === 'flagged')
  const renumbered = offline.lastResults.filter((r) => r.invoice_no && r.printed && r.invoice_no !== r.printed)
  const uploaded = offline.lastResults.filter((r) => r.status !== 'rejected')

  if (!isOffline && waiting.length === 0 && refused.length === 0 && offline.lastResults.length === 0) return null

  return (
    <div className="space-y-2 lg:col-span-2" aria-live="polite">
      {isOffline && (
        <div className="flex items-start gap-3 rounded-md border border-[#ffd27a] bg-[#fff2d6] p-3 text-sm text-[#8a5a00]">
          <WifiOff className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
          <div>
            <p className="font-medium">Offline — keep selling</p>
            <p>
              Sales are saved on this device and uploaded when the connection is back. Customers, discounts, Senior/PWD, holds and returns need the
              connection.{' '}
              {offline.catalog
                ? `Item list saved ${formatDateTime(offline.catalog.savedAt)}.`
                : 'The item list is not saved on this device yet: items cannot be found until the connection is back.'}
            </p>
          </div>
        </div>
      )}
      {waiting.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-md border border-line bg-surface p-3 text-sm">
          <CloudUpload className="size-5 text-brand-500" aria-hidden="true" />
          <span className="text-ink">
            {waiting.length} sale{waiting.length === 1 ? '' : 's'} waiting to upload (invoices {waiting[0]!.invoice_no}
            {waiting.length > 1 ? `–${waiting[waiting.length - 1]!.invoice_no}` : ''}).
          </span>
          <Button size="sm" variant="outline" className="ml-auto" loading={offline.syncing} disabled={isOffline} onClick={() => void offline.sync()}>
            Upload now
          </Button>
        </div>
      )}
      {refused.length > 0 && (
        <Alert tone="danger" title={`${refused.length} offline sale${refused.length === 1 ? ' was' : 's were'} refused`}>
          {refused.map((s) => `Invoice ${s.invoice_no}: ${s.error}`).join(' ')} They stay on this device; ask a manager before closing the shift.
        </Alert>
      )}
      {offline.lastResults.length > 0 && (
        <div className="flex items-start gap-3 rounded-md border border-line bg-surface p-3 text-sm text-ink">
          <div className="flex-1">
            <p>
              {uploaded.length} offline sale{uploaded.length === 1 ? '' : 's'} uploaded.
              {flagged.length > 0 && ` ${flagged.length} need a manager's review (Sales history → Needs review).`}
            </p>
            {renumbered.map((r) => (
              <p key={r.client_uuid} className="text-[#8a5a00]">
                Invoice {r.printed} was recorded as {r.invoice_no}. Reprint it from Sales history if the customer needs it.
              </p>
            ))}
          </div>
          <IconButton label="Dismiss" size="sm" onClick={offline.clearResults}>
            <X className="size-4" />
          </IconButton>
        </div>
      )}
    </div>
  )
}
