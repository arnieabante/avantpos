import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Ruler } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, Checkbox, DataTable, EmptyState, FormField, Input, Modal, PageHeader, useConfirm, useToast, type Column } from '@/components/ui'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { catalogApi, catalogKeys } from '../api'
import type { Unit } from '../types'

export default function UnitsPage() {
  const confirm = useConfirm()
  const { run } = useAction()
  const [editing, setEditing] = useState<Unit | 'new' | null>(null)
  const units = useQuery({ queryKey: catalogKeys.units, queryFn: catalogApi.units })

  async function remove(u: Unit) {
    if (!(await confirm({ title: `Delete the unit "${u.name}"?`, confirmLabel: 'Delete', danger: true }))) return
    await run(`delete-${u.id}`, () => catalogApi.deleteUnit(u.id), { invalidate: [catalogKeys.units], success: 'Unit deleted' })
  }

  const columns: Column<Unit>[] = [
    { key: 'name', header: 'Unit', render: (u) => <span className="font-medium text-ink">{u.name}</span> },
    { key: 'abbr', header: 'Short', render: (u) => <span className="font-mono">{u.abbreviation}</span> },
    { key: 'decimal', header: 'Quantities', render: (u) => (u.allows_decimal ? <Badge tone="info">Decimals (e.g. 1.25)</Badge> : <Badge>Whole numbers</Badge>) },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (u) => (
        <div className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setEditing(u)}>
            Edit
          </Button>
          <Button size="sm" variant="ghost" onClick={() => remove(u)}>
            Delete
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Units"
        description="How products are counted and sold: piece, pack, kilogram, meter…"
        actions={<Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add unit</Button>}
      />
      <Card>
        {units.isError ? (
          <ApiErrorAlert error={ApiError.from(units.error)} className="m-4" />
        ) : (
          <DataTable
            columns={columns}
            rows={units.data}
            rowKey={(u) => u.id}
            loading={units.isFetching}
            caption="Units"
            empty={<EmptyState icon={<Ruler className="size-5" />} title="No units" />}
          />
        )}
      </Card>
      {editing !== null && <UnitModal unit={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function UnitModal({ unit, onClose }: { unit: Unit | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm({
    initialValues: { name: unit?.name ?? '', abbreviation: unit?.abbreviation ?? '', allows_decimal: unit?.allows_decimal ?? false },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter a name.',
      abbreviation: v.abbreviation.trim() ? undefined : 'Enter a short name, e.g. pc.',
    }),
    onSubmit: async (v) => {
      await catalogApi.saveUnit({ name: v.name.trim(), abbreviation: v.abbreviation.trim(), allows_decimal: v.allows_decimal }, unit?.id)
      await queryClient.invalidateQueries({ queryKey: catalogKeys.units })
      toast.success('Unit saved')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={unit ? 'Edit unit' : 'Add unit'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="unit-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="unit-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <div className="grid grid-cols-[1fr_7rem] gap-4">
          <FormField label="Name" required error={form.errors.name}>
            <Input {...form.field('name')} placeholder="e.g. Sachet" data-autofocus />
          </FormField>
          <FormField label="Short" required error={form.errors.abbreviation}>
            <Input {...form.field('abbreviation')} maxLength={10} placeholder="sct" />
          </FormField>
        </div>
        <Checkbox {...form.checkbox('allows_decimal')} label="Allow decimal quantities" description="For weighed or measured items (kg, meter, liter)." />
      </form>
    </Modal>
  )
}
