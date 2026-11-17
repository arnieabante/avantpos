import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, MoreHorizontal, Plus, Star } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Badge,
  Button,
  Card,
  DataTable,
  Dropdown,
  EmptyState,
  FilterBar,
  FormField,
  Input,
  Modal,
  PageHeader,
  SearchInput,
  Select,
  useConfirm,
  useToast,
  type Column,
} from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatPhone } from '@/lib/format'
import { settingsApi, settingsKeys, type BranchInput } from '../api'
import { AddressFields } from '../components/AddressFields'
import { addressFromApi, defaultOperatingHours, emptyAddress } from '../formDefaults'
import { OperatingHoursEditor } from '../components/OperatingHoursEditor'
import type { AddressInput, Branch } from '../types'

type StatusFilter = '' | 'active' | 'inactive'

export default function BranchesPage() {
  const { can, refresh } = useAuth()
  const canManage = can('branches.manage')
  const confirm = useConfirm()
  const { run, isPending } = useAction()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<StatusFilter>('')
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)

  const params = { search: search || undefined, status: status || undefined }
  const branches = useQuery({ queryKey: settingsKeys.branches(params), queryFn: () => settingsApi.branches(params), placeholderData: (prev) => prev })

  // The branch list is part of the session (branch switcher), so refresh it too.
  const afterChange = { invalidate: [['branches']] }

  async function action(branch: Branch, kind: 'activate' | 'deactivate' | 'make-main') {
    if (kind === 'deactivate') {
      const ok = await confirm({
        title: `Deactivate ${branch.name}?`,
        description: 'Staff can no longer select this branch and it cannot sell. You can reactivate it later.',
        confirmLabel: 'Deactivate',
        danger: true,
      })
      if (!ok) return
    }
    const messages = { activate: 'Branch activated', deactivate: 'Branch deactivated', 'make-main': `${branch.name} is now the main branch` }
    if (await run(`${kind}-${branch.id}`, () => settingsApi.branchAction(branch.id, kind), { ...afterChange, success: messages[kind] })) {
      await refresh()
    }
  }

  const columns: Column<Branch>[] = [
    {
      key: 'name',
      header: 'Branch',
      render: (b) => (
        <div className="min-w-0">
          <p className="flex items-center gap-1.5 font-medium text-ink">
            {b.name}
            {b.is_main && <Star className="size-3.5 fill-amber-400 text-amber-400" aria-label="Main branch" />}
          </p>
          <p className="text-xs text-ink-subtle">{b.code}</p>
        </div>
      ),
    },
    { key: 'address', header: 'Address', className: 'hidden md:table-cell max-w-xs', render: (b) => <span className="line-clamp-2 text-ink-muted">{b.address.formatted ?? '—'}</span> },
    { key: 'phone', header: 'Phone', className: 'hidden lg:table-cell', render: (b) => formatPhone(b.phone) },
    { key: 'terminals', header: 'Terminals', align: 'right', className: 'hidden sm:table-cell', render: (b) => b.terminals_count ?? 0 },
    { key: 'users', header: 'Staff', align: 'right', className: 'hidden sm:table-cell', render: (b) => b.users_count ?? 0 },
    {
      key: 'status',
      header: 'Status',
      render: (b) => <Badge tone={b.is_active ? 'success' : 'neutral'}>{b.is_active ? 'Active' : 'Inactive'}</Badge>,
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (b) =>
        canManage ? (
          <Dropdown
            items={[
              { key: 'edit', label: 'Edit', onSelect: () => setEditing(b) },
              ...(!b.is_main && b.is_active ? [{ key: 'main', label: 'Make main branch', onSelect: () => action(b, 'make-main') }] : []),
              ...(b.is_active
                ? !b.is_main
                  ? [{ key: 'deactivate', label: 'Deactivate', danger: true, onSelect: () => action(b, 'deactivate') }]
                  : []
                : [{ key: 'activate', label: 'Activate', onSelect: () => action(b, 'activate') }]),
            ]}
            trigger={(props) => (
              <button
                type="button"
                {...props}
                disabled={isPending(`activate-${b.id}`) || isPending(`deactivate-${b.id}`) || isPending(`make-main-${b.id}`)}
                className="rounded-md p-2 text-ink-subtle hover:bg-brand-500/10 hover:text-brand-500"
                aria-label={`Actions for ${b.name}`}
              >
                <MoreHorizontal className="size-4" />
              </button>
            )}
          />
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title="Branches"
        description="Each branch keeps its own stock, terminals and sales."
        actions={canManage && <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add branch</Button>}
      />

      <Card>
        <FilterBar>
          <SearchInput value={search} onChange={setSearch} placeholder="Search name or code…" />
          <Select value={status} onChange={(e) => setStatus(e.target.value as StatusFilter)} className="sm:w-40" aria-label="Status">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </FilterBar>
        {branches.isError ? (
          <ApiErrorAlert error={ApiError.from(branches.error)} className="m-4" />
        ) : (
          <DataTable
            columns={columns}
            rows={branches.data}
            rowKey={(b) => b.id}
            loading={branches.isFetching}
            caption="Branches"
            empty={<EmptyState icon={<Building2 className="size-5" />} title="No branches found" description={search || status ? 'Try another search or filter.' : undefined} />}
          />
        )}
      </Card>

      {editing !== null && (
        <BranchFormModal
          branch={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null)
            await refresh()
          }}
        />
      )}
    </>
  )
}

