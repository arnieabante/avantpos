import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { ArrowLeft, Printer } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, ButtonLink, PageHeader, PageLoader } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { cashApi, cashKeys } from '../api'
import { SessionReport } from '../components/SessionReport'

/** /cash-sessions/:sessionId — shift report (printable). */
export default function CashSessionPage() {
  const id = Number(useParams().sessionId)
  const session = useQuery({ queryKey: cashKeys.session(id), queryFn: () => cashApi.session(id), enabled: Number.isFinite(id) })

  if (session.isPending) return <PageLoader />
  if (session.isError) return <ApiErrorAlert error={ApiError.from(session.error)} />
  const s = session.data

  return (
    <div className="space-y-6">
      <ButtonLink to="/cash-sessions" variant="link" leftIcon={<ArrowLeft className="size-4" />} className="print:hidden">
        Cash shifts
      </ButtonLink>
      <PageHeader
        title={`Shift of ${s.cashier?.name ?? ''}`}
        description={`${s.terminal?.name ?? ''} · ${s.drawer?.name ?? ''} · ${formatDateTime(s.opened_at)}${s.closed_at ? ` – ${formatDateTime(s.closed_at)}` : ''}${s.closed_by && s.closed_by.id !== s.cashier?.id ? ` · closed by ${s.closed_by.name}` : ''}`}
        actions={
          <>
            <Badge tone={s.status === 'open' ? 'info' : 'neutral'}>{s.status === 'open' ? 'Open' : 'Closed'}</Badge>
            <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={() => window.print()} className="print:hidden">
              Print
            </Button>
          </>
        }
      />
      {s.closing_notes && <p className="text-sm text-ink-muted">Notes: {s.closing_notes}</p>}
      <SessionReport session={s} />
    </div>
  )
}
