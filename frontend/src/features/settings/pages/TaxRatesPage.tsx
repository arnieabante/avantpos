import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, Checkbox, DataTable, FormField, Input, Modal, PageHeader, Select, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatPercent } from '@/lib/format'
import { settingsApi, settingsKeys, type TaxRateInput } from '../api'
import type { TaxRate } from '../types'

const TYPE_LABEL: Record<TaxRate['type'], string> = { vat: 'VAT', percentage_tax: 'Percentage tax' }

export default function TaxRatesPage() {
  const { can } = useAuth()
  const canManage = can('settings.tax')
  const { run, isPending } = useAction()
  const [editing, setEditing] = useState<TaxRate | 'new' | null>(null)
  const rates = useQuery({ queryKey: settingsKeys.taxRates, queryFn: settingsApi.taxRates })

  const columns: Column<TaxRate>[] = [
    { key: 'name', header: 'Name', render: (r) => <span className="font-medium text-ink">{r.name}</span> },
    { key: 'type', header: 'Type', render: (r) => TYPE_LABEL[r.type] },
    { key: 'rate', header: 'Rate', align: 'right', render: (r) => formatPercent(r.rate) },
    {
      key: 'status',
      header: 'Status',
      render: (r) => (
        <div className="flex flex-wrap gap-1">
          {r.is_default && <Badge tone="brand">Default</Badge>}
          <Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Badge>
        </div>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (r) =>
        canManage && (
          <div className="flex justify-end gap-1">
            {!r.is_default && r.is_active && (
              <Button
                size="sm"
                variant="ghost"
                loading={isPending(`default-${r.id}`)}
                onClick={() => run(`default-${r.id}`, () => settingsApi.makeDefaultTaxRate(r.id), { invalidate: [settingsKeys.taxRates], success: `${r.name} is now the default` })}
              >
                Make default
              </Button>
            )}
            <Button size="sm" variant="ghost" onClick={() => setEditing(r)}>
              Edit
            </Button>
          </div>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Taxes"
        description="The default rate applies to vatable items at the POS."
        actions={canManage && <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add tax rate</Button>}
      />
      <Alert tone="info" className="mb-4">
        VAT-registered businesses charge 12% VAT; non-VAT businesses may be subject to 3% percentage tax. Confirm your tax type with your BIR Certificate of
        Registration or your accountant.
      </Alert>
      <Card>
        {rates.isError ? (
          <ApiErrorAlert error={ApiError.from(rates.error)} className="m-4" />
        ) : (
          <DataTable columns={columns} rows={rates.data} rowKey={(r) => r.id} loading={rates.isFetching} caption="Tax rates" />
        )}
      </Card>
      {editing !== null && <TaxRateModal rate={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function TaxRateModal({ rate, onClose }: { rate: TaxRate | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm<TaxRateInput>({
    initialValues: { name: rate?.name ?? '', type: rate?.type ?? 'vat', rate: rate ? rate.rate.replace(/\.?0+$/, '') : '', is_active: rate?.is_active ?? true },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter a name.',
      rate: /^\d{1,3}(\.\d{1,4})?$/.test(v.rate) ? undefined : 'Enter a percentage, e.g. 12.',
    }),
    onSubmit: async (values) => {
      if (rate) await settingsApi.updateTaxRate(rate.id, values)
      else await settingsApi.createTaxRate(values)
      await queryClient.invalidateQueries({ queryKey: settingsKeys.taxRates })
      toast.success('Tax rate saved')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={rate ? 'Edit tax rate' : 'Add tax rate'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="tax-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="tax-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Name" required error={form.errors.name}>
          <Input {...form.field('name')} />
        </FormField>
        <div className="grid grid-cols-2 gap-4">
          <FormField label="Type" error={form.errors.type}>
            <Select {...form.field('type')}>
              <option value="vat">VAT</option>
              <option value="percentage_tax">Percentage tax</option>
            </Select>
          </FormField>
          <FormField label="Rate" required error={form.errors.rate}>
            <Input {...form.field('rate')} inputMode="decimal" rightAddon={<span className="pr-3 text-sm text-ink-subtle">%</span>} />
          </FormField>
        </div>
        {rate && !rate.is_default && <Checkbox {...form.checkbox('is_active')} label="Active" />}
      </form>
    </Modal>
  )
}