function toInput(branch: Branch | null): BranchInput {
  return {
    code: branch?.code ?? '',
    name: branch?.name ?? '',
    phone: branch?.phone ?? '',
    email: branch?.email ?? '',
    bir_branch_code: branch?.bir_branch_code ?? '',
    operating_hours: branch ? branch.operating_hours ?? defaultOperatingHours() : defaultOperatingHours(),
    ...(branch ? addressFromApi(branch.address) : emptyAddress),
  }
}

function BranchFormModal({ branch, onClose, onSaved }: { branch: Branch | null; onClose: () => void; onSaved: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()

  const form = useForm({
    initialValues: toInput(branch),
    validate: (v) => ({
      code: v.code.trim() ? undefined : 'Enter a short code, e.g. QC-01.',
      name: v.name.trim() ? undefined : 'Enter the branch name.',
    }),
    onSubmit: async (values) => {
      if (branch) await settingsApi.updateBranch(branch.id, values)
      else await settingsApi.createBranch(values)
      await queryClient.invalidateQueries({ queryKey: ['branches'] })
      toast.success(branch ? 'Branch saved' : 'Branch created')
      onSaved()
    },
  })

  const address: AddressInput = {
    address_line: form.values.address_line,
    region_code: form.values.region_code,
    province_code: form.values.province_code,
    city_code: form.values.city_code,
    barangay_code: form.values.barangay_code,
    postal_code: form.values.postal_code,
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!form.submitting}
      size="lg"
      title={branch ? `Edit ${branch.name}` : 'Add branch'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="branch-form" loading={form.submitting}>
            {branch ? 'Save branch' : 'Create branch'}
          </Button>
        </>
      }
    >
      <form id="branch-form" onSubmit={form.handleSubmit} noValidate className="space-y-5">
        <ApiErrorAlert error={form.formError} />
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Code" required hint="e.g. QC-01" error={form.errors.code}>
            <Input {...form.field('code')} onChange={(e) => form.setValue('code', e.target.value.toUpperCase())} maxLength={20} data-autofocus />
          </FormField>
          <FormField label="Branch name" required error={form.errors.name} className="sm:col-span-2">
            <Input {...form.field('name')} />
          </FormField>
          <FormField label="Phone" error={form.errors.phone}>
            <Input {...form.field('phone')} type="tel" />
          </FormField>
          <FormField label="E-mail" error={form.errors.email}>
            <Input {...form.field('email')} type="email" />
          </FormField>
          <FormField label="BIR branch code" hint="e.g. 00001" error={form.errors.bir_branch_code}>
            <Input {...form.field('bir_branch_code')} inputMode="numeric" maxLength={5} />
          </FormField>
        </div>

        <section>
          <h3 className="mb-3 text-sm font-semibold text-ink">Address</h3>
          <AddressFields
            value={address}
            errors={form.errors}
            onChange={(next) => (Object.keys(next) as Array<keyof AddressInput>).forEach((key) => form.setValue(key, next[key]))}
          />
        </section>

        <section>
          <h3 className="mb-3 text-sm font-semibold text-ink">Opening hours</h3>
          <OperatingHoursEditor
            value={form.values.operating_hours ?? defaultOperatingHours()}
            onChange={(next) => form.setValue('operating_hours', next)}
            errors={form.errors}
          />
        </section>
      </form>
    </Modal>
  )
}
