import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { ArrowLeft, LifeBuoy, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, ButtonLink, Card, CardBody, EmptyState, FormField, Input, Modal, PageHeader, PageLoader, Select, Textarea } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { TICKET_CATEGORIES, TICKET_STATUS, supportApi, supportKeys } from '../api'

/** /support and /support/:ticketId — ask AvantPOS for help. */
export default function SupportPage() {
  const id = Number(useParams().ticketId)
  return Number.isFinite(id) && id > 0 ? <TicketThread id={id} /> : <TicketList />
}

function TicketList() {
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)
  const q = useQuery({ queryKey: supportKeys.all, queryFn: supportApi.list })

  return (
    <>
      <PageHeader
        title="Help and support"
        description="Ask the AvantPOS team about problems, billing or anything else. We reply here and by e-mail."
        actions={
          <Button leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
            New request
          </Button>
        }
      />
      {q.isError && <ApiErrorAlert error={ApiError.from(q.error)} />}
      {q.isPending ? (
        <PageLoader />
      ) : q.data && q.data.length === 0 ? (
        <Card>
          <EmptyState icon={<LifeBuoy className="size-5" />} title="No requests yet" description="Need help? Open a request and we will get back to you." />
        </Card>
      ) : (
        <Card>
          <ul className="divide-y divide-line-soft">
            {(q.data ?? []).map((t) => (
              <li key={t.id}>
                <Link to={`/support/${t.id}`} className="flex items-center gap-3 px-5 py-3 hover:bg-[#f8f9fa]">
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium text-ink">{t.subject}</p>
                    <p className="text-xs text-ink-subtle">
                      {t.number} · {TICKET_CATEGORIES[t.category ?? 'other']} · updated {formatDateTime(t.updated_at)}
                    </p>
                  </div>
                  <Badge tone={TICKET_STATUS[t.status].tone}>{TICKET_STATUS[t.status].label}</Badge>
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      )}
      {creating && <NewTicketModal onClose={() => setCreating(false)} onCreated={(id) => navigate(`/support/${id}`)} />}
    </>
  )
}

function NewTicketModal({ onCreated, onClose }: { onCreated: (id: number) => void; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [v, setV] = useState({ subject: '', category: 'technical', priority: 'normal', body: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const send = async () => {
    setBusy(true)
    setError(null)
    try {
      const t = await supportApi.open({ ...v, subject: v.subject.trim(), body: v.body.trim() })
      await queryClient.invalidateQueries({ queryKey: supportKeys.all })
      onCreated(t.id)
    } catch (e) {
      const err = ApiError.from(e)
      if (err.isValidation) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title="New support request"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={send} loading={busy}>
            Send
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <FormField label="Subject" required error={errors.subject}>
          <Input value={v.subject} maxLength={200} onChange={(e) => setV({ ...v, subject: e.target.value })} data-autofocus />
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="About">
            <Select value={v.category} onChange={(e) => setV({ ...v, category: e.target.value })}>
              {Object.entries(TICKET_CATEGORIES).map(([k, label]) => (
                <option key={k} value={k}>
                  {label}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="How urgent">
            <Select value={v.priority} onChange={(e) => setV({ ...v, priority: e.target.value })}>
              <option value="low">Not urgent</option>
              <option value="normal">Normal</option>
              <option value="high">Urgent: I cannot sell</option>
            </Select>
          </FormField>
        </div>
        <FormField label="Describe the problem" required hint="What happened, on which screen, and what you expected." error={errors.body}>
          <Textarea rows={6} maxLength={5000} value={v.body} onChange={(e) => setV({ ...v, body: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  )
}

function TicketThread({ id }: { id: number }) {
  const queryClient = useQueryClient()
  const q = useQuery({ queryKey: supportKeys.one(id), queryFn: () => supportApi.get(id) })
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />
  const t = q.data

  const reply = async () => {
    setBusy(true)
    setError(null)
    try {
      queryClient.setQueryData(supportKeys.one(id), await supportApi.reply(id, body.trim()))
      await queryClient.invalidateQueries({ queryKey: supportKeys.all, exact: true })
      setBody('')
    } catch (e) {
      setError(ApiError.from(e))
    }
    setBusy(false)
  }

  return (
    <div className="space-y-6">
      <ButtonLink to="/support" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Help and support
      </ButtonLink>
      <PageHeader title={t.subject} description={`${t.number} · ${TICKET_CATEGORIES[t.category ?? 'other']}`} actions={<Badge tone={TICKET_STATUS[t.status].tone}>{TICKET_STATUS[t.status].label}</Badge>} />
      <div className="space-y-3">
        {(t.messages ?? []).map((m) => (
          <Card key={m.id} className={cn('max-w-3xl', m.from === 'support' ? 'ml-auto border-l-4 border-l-brand-500' : '')}>
            <CardBody>
              <p className="text-xs text-ink-subtle">
                {m.author} · {formatDateTime(m.created_at)}
              </p>
              <p className="mt-1 text-sm whitespace-pre-line text-ink">{m.body}</p>
            </CardBody>
          </Card>
        ))}
      </div>
      {t.status !== 'closed' ? (
        <Card className="max-w-3xl">
          <CardBody className="space-y-3">
            <ApiErrorAlert error={error} />
            <FormField label="Reply">
              <Textarea rows={4} maxLength={5000} value={body} onChange={(e) => setBody(e.target.value)} />
            </FormField>
            <div className="flex justify-end">
              <Button onClick={reply} loading={busy} disabled={!body.trim()}>
                Send reply
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : (
        <p className="text-sm text-ink-subtle">This request is closed. Open a new one if you still need help.</p>
      )}
    </div>
  )
}
