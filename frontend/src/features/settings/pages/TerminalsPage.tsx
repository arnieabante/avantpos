import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Monitor, MoreHorizontal, Plus, Wallet } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Badge,
  Button,
  Card,
  CardHeader,
  Checkbox,
  DataTable,
  Dropdown,
  EmptyState,
  FormField,
  Input,
  Modal,
  PageHeader,
  Select,
  useConfirm,
  useToast,
  type Column,
  type DropdownItem,
  type Tone,
} from '@/components/ui'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, humanize } from '@/lib/format'
import { settingsApi, settingsKeys, type CashRegisterInput, type TerminalInput } from '../api'
import type { CashRegister, Terminal, TerminalStatus } from '../types'

const STATUS_TONE: Record<TerminalStatus, Tone> = { active: 'success', inactive: 'neutral', retired: 'danger' }

function useActiveBranches() {
  return useQuery({
    queryKey: settingsKeys.branches({ status: 'active' }),
    queryFn: () => settingsApi.branches({ status: 'active' }),
    staleTime: 60_000,
  })
}

export default function TerminalsPage() {
  const confirm = useConfirm()
  const { run } = useAction()
  const [editing, setEditing] = useState<Terminal | 'new' | null>(null)
  const [editingDrawer, setEditingDrawer] = useState<CashRegister | 'new' | null>(null)

  const terminals = useQuery({ queryKey: settingsKeys.terminals, queryFn: settingsApi.terminals })
  const drawers = useQuery({ queryKey: settingsKeys.cashRegisters, queryFn: settingsApi.cashRegisters })
  const refreshAll = { invalidate: [settingsKeys.terminals, settingsKeys.cashRegisters, ['branches']] }

  async function terminalAction(t: Terminal, kind: 'activate' | 'deactivate' | 'retire' | 'revoke') {
    if (kind === 'retire') {
      const ok = await confirm({
        title: `Retire ${t.code}?`,
        description: 'Retiring is permanent: the device is unlinked and the terminal can no longer sell. Its records are kept for BIR and audit.',
        confirmLabel: 'Retire terminal',
        danger: true,
      })
      if (!ok) return
    }
    if (kind === 'revoke') {
      const ok = await confirm({
        title: `Unlink the device from ${t.code}?`,
        description: 'The device will stop syncing immediately. You can link a device again from the POS setup screen.',
        confirmLabel: 'Unlink device',
        danger: true,
      })
      if (!ok) return
    }
    const messages = { activate: 'Terminal activated', deactivate: 'Terminal deactivated', retire: 'Terminal retired', revoke: 'Device unlinked' }
    await run(
      `${kind}-${t.id}`,
      () => (kind === 'revoke' ? settingsApi.revokeDevice(t.id) : settingsApi.terminalAction(t.id, kind)),
      { ...refreshAll, success: messages[kind] },
    )
  }

  const terminalColumns: Column<Terminal>[] = [
    {
      key: 'terminal',
      header: 'Terminal',
      render: (t) => (
        <div>
          <p className="font-medium text-ink">{t.name}</p>
          <p className="text-xs text-ink-subtle">{t.code}</p>
        </div>
      ),
    },
    { key: 'branch', header: 'Branch', render: (t) => t.branch?.name ?? '—' },
    {
      key: 'device',
      header: 'Device',
      className: 'hidden md:table-cell',
      render: (t) =>
        t.device ? (
          <div>
            <p className="text-ink">{t.device.label}</p>
            <p className="text-xs text-ink-subtle">
              {t.device.platform ? humanize(t.device.platform) + ' · ' : ''}last seen {formatDateTime(t.device.last_seen_at)}
            </p>
          </div>
        ) : (
          <span className="text-ink-subtle">Not linked</span>
        ),
    },
    {
      key: 'ptu',
      header: 'BIR PTU',
      className: 'hidden lg:table-cell',
      render: (t) => (t.ptu_no ? `${t.ptu_no}${t.ptu_valid_until ? ` · until ${formatDate(t.ptu_valid_until)}` : ''}` : <span className="text-ink-subtle">—</span>),
    },
    { key: 'status', header: 'Status', render: (t) => <Badge tone={STATUS_TONE[t.status]}>{humanize(t.status)}</Badge> },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (t) => {
        if (t.status === 'retired') return null
        const items: DropdownItem[] = [{ key: 'edit', label: 'Edit', onSelect: () => setEditing(t) }]
        if (t.device) items.push({ key: 'revoke', label: 'Unlink device', onSelect: () => terminalAction(t, 'revoke') })
        items.push(
          t.status === 'active'
            ? { key: 'deactivate', label: 'Deactivate', onSelect: () => terminalAction(t, 'deactivate') }
            : { key: 'activate', label: 'Activate', onSelect: () => terminalAction(t, 'activate') },
        )
        items.push({ key: 'retire', label: 'Retire permanently', danger: true, onSelect: () => terminalAction(t, 'retire') })
        return <RowMenu label={`Actions for ${t.code}`} items={items} />
      },
    },
  ]

  const drawerColumns: Column<CashRegister>[] = [
    { key: 'name', header: 'Drawer', render: (r) => <span className="font-medium text-ink">{r.name}</span> },
    { key: 'branch', header: 'Branch', render: (r) => r.branch?.name ?? '—' },
    { key: 'terminal', header: 'Terminal', render: (r) => (r.terminal ? `${r.terminal.name} (${r.terminal.code})` : <span className="text-ink-subtle">Shared</span>) },
    { key: 'status', header: 'Status', render: (r) => <Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Badge> },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (r) => (
        <RowMenu
          label={`Actions for ${r.name}`}
          items={[
            { key: 'edit', label: 'Edit', onSelect: () => setEditingDrawer(r) },
            r.is_active
              ? { key: 'off', label: 'Deactivate', onSelect: () => run(`d-${r.id}`, () => settingsApi.cashRegisterAction(r.id, 'deactivate'), { ...refreshAll, success: 'Drawer deactivated' }) }
              : { key: 'on', label: 'Activate', onSelect: () => run(`d-${r.id}`, () => settingsApi.cashRegisterAction(r.id, 'activate'), { ...refreshAll, success: 'Drawer activated' }) },
          ]}
        />
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="POS terminals"
        description="A terminal is a selling station with its own BIR invoice series. Terminals are never deleted."
        actions={<Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add terminal</Button>}
      />

      <div className="space-y-6">
        <Card>
          {terminals.isError ? (
            <ApiErrorAlert error={ApiError.from(terminals.error)} className="m-4" />
          ) : (
            <DataTable
              columns={terminalColumns}
              rows={terminals.data}
              rowKey={(t) => t.id}
              loading={terminals.isFetching}
              caption="POS terminals"
              empty={<EmptyState icon={<Monitor className="size-5" />} title="No terminals yet" description="Add a terminal for each checkout counter or device." />}
            />
          )}
        </Card>

        <Card>
          <CardHeader
            title="Cash drawers"
            description="Every cash register session is counted per drawer. A drawer without a terminal is shared by the branch."
            actions={<Button size="sm" variant="secondary" leftIcon={<Plus className="size-4" />} onClick={() => setEditingDrawer('new')}>Add drawer</Button>}
          />
          {drawers.isError ? (
            <ApiErrorAlert error={ApiError.from(drawers.error)} className="m-4" />
          ) : (
            <DataTable
              columns={drawerColumns}
              rows={drawers.data}
              rowKey={(r) => r.id}
              loading={drawers.isFetching}
              caption="Cash drawers"
              empty={<EmptyState icon={<Wallet className="size-5" />} title="No cash drawers" />}
            />
          )}
        </Card>
      </div>

      {editing !== null && <TerminalFormModal terminal={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {editingDrawer !== null && (
        <DrawerFormModal drawer={editingDrawer === 'new' ? null : editingDrawer} terminals={terminals.data ?? []} onClose={() => setEditingDrawer(null)} />
      )}
    </>
  )
}

function RowMenu({ label, items }: { label: string; items: DropdownItem[] }) {
  return (
    <Dropdown
      items={items}
      trigger={(props) => (
        <button type="button" {...props} className="rounded-md p-2 text-ink-subtle hover:bg-brand-500/10 hover:text-brand-500" aria-label={label}>
          <MoreHorizontal className="size-4" />
        </button>
      )}
    />
  )
}

function TerminalFormModal({ terminal, onClose }: { terminal: Terminal | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const branches = useActiveBranches()

  const form = useForm<TerminalInput>({
    initialValues: {
      branch_id: terminal?.branch_id ?? '',
      code: terminal?.code ?? '',
      name: terminal?.name ?? '',
      min: terminal?.min ?? '',
      serial_no: terminal?.serial_no ?? '',
      ptu_no: terminal?.ptu_no ?? '',
      ptu_issued_at: terminal?.ptu_issued_at ?? '',
      ptu_valid_until: terminal?.ptu_valid_until ?? '',
      ...(terminal ? {} : { create_cash_register: true }),
    },
    validate: (v) => ({
      branch_id: v.branch_id ? undefined : 'Select a branch.',
      code: v.code.trim() ? undefined : 'Enter a code, e.g. POS-01.',
      name: v.name.trim() ? undefined : 'Enter a name, e.g. Counter 1.',
    }),
    onSubmit: async (values) => {
      if (terminal) await settingsApi.updateTerminal(terminal.id, values)
      else await settingsApi.createTerminal(values)
      await Promise.all([settingsKeys.terminals, settingsKeys.cashRegisters, ['branches']].map((queryKey) => queryClient.invalidateQueries({ queryKey })))
      toast.success(terminal ? 'Terminal saved' : 'Terminal created')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!form.submitting}
      title={terminal ? `Edit ${terminal.code}` : 'Add terminal'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="terminal-form" loading={form.submitting}>
            {terminal ? 'Save terminal' : 'Create terminal'}
          </Button>
        </>
      }
    >
      <form id="terminal-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Branch" required error={form.errors.branch_id}>
          <Select value={String(form.values.branch_id)} onChange={(e) => form.setValue('branch_id', e.target.value ? Number(e.target.value) : '')}>
            <option value="">{branches.isPending ? 'Loading…' : 'Select branch…'}</option>
            {branches.data?.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
        </FormField>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Code" required hint="e.g. POS-01" error={form.errors.code}>
            <Input {...form.field('code')} onChange={(e) => form.setValue('code', e.target.value.toUpperCase())} maxLength={20} />
          </FormField>
          <FormField label="Name" required hint="e.g. Counter 1" error={form.errors.name}>
            <Input {...form.field('name')} />
          </FormField>
        </div>

        <fieldset className="space-y-4 rounded-lg border border-line p-4">
          <legend className="px-1 text-sm font-semibold text-ink">BIR machine registration</legend>
          <p className="-mt-1 text-sm text-ink-subtle">Fill in after the BIR issues the Permit to Use (PTU) for this machine.</p>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Machine ID (MIN)" error={form.errors.min}>
              <Input {...form.field('min')} />
            </FormField>
            <FormField label="Serial no." error={form.errors.serial_no}>
              <Input {...form.field('serial_no')} />
            </FormField>
            <FormField label="PTU no." error={form.errors.ptu_no} className="sm:col-span-2">
              <Input {...form.field('ptu_no')} />
            </FormField>
            <FormField label="PTU issued" error={form.errors.ptu_issued_at}>
              <Input {...form.field('ptu_issued_at')} type="date" />
            </FormField>
            <FormField label="PTU valid until" error={form.errors.ptu_valid_until}>
              <Input {...form.field('ptu_valid_until')} type="date" />
            </FormField>
          </div>
        </fieldset>

        {!terminal && <Checkbox {...form.checkbox('create_cash_register')} label="Create a cash drawer for this terminal" />}
      </form>
    </Modal>
  )
}

function DrawerFormModal({ drawer, terminals, onClose }: { drawer: CashRegister | null; terminals: Terminal[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const branches = useActiveBranches()

  const form = useForm<CashRegisterInput>({
    initialValues: { branch_id: drawer?.branch_id ?? '', terminal_id: drawer?.terminal_id ?? '', name: drawer?.name ?? '' },
    validate: (v) => ({
      branch_id: v.branch_id ? undefined : 'Select a branch.',
      name: v.name.trim() ? undefined : 'Enter a name.',
    }),
    onSubmit: async (values) => {
      if (drawer) await settingsApi.updateCashRegister(drawer.id, values)
      else await settingsApi.createCashRegister(values)
      await Promise.all([settingsKeys.terminals, settingsKeys.cashRegisters].map((queryKey) => queryClient.invalidateQueries({ queryKey })))
      toast.success(drawer ? 'Drawer saved' : 'Drawer created')
      onClose()
    },
  })

  const branchTerminals = terminals.filter((t) => t.branch_id === form.values.branch_id && t.status === 'active')

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!form.submitting}
      size="sm"
      title={drawer ? 'Edit cash drawer' : 'Add cash drawer'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="drawer-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="drawer-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Name" required error={form.errors.name}>
          <Input {...form.field('name')} />
        </FormField>
        <FormField label="Branch" required error={form.errors.branch_id}>
          <Select
            value={String(form.values.branch_id)}
            onChange={(e) => {
              form.setValue('branch_id', e.target.value ? Number(e.target.value) : '')
              form.setValue('terminal_id', '')
            }}
          >
            <option value="">Select branch…</option>
            {branches.data?.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Terminal" hint="Leave as shared if several terminals use this drawer." error={form.errors.terminal_id}>
          <Select value={String(form.values.terminal_id ?? '')} onChange={(e) => form.setValue('terminal_id', e.target.value ? Number(e.target.value) : '')}>
            <option value="">Shared (no terminal)</option>
            {branchTerminals.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} ({t.code})
              </option>
            ))}
          </Select>
        </FormField>
      </form>
    </Modal>
  )
}
