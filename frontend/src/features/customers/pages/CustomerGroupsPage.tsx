import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, UsersRound } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, DataTable, EmptyState, FormField, Input, Modal, PageHeader, Select, useConfirm, useToast, type Column } from '@/components/ui'
import { trimDecimal } from '@/features/catalog/productForm'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatNumber } from '@/lib/format'
import { customerApi, customerKeys } from '../api'
import type { CustomerGroup, PriceTier } from '../types'

export default function CustomerGroupsPage() {
  const confirm = useConfirm()
  const { run } = useAction()
  const [editing, setEditing] = useState<CustomerGroup | 'new' | null>(null)
  const groups = useQuery({ queryKey: customerKeys.groups, queryFn: customerApi.groups })

  async function remove(g: CustomerGroup) {
    if (!(await confirm({ title: `Delete the group "${g.name}"?`, confirmLabel: 'Delete', danger: true }))) return
    await run(`delete-${g.id}`, () => customerApi.deleteGroup(g.id), { invalidate: [customerKeys.all], success: 'Group deleted' })
  }

  const columns: Column<CustomerGroup>[] = [
    { key: 'name', header: 'Group', render: (g) => <span className="font-medium text-ink">{g.name}</span> },
    { key: 'tier', header: 'Prices', render: (g) => <Badge tone={g.price_tier === 'wholesale' ? 'info' : 'neutral'}>{g.price_tier === 'wholesale' ? 'Wholesale' : 'Retail'}</Badge> },
    { key: 'discount', header: 'Discount', align: 'right', render: (g) => (/^0*(\.0*)?$/.test(g.discount_percent) ? '—' : `${formatNumber(g.discount_percent)}%`) },
    { key: 'customers', header: 'Customers', align: 'right', render: (g) => g.customers_count },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (g) => (
        <div className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setEditing(g)}>
            Edit
          </Button>
          <Button size="sm" variant="ghost" onClick={() => remove(g)}>
            Delete
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Customer groups"
        description="Give resellers wholesale prices, or a standard discount to a group (applied at the POS)."
        actions={<Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add group</Button>}
      />
      <Card>
        {groups.isError ? (
          <ApiErrorAlert error={ApiError.from(groups.error)} className="m-4" />
        ) : (
          <DataTable
            columns={columns}
            rows={groups.data}
            rowKey={(g) => g.id}
            loading={groups.isFetching}
            caption="Customer groups"
            empty={<EmptyState icon={<UsersRound className="size-5" />} title="No groups yet" description="For example “Resellers” with wholesale prices, or “Suki” with 5% off." />}
          />
        )}
      </Card>
      {editing !== null && <GroupModal group={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function GroupModal({ group, onClose }: { group: CustomerGroup | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm({
    initialValues: { name: group?.name ?? '', price_tier: (group?.price_tier ?? 'retail') as PriceTier, discount_percent: group ? trimDecimal(group.discount_percent) || '0' : '0' },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter a name.',
      discount_percent: /^\d{1,3}(\.\d{1,2})?$/.test(v.discount_percent.trim()) ? undefined : 'Enter a percentage from 0 to 100.',
    }),
    onSubmit: async (v) => {
      await customerApi.saveGroup({ name: v.name.trim(), price_tier: v.price_tier, discount_percent: v.discount_percent.trim() }, group?.id)
      await queryClient.invalidateQueries({ queryKey: customerKeys.all })
      toast.success('Group saved')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={group ? 'Edit group' : 'Add group'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="group-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="group-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Name" required error={form.errors.name}>
          <Input {...form.field('name')} maxLength={80} data-autofocus />
        </FormField>
        <FormField label="Prices" error={form.errors.price_tier}>
          <Select {...form.field('price_tier')}>
            <option value="retail">Retail prices</option>
            <option value="wholesale">Wholesale prices</option>
          </Select>
        </FormField>
        <FormField label="Standard discount (%)" error={form.errors.discount_percent}>
          <Input {...form.field('discount_percent')} inputMode="decimal" />
        </FormField>
      </form>
    </Modal>
  )
}
