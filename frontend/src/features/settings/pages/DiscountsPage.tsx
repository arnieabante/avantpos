import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, Checkbox, DataTable, FormField, Input, Modal, PageHeader, Select, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatMoney, formatPercent, humanize } from '@/lib/format'
import { settingsApi, settingsKeys, type DiscountInput } from '../api'
import type { DiscountPreset } from '../types'

const STATUTORY_LABEL: Record<string, string> = { senior: 'Senior Citizen (RA 9994)', pwd: 'PWD (RA 10754)', naac: 'NAAC', solo_parent: 'Solo Parent' }

function discountValue(d: Pick<DiscountPreset, 'type' | 'value'>): string {
  return d.type === 'percent' ? formatPercent(d.value) : formatMoney(d.value)
}

export default function DiscountsPage() {
  const { can } = useAuth()
  const canManage = can('settings.discounts')
  const [editing, setEditing] = useState<DiscountPreset | 'new' | null>(null)
  const discounts = useQuery({ queryKey: settingsKeys.discounts, queryFn: settingsApi.discounts })

  const columns: Column<DiscountPreset>[] = [
    {
      key: 'name',
      header: 'Name',
      render: (d) => (
        <div>
          <p className="flex items-center gap-1.5 font-medium text-ink">
            {d.name}
            {d.statutory && <Lock className="size-3.5 text-ink-subtle" aria-label="Statutory" />}
          </p>
          {d.statutory && <p className="text-xs text-ink-subtle">{STATUTORY_LABEL[d.statutory] ?? humanize(d.statutory)}</p>}
        </div>
      ),
    },
    { key: 'value', header: 'Discount', align: 'right', render: (d) => discountValue(d) },
    { key: 'scope', header: 'Applies to', className: 'hidden sm:table-cell', render: (d) => (d.scope === 'line' ? 'Item' : 'Whole sale') },
    { key: 'cap', header: 'Max amount', align: 'right', className: 'hidden md:table-cell', render: (d) => (d.max_amount ? formatMoney(d.max_amount) : '—') },
    {
      key: 'status',
      header: 'Status',
      render: (d) => (
        <div className="flex flex-wrap gap-1">
          <Badge tone={d.is_active ? 'success' : 'neutral'}>{d.is_active ? 'Active' : 'Inactive'}</Badge>
          {d.requires_permission && <Badge tone="warning">Needs approval</Badge>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (d) =>
        canManage && (
          <Button size="sm" variant="ghost" onClick={() => setEditing(d)}>
            Edit
          </Button>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Discounts"
        description="Ready-made discounts cashiers can apply at the POS."
        actions={canManage && <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add discount</Button>}
      />
      <Alert tone="info" className="mb-4">
        Senior Citizen and PWD discounts are required by law (20%, VAT-exempt on qualified purchases) and cannot be changed or switched off. The POS applies
        the VAT exemption and records the ID number automatically.
      </Alert>
      <Card>
        {discounts.isError ? (
          <ApiErrorAlert error={ApiError.from(discounts.error)} className="m-4" />
        ) : (
          <DataTable columns={columns} rows={discounts.data} rowKey={(d) => d.id} loading={discounts.isFetching} caption="Discounts" />
        )}
      </Card>
      {editing !== null && <DiscountModal discount={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function DiscountModal({ discount, onClose }: { discount: DiscountPreset | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const locked = Boolean(discount?.statutory)

  const form = useForm<DiscountInput>({
    initialValues: {
      name: discount?.name ?? '',
      type: discount?.type ?? 'percent',
      value: discount ? (discount.type === 'percent' ? discount.value.replace(/\.?0+$/, '') : discount.value) : '',
      scope: discount?.scope ?? 'line',
      requires_permission: discount?.requires_permission ?? false,
      max_amount: discount?.max_amount ?? '',
      is_active: discount?.is_active ?? true,
    },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter a name.',
      value: /^\d+(\.\d{1,4})?$/.test(v.value) && Number(v.value) > 0 ? undefined : 'Enter a positive number.',
      max_amount: v.max_amount === '' || /^\d+(\.\d{1,2})?$/.test(v.max_amount) ? undefined : 'Enter an amount in pesos, e.g. 200.',
    }),
    onSubmit: async (values) => {
      if (discount) await settingsApi.updateDiscount(discount.id, values)
      else await settingsApi.createDiscount(values)
      await queryClient.invalidateQueries({ queryKey: settingsKeys.discounts })
      toast.success('Discount saved')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={discount ? `Edit ${discount.name}` : 'Add discount'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="discount-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="discount-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        {locked && <Alert tone="warning">This is a statutory discount. Only its name and approval setting can be changed.</Alert>}
        <ApiErrorAlert error={form.formError} />
        <FormField label="Name" required error={form.errors.name}>
          <Input {...form.field('name')} />
        </FormField>
        <fieldset disabled={locked} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <FormField label="Type" error={form.errors.type}>
              <Select {...form.field('type')}>
                <option value="percent">Percentage</option>
                <option value="fixed">Fixed amount (₱)</option>
              </Select>
            </FormField>
            <FormField label="Value" required error={form.errors.value}>
              <Input
                {...form.field('value')}
                inputMode="decimal"
                leftAddon={form.values.type === 'fixed' ? '₱' : undefined}
                rightAddon={form.values.type === 'percent' ? <span className="pr-3 text-sm text-ink-subtle">%</span> : undefined}
              />
            </FormField>
          </div>
          <FormField label="Applies to" error={form.errors.scope}>
            <Select {...form.field('scope')}>
              <option value="line">One item</option>
              <option value="transaction">The whole sale</option>
            </Select>
          </FormField>
          <FormField label="Maximum discount" hint="Optional cap per use, in pesos" error={form.errors.max_amount}>
            <Input {...form.field('max_amount')} inputMode="decimal" leftAddon="₱" />
          </FormField>
          <Checkbox {...form.checkbox('is_active')} label="Active" />
        </fieldset>
        <Checkbox
          {...form.checkbox('requires_permission')}
          label="Requires manager approval"
          description="Cashiers need a supervisor PIN to apply it."
        />
      </form>
    </Modal>
  )
}
