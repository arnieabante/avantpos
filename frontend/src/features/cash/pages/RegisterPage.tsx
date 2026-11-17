import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDownToLine, ArrowUpFromLine, FileBarChart, HandCoins, Lock, Monitor, ShoppingCart, Wallet } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, CardBody, CardHeader, FormField, Input, Modal, PageHeader, PageLoader, Select, Textarea, useToast } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { uuid } from '@/lib/uuid'
import { SellScreen } from '@/features/pos/components/SellScreen'
import { cachedRead, offlineQueue } from '@/features/pos/offline/store'
import type { RegisterReading } from '@/features/pos/types'
import { salesApi } from '@/features/sales/api'
import { ReadingModal } from '@/features/sales/components/ReadingReport'
import { cashApi, cashKeys } from '../api'
import { DenominationCounter } from '../components/DenominationCounter'
import { SessionReport, VarianceLabel } from '../components/SessionReport'
import { countPayload, countTotal, type Counts } from '../count'
import { MOVEMENT_LABELS, type CashSession, type MovementType, type PosTerminal } from '../types'

/** /pos — start a shift, sell, manage the drawer, close the shift. */
export default function RegisterPage() {
  const { activeBranch } = useAuth()
  // Kept on the device so the POS still opens offline.
  const terminals = useQuery({ queryKey: cashKeys.terminals, queryFn: () => cachedRead(`terminals.${activeBranch?.id ?? 0}`, cashApi.terminals) })

  if (terminals.isPending) return <PageLoader />
  if (terminals.isError) return <ApiErrorAlert error={ApiError.from(terminals.error)} />

  const mine = terminals.data.my_session
  if (mine && mine.branch?.id !== activeBranch?.id) {
    return (
      <Alert tone="warning" title="Your shift is open in another branch">
        You have an open shift on {mine.terminal?.name} at {mine.branch?.name}. Switch to that branch in the top bar to continue or close it.
      </Alert>
    )
  }
  return mine ? <OpenShift sessionId={mine.id} /> : <StartShift terminals={terminals.data.terminals} />
}

/* ------------------------------------------------------------------ Start */

