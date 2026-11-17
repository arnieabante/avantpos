import { useState } from 'react'
import { ShieldCheck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, FormField, Input, Modal, Select } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { posApi } from '../api'
import { APPROVAL_LABELS, type PosSetup } from '../types'

interface Props {
  /** Permissions the cashier lacks for this sale. */
  permissions: string[]
  approvers: PosSetup['approvers']
  /** Current user, who cannot approve their own sale with a PIN. */
  selfId: number | undefined
  onApproved: (tokens: Record<string, string>) => void
  onClose: () => void
}

/**
 * Supervisor approval at the till: the supervisor picks their name and
 * enters their PIN. One single-use token is issued per permission; if the
 * supervisor can only approve part of it, another supervisor approves the rest.
 */
export function ApprovalModal({ permissions, approvers, selfId, onApproved, onClose }: Props) {
  const [tokens, setTokens] = useState<Record<string, string>>({})
  const pending = permissions.filter((p) => !tokens[p])
  const eligible = approvers.filter((a) => a.id !== selfId && a.permissions.some((p) => pending.includes(p)))
  const [supervisorId, setSupervisorId] = useState<number | null>(eligible.length === 1 ? eligible[0]!.id : null)
  const [pin, setPin] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const approve = async () => {
    const supervisor = eligible.find((a) => a.id === supervisorId)
    if (!supervisor || pin.trim().length < 4) {
      setError(new ApiError(!supervisor ? 'Choose who is approving.' : 'Enter the PIN.'))
      return
    }
    setBusy(true)
    setError(null)
    const next = { ...tokens }
    try {
      for (const permission of pending.filter((p) => supervisor.permissions.includes(p))) {
        const granted = await posApi.override({ supervisor_id: supervisor.id, pin: pin.trim(), permission })
        next[permission] = granted.override_token
      }
      setPin('')
      if (permissions.every((p) => next[p])) {
        onApproved(next)
        return
      }
      setTokens(next)
      setSupervisorId(null)
    } catch (e) {
      setTokens(next)
      setError(ApiError.from(e))
      setPin('')
    }
    setBusy(false)
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!busy}
      title="Supervisor approval"
      description="This sale needs a supervisor's approval."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={approve} loading={busy} disabled={eligible.length === 0} leftIcon={<ShieldCheck className="size-4" />}>
            Approve
          </Button>
        </>
      }
    >
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          void approve()
        }}
      >
        <ul className="space-y-1 text-sm">
          {permissions.map((p) => (
            <li key={p} className="flex items-center justify-between gap-2">
              <span className="text-ink">{APPROVAL_LABELS[p] ?? p}</span>
              <span className={tokens[p] ? 'text-xs font-medium text-[#71dd37]' : 'text-xs text-ink-subtle'}>{tokens[p] ? 'Approved' : 'Needed'}</span>
            </li>
          ))}
        </ul>
        {eligible.length === 0 ? (
          <Alert tone="warning">
            No one at this branch with a PIN can approve this. A manager sets their PIN under My account → POS PIN.
          </Alert>
        ) : (
          <>
            <ApiErrorAlert error={error} />
            <FormField label="Approved by" required>
              <Select value={supervisorId ?? ''} onChange={(e) => setSupervisorId(e.target.value ? Number(e.target.value) : null)}>
                <option value="">Choose…</option>
                {eligible.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="PIN" required>
              <Input
                type="password"
                inputMode="numeric"
                autoComplete="off"
                maxLength={12}
                value={pin}
                onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                data-autofocus
              />
            </FormField>
            <button type="submit" hidden />
          </>
        )}
      </form>
    </Modal>
  )
}
