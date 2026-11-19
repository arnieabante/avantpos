import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { MoreHorizontal, ShieldCheck, UserPlus, Users } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Alert,
  Badge,
  Button,
  Card,
  DataTable,
  Dropdown,
  EmptyState,
  FormField,
  Input,
  Modal,
  PageHeader,
  PasswordInput,
  Select,
  useConfirm,
  useToast,
  type Column,
  type DropdownItem,
} from '@/components/ui'
import { useAction } from '@/hooks/useAction'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { adminApi, adminKeys, type AdminRole, type TeamAdmin } from '../api'
import { ADMIN_ROLES } from '../labels'
import { useAdminAuth } from '../useAdminAuth'

/** /admin/team — AvantPOS staff accounts and their roles (super admins only). */
export default function AdminTeamPage() {
  const { admin: me } = useAdminAuth()
  const isSuper = me?.role === 'super_admin'
  const q = useQuery({ queryKey: adminKeys.team, queryFn: adminApi.team, enabled: isSuper })
  const { run } = useAction()
  const confirm = useConfirm()
  const [adding, setAdding] = useState(false)
  const [passwordFor, setPasswordFor] = useState<TeamAdmin | null>(null)
  const refresh = { invalidate: [adminKeys.team] }

  if (!isSuper) {
    return (
      <>
        <PageHeader title="Admin team" />
        <Alert tone="info">Only super admins manage the admin team.</Alert>
      </>
    )
  }

  const actions = (a: TeamAdmin): DropdownItem[] => {
    const self = a.id === me?.id
    const items: DropdownItem[] = []
    if (!self) {
      for (const role of Object.keys(ADMIN_ROLES) as AdminRole[]) {
        if (role !== a.role) {
          items.push({ key: `role-${role}`, label: `Make ${ADMIN_ROLES[role].label}`, onSelect: () => void run(`role-${a.id}`, () => adminApi.updateAdmin(a.id, { role }), { ...refresh, success: `${a.name} is now ${ADMIN_ROLES[role].label}` }) })
        }
      }
    }
    items.push({ key: 'password', label: 'Set a new password', onSelect: () => setPasswordFor(a) })
    if (a.two_factor) {
      items.push({
        key: '2fa',
        label: 'Reset two-factor sign-in',
        onSelect: async () => {
          const ok = await confirm({ title: `Reset two-factor for ${a.name}?`, description: 'They sign in with their password only until they set up their authenticator again.', confirmLabel: 'Reset' })
          if (ok) await run(`2fa-${a.id}`, () => adminApi.resetAdminTwoFactor(a.id), { ...refresh, success: 'Two-factor sign-in reset' })
        },
      })
    }
    if (!self) {
      items.push(
        a.is_active
          ? {
              key: 'deactivate',
              label: 'Deactivate',
              onSelect: async () => {
                const ok = await confirm({ title: `Deactivate ${a.name}?`, description: 'They are signed out and can no longer sign in.', confirmLabel: 'Deactivate', danger: true })
                if (ok) await run(`off-${a.id}`, () => adminApi.updateAdmin(a.id, { is_active: false }), { ...refresh, success: `${a.name} deactivated` })
              },
            }
          : { key: 'activate', label: 'Re-activate', onSelect: () => void run(`on-${a.id}`, () => adminApi.updateAdmin(a.id, { is_active: true }), { ...refresh, success: `${a.name} can sign in again` }) },
      )
    }
    return items
  }

  const columns: Column<TeamAdmin>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (a) => (
        <span>
          <span className="font-medium text-ink">{a.name}</span>
          {a.id === me?.id && <span className="text-ink-subtle"> (you)</span>}
          <span className="block text-xs text-ink-subtle">{a.email}</span>
        </span>
      ),
    },
    { key: 'role', header: 'Role', render: (a) => <Badge tone={ADMIN_ROLES[a.role].tone}>{ADMIN_ROLES[a.role].label}</Badge> },
    {
      key: '2fa',
      header: 'Two-factor',
      className: 'hidden md:table-cell',
      render: (a) => (a.two_factor ? <span className="inline-flex items-center gap-1 text-[#71dd37]"><ShieldCheck className="size-4" aria-hidden="true" />On</span> : <span className="text-ink-subtle">Off</span>),
    },
    { key: 'login', header: 'Last sign-in', className: 'hidden lg:table-cell', render: (a) => (a.last_login_at ? formatDateTime(a.last_login_at) : 'Never') },
    { key: 'status', header: 'Status', render: (a) => <Badge tone={a.is_active ? 'success' : 'neutral'}>{a.is_active ? 'Active' : 'Inactive'}</Badge> },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      className: 'w-12 text-right',
      render: (a) => (
        <Dropdown
          trigger={(props) => (
            <button type="button" {...props} className="rounded-md p-2 text-ink-subtle hover:bg-brand-500/10 hover:text-brand-500" aria-label={`Actions for ${a.name}`}>
              <MoreHorizontal className="size-4" />
            </button>
          )}
          items={actions(a)}
        />
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Admin team"
        description="AvantPOS staff who can use this admin area. Super admins manage everything, billing staff handle plans and payments, support staff answer tickets."
        actions={
          <Button leftIcon={<UserPlus className="size-4" />} onClick={() => setAdding(true)}>
            Add admin
          </Button>
        }
      />
      <Card>
        {q.isError ? (
          <ApiErrorAlert error={ApiError.from(q.error)} className="m-4" />
        ) : (
          <DataTable columns={columns} rows={q.data} rowKey={(a) => a.id} loading={q.isFetching} caption="Admin team"
            empty={<EmptyState icon={<Users className="size-5" />} title="No admins yet" />} />
        )}
      </Card>
      {adding && <AddAdminModal onClose={() => setAdding(false)} />}
      {passwordFor && <PasswordModal admin={passwordFor} onClose={() => setPasswordFor(null)} />}
    </>
  )
}

function AddAdminModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [v, setV] = useState({ name: '', email: '', role: 'support' as AdminRole, password: '', password_confirmation: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const errors = error?.errors ?? {}

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await adminApi.addAdmin({ ...v, email: v.email.trim() })
      await queryClient.invalidateQueries({ queryKey: adminKeys.team })
      toast.success(`${v.name} added`, `They can sign in at /admin/login as ${ADMIN_ROLES[v.role].label}.`)
      onClose()
    } catch (e) {
      setError(ApiError.from(e))
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!busy}
      title="Add an admin"
      description="Give them the starting password; they should change it and turn on two-factor sign-in after their first sign-in."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button loading={busy} disabled={!v.name.trim() || !v.email.trim() || !v.password} onClick={submit}>
            Add admin
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error && Object.keys(errors).length === 0 ? error : null} />
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Name" required error={errors.name?.[0]}>
            <Input value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} data-autofocus />
          </FormField>
          <FormField label="E-mail address" required error={errors.email?.[0]}>
            <Input type="email" value={v.email} onChange={(e) => setV({ ...v, email: e.target.value })} autoComplete="off" />
          </FormField>
        </div>
        <FormField label="Role" required hint={ADMIN_ROLES[v.role].description}>
          <Select value={v.role} onChange={(e) => setV({ ...v, role: e.target.value as AdminRole })}>
            {(Object.keys(ADMIN_ROLES) as AdminRole[]).map((r) => (
              <option key={r} value={r}>
                {ADMIN_ROLES[r].label}
              </option>
            ))}
          </Select>
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Starting password" required error={errors.password?.[0]}>
            <PasswordInput value={v.password} onChange={(e) => setV({ ...v, password: e.target.value })} autoComplete="new-password" />
          </FormField>
          <FormField label="Confirm password" required>
            <PasswordInput value={v.password_confirmation} onChange={(e) => setV({ ...v, password_confirmation: e.target.value })} autoComplete="new-password" />
          </FormField>
        </div>
      </div>
    </Modal>
  )
}

function PasswordModal({ admin, onClose }: { admin: TeamAdmin; onClose: () => void }) {
  const { run, pending } = useAction()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

  return (
    <Modal
      open
      onClose={onClose}
      title={`New password for ${admin.name}`}
      description="Tell them the new password in person or by phone, not by e-mail."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            loading={pending === 'pw'}
            disabled={!password || password !== confirmation}
            onClick={async () => {
              if (await run('pw', () => adminApi.resetAdminPassword(admin.id, password, confirmation), { success: 'Password changed' })) onClose()
            }}
          >
            Save password
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="New password" required>
          <PasswordInput value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" data-autofocus />
        </FormField>
        <FormField label="Confirm password" required error={confirmation && password !== confirmation ? 'The passwords do not match.' : undefined}>
          <PasswordInput value={confirmation} onChange={(e) => setConfirmation(e.target.value)} autoComplete="new-password" />
        </FormField>
      </div>
    </Modal>
  )
}