function StartShift({ terminals }: { terminals: PosTerminal[] }) {
  const { can, activeBranch } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const free = terminals.filter((t) => !t.in_use_by)
  const [terminalId, setTerminalId] = useState<number | null>(free.length === 1 ? free[0]!.id : null)
  const [drawerId, setDrawerId] = useState<number | null>(null)
  const [mode, setMode] = useState<'amount' | 'count'>('amount')
  const [amount, setAmount] = useState('')
  const [counts, setCounts] = useState<Counts>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  // One key per attempt: a retry after a network error does not open a second shift.
  const [requestId] = useState(uuid)

  const allBusy = free.length === 0
  const terminal = terminals.find((t) => t.id === terminalId)
  const freeDrawers = (terminal?.drawers ?? []).filter((d) => !d.in_use)
  const float = mode === 'count' ? countTotal(counts) : amount.trim()

  if (!can('cash.open_session')) {
    return <Alert tone="info">You do not have permission to open a register shift. Ask your manager.</Alert>
  }
  if (terminals.length === 0) {
    return (
      <Alert tone="warning" title="No POS terminal in this branch">
        Add a terminal for {activeBranch?.name} in Settings → POS terminals first.
      </Alert>
    )
  }

  const start = async () => {
    const found: Record<string, string> = {}
    if (!terminal) found.terminal_id = 'Choose a terminal.'
    if (!/^\d+(\.\d{1,2})?$/.test(float)) found.opening_float = 'Enter the starting cash (0 if none).'
    setErrors(found)
    if (Object.keys(found).length) return

    setBusy(true)
    setError(null)
    try {
      await cashApi.open({ client_uuid: requestId, terminal_id: terminal!.id, cash_register_id: drawerId ?? freeDrawers[0]?.id ?? null, opening_float: float })
      await queryClient.invalidateQueries({ queryKey: cashKeys.all })
      toast.success(`Shift started on ${terminal!.name}.`)
    } catch (e) {
      const apiError = ApiError.from(e)
      if (apiError.isValidation) setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
      else setError(apiError)
      await queryClient.invalidateQueries({ queryKey: cashKeys.terminals })
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader title="Start your shift" description={`Choose your terminal at ${activeBranch?.name ?? 'this branch'} and count the starting cash.`} />
      <ApiErrorAlert error={error} />
      {allBusy && (
        <Alert tone="warning" title="All terminals are in use">
          Wait for a cashier to close their shift, or ask a manager to close a forgotten one under Cash shifts.
        </Alert>
      )}

      <Card>
        <CardHeader title="Terminal" />
        <CardBody>
          <div role="radiogroup" aria-label="Terminal" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {terminals.map((t) => {
              const busyBy = t.in_use_by
              const selected = t.id === terminalId
              return (
                <button
                  key={t.id}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  disabled={!!busyBy}
                  onClick={() => {
                    setTerminalId(t.id)
                    setDrawerId(null)
                    setErrors({})
                  }}
                  className={cn(
                    'flex items-start gap-3 rounded-md border p-4 text-left transition',
                    selected ? 'border-brand-500 bg-brand-100/50 ring-1 ring-brand-500' : 'border-line hover:border-brand-500',
                    busyBy && 'cursor-not-allowed opacity-60 hover:border-line',
                  )}
                >
                  <Monitor className={cn('mt-0.5 size-5', selected ? 'text-brand-500' : 'text-ink-subtle')} aria-hidden="true" />
                  <span>
                    <span className="block font-medium text-ink">{t.name}</span>
                    <span className="block text-xs text-ink-subtle">{t.code}</span>
                    {busyBy && <Badge tone="warning" className="mt-2">In use by {busyBy.name}</Badge>}
                  </span>
                </button>
              )
            })}
          </div>
          {errors.terminal_id && <p className="mt-2 text-xs text-[#ff3e1d]">{errors.terminal_id}</p>}
          {terminal && freeDrawers.length > 1 && (
            <FormField label="Cash drawer" className="mt-4 max-w-sm">
              <Select value={String(drawerId ?? freeDrawers[0]!.id)} onChange={(e) => setDrawerId(Number(e.target.value))}>
                {freeDrawers.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                    {d.shared ? ' (shared)' : ''}
                  </option>
                ))}
              </Select>
            </FormField>
          )}
          {errors.cash_register_id && <p className="mt-2 text-xs text-[#ff3e1d]">{errors.cash_register_id}</p>}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Starting cash (float)"
          actions={
            <Button size="sm" variant="ghost" onClick={() => setMode(mode === 'amount' ? 'count' : 'amount')}>
              {mode === 'amount' ? 'Count bills and coins' : 'Enter a total instead'}
            </Button>
          }
        />
        <CardBody>
          {mode === 'amount' ? (
            <FormField label="Amount in the drawer" error={errors.opening_float} className="max-w-xs">
              <Input value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" leftAddon="₱" placeholder="0.00" data-autofocus />
            </FormField>
          ) : (
            <>
              <DenominationCounter value={counts} onChange={setCounts} />
              {errors.opening_float && <p className="mt-2 text-xs text-[#ff3e1d]">{errors.opening_float}</p>}
            </>
          )}
        </CardBody>
      </Card>

      <div className="flex justify-end">
        <Button size="lg" onClick={start} loading={busy} disabled={allBusy}>
          Start shift with {formatMoney(/^\d+(\.\d{1,2})?$/.test(float) ? float : '0')}
        </Button>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ Open shift */

function OpenShift({ sessionId }: { sessionId: number }) {
  const { can, account } = useAuth()
  const toast = useToast()
  const [reading, setReading] = useState<RegisterReading | null>(null)
  const [xBusy, setXBusy] = useState(false)
  const [view, setView] = useState<'sell' | 'shift'>(can('pos.sell') ? 'sell' : 'shift')
  const session = useQuery({ queryKey: cashKeys.session(sessionId), queryFn: () => cashApi.session(sessionId), enabled: view === 'shift' })
  const [movement, setMovement] = useState<MovementType | null>(null)
  const [closing, setClosing] = useState(false)
  const [closed, setClosed] = useState<CashSession | null>(null)

  if (closed) return <ClosedResult session={closed} />

  const tabs = can('pos.sell') && (
    <div role="tablist" aria-label="Register" className="mb-4 inline-flex rounded-md border border-line bg-surface p-1">
      {([
        ['sell', 'Sell', ShoppingCart],
        ['shift', 'Shift & drawer', Wallet],
      ] as const).map(([key, label, Icon]) => (
        <button
          key={key}
          type="button"
          role="tab"
          aria-selected={view === key}
          onClick={() => setView(key)}
          className={cn('inline-flex items-center gap-2 rounded px-3 py-1.5 text-sm font-medium', view === key ? 'bg-brand-500 text-white' : 'text-ink hover:text-brand-500')}
        >
          <Icon className="size-4" aria-hidden="true" />
          {label}
        </button>
      ))}
    </div>
  )

  if (view === 'sell') {
    return (
      <>
        {tabs}
        <SellScreen sessionId={sessionId} />
      </>
    )
  }
  if (session.isPending) return <PageLoader />
  if (session.isError) return <ApiErrorAlert error={ApiError.from(session.error)} />

  const s = session.data
  const xReading = async () => {
    setXBusy(true)
    try {
      setReading(await salesApi.xReading(s.id))
    } catch (e) {
      toast.error(ApiError.from(e).message)
    }
    setXBusy(false)
  }
  return (
    <div className="space-y-6">
      {tabs}
      <PageHeader
        title={`Shift on ${s.terminal?.name ?? ''}`}
        description={`${s.cashier?.name ?? ''} · ${s.drawer?.name ?? ''} · started ${formatDateTime(s.opened_at)}`}
        actions={
          <>
            {s.can.operate && (
              <>
                <Button variant="outline" leftIcon={<ArrowDownToLine className="size-4" />} onClick={() => setMovement('cash_in')}>
                  Cash in
                </Button>
                <Button variant="outline" leftIcon={<ArrowUpFromLine className="size-4" />} onClick={() => setMovement('cash_out')}>
                  Cash out
                </Button>
                <Button variant="outline" leftIcon={<HandCoins className="size-4" />} onClick={() => setMovement('payout')}>
                  Payout
                </Button>
              </>
            )}
            <Button variant="outline" leftIcon={<FileBarChart className="size-4" />} onClick={xReading} loading={xBusy}>
              X reading
            </Button>
            {s.can.close && (
              <Button leftIcon={<Lock className="size-4" />} onClick={() => setClosing(true)}>
                Close shift
              </Button>
            )}
          </>
        }
      />
      <SessionReport session={s} />
      {movement && <MovementModal session={s} type={movement} onClose={() => setMovement(null)} />}
      {closing && <CloseModal session={s} onClose={() => setClosing(false)} onClosed={setClosed} />}
      {reading && <ReadingModal reading={reading} businessName={account?.tenant.name ?? ''} onClose={() => setReading(null)} />}
    </div>
  )
}

const MOVEMENT_HINTS: Record<MovementType, string> = {
  cash_in: 'Cash added to the drawer, e.g. more change from the safe.',
  cash_out: 'Cash taken out for safekeeping, e.g. a drop to the safe or bank.',
  payout: 'Cash paid out from the drawer, e.g. a delivery or supplies.',
}

function MovementModal({ session, type, onClose }: { session: CashSession; type: MovementType; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [requestId] = useState(uuid)

  const save = async () => {
    const found: Record<string, string> = {}
    if (!/^\d+(\.\d{1,2})?$/.test(amount.trim()) || /^0*(\.0*)?$/.test(amount.trim())) found.amount = 'Enter an amount.'
    if (reason.trim().length < 3) found.reason = 'Say what it is for.'
    setErrors(found)
    if (Object.keys(found).length) return
    setBusy(true)
    setError(null)
    try {
      const saved = await cashApi.move(session.id, { client_uuid: requestId, type, amount: amount.trim(), reason: reason.trim() })
      queryClient.setQueryData(cashKeys.session(session.id), saved)
      toast.success(`${MOVEMENT_LABELS[type]} of ${formatMoney(amount.trim())} recorded.`)
      onClose()
    } catch (e) {
      const apiError = ApiError.from(e)
      if (apiError.isValidation) setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
      else setError(apiError)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!busy}
      title={MOVEMENT_LABELS[type]}
      description={MOVEMENT_HINTS[type]}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <FormField label="Amount" required error={errors.amount}>
          <Input value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" leftAddon="₱" data-autofocus />
        </FormField>
        <FormField label="Reason" required error={errors.reason}>
          <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  )
}

function CloseModal({ session, onClose, onClosed }: { session: CashSession; onClose: () => void; onClosed: (s: CashSession) => void }) {
  const queryClient = useQueryClient()
  const [counts, setCounts] = useState<Counts>({})
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const { account } = useAuth()
  const close = async () => {
    setBusy(true)
    setError(null)
    // Offline sales of this shift must be uploaded first, or its cash count would miss them.
    const waiting = account && session.branch ? (await offlineQueue.list(session.branch.id, account.user.id)).filter((s) => s.cash_register_session_id === session.id) : []
    if (waiting.length) {
      setError(new ApiError(`${waiting.length} sale${waiting.length === 1 ? ' made offline is' : 's made offline are'} still on this device. Go back to Sell and upload them first.`))
      setBusy(false)
      return
    }
    try {
      const saved = await cashApi.close(session.id, { denominations: countPayload(counts), counted_cash: null, notes: notes.trim() || null })
      queryClient.setQueryData(cashKeys.session(session.id), saved)
      await queryClient.invalidateQueries({ queryKey: cashKeys.all, predicate: (q) => q.queryKey[1] !== 'session' })
      onClosed(saved)
    } catch (e) {
      setError(ApiError.from(e))
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      dismissible={!busy}
      title="Close shift: count the drawer"
      description="Count every bill and coin in the drawer. You will see the expected amount after closing."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Keep shift open
          </Button>
          <Button onClick={close} loading={busy}>
            Close shift with {formatMoney(countTotal(counts))}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <DenominationCounter value={counts} onChange={setCounts} />
        <FormField label="Notes">
          <Textarea rows={2} value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  )
}

function ClosedResult({ session }: { session: CashSession }) {
  const queryClient = useQueryClient()
  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader title="Shift closed" description={`${session.terminal?.name ?? ''} · ${formatDateTime(session.opened_at)} – ${formatDateTime(session.closed_at)}`} />
      <Card>
        <CardBody className="grid gap-6 text-center sm:grid-cols-3">
          <div>
            <p className="text-sm text-ink-muted">Expected</p>
            <p className="mt-1 text-2xl font-medium text-ink">{formatMoney(session.expected_cash)}</p>
          </div>
          <div>
            <p className="text-sm text-ink-muted">Counted</p>
            <p className="mt-1 text-2xl font-medium text-ink">{formatMoney(session.counted_cash)}</p>
          </div>
          <div>
            <p className="text-sm text-ink-muted">Over / short</p>
            <VarianceLabel variance={session.variance} className="mt-1 block text-2xl" />
          </div>
        </CardBody>
      </Card>
      <SessionReport session={session} />
      <div className="flex justify-end gap-2 print:hidden">
        <Button variant="outline" onClick={() => window.print()}>
          Print report
        </Button>
        <Button onClick={() => void queryClient.invalidateQueries({ queryKey: cashKeys.all })}>Done</Button>
      </div>
    </div>
  )
}
