import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, Card, Checkbox, DataTable, FormField, Input, Modal, PageHeader, Select, useToast, type Column } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { settingsApi, settingsKeys, type PaymentMethodInput } from '../api'
import { PAYMENT_TYPE_LABELS, type PaymentMethod, type PaymentType } from '../types'

export default function PaymentMethodsPage() {
  const { can } = useAuth()
  const canManage = can('settings.payment_methods')
  const [editing, setEditing] = useState<PaymentMethod | 'new' | null>(null)
  const methods = useQuery({ queryKey: settingsKeys.paymentMethods, queryFn: settingsApi.paymentMethods })

  const columns: Column<PaymentMethod>[] = [
    { key: 'order', header: '#', className: 'w-12 text-ink-subtle', render: (m) => m.sort_order },
    { key: 'name', header: 'Name', render: (m) => <span className="font-medium text-ink">{m.name}</span> },
    { key: 'type', header: 'Type', render: (m) => PAYMENT_TYPE_LABELS[m.type] },
    { key: 'reference', header: 'Reference no.', className: 'hidden sm:table-cell', render: (m) => (m.requires_reference ? 'Required' : 'Not needed') },
    { key: 'status', header: 'Status', render: (m) => <Badge tone={m.is_active ? 'success' : 'neutral'}>{m.is_active ? 'Active' : 'Inactive'}</Badge> },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (m) =>
        canManage && (
          <Button size="sm" variant="ghost" onClick={() => setEditing(m)}>
            Edit
          </Button>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Payment methods"
        description="Shown at checkout in this order."
        actions={canManage && <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add method</Button>}
      />
      <Alert tone="info" className="mb-4">
        GCash, Maya, bank and card payments are recorded with the customer's reference number and are not marked as received automatically. Online payment
        verification will be available when a payment provider is connected.
      </Alert>
      <Card>
        {methods.isError ? (
          <ApiErrorAlert error={ApiError.from(methods.error)} className="m-4" />
        ) : (
          <DataTable columns={columns} rows={methods.data} rowKey={(m) => m.id} loading={methods.isFetching} caption="Payment methods" />
        )}
      </Card>
      {editing !== null && <PaymentMethodModal method={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function PaymentMethodModal({ method, onClose }: { method: PaymentMethod | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm<PaymentMethodInput & { sort_order: number }>({
    initialValues: {
      ...(method ? {} : { type: 'gcash' as PaymentType }),
      name: method?.name ?? '',
      requires_reference: method?.requires_reference ?? true,
      is_active: method?.is_active ?? true,
      sort_order: method?.sort_order ?? 0,
    },
    validate: (v) => ({ name: v.name.trim() ? undefined : 'Enter a name.' }),
    onSubmit: async (values) => {
      const payload = method ? values : { ...values, sort_order: undefined }
      if (method) await settingsApi.updatePaymentMethod(method.id, payload)
      else await settingsApi.createPaymentMethod(payload)
      await queryClient.invalidateQueries({ queryKey: settingsKeys.paymentMethods })
      toast.success('Payment method saved')
      onClose()
    },
  })

  const type = method?.type ?? form.values.type ?? 'other'
  const referenceLocked = type === 'cash' || type === 'credit'

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={method ? `Edit ${method.name}` : 'Add payment method'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="payment-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="payment-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Type" hint={method ? 'The type cannot be changed.' : undefined} error={form.errors.type}>
          <Select value={type} disabled={Boolean(method)} onChange={(e) => form.setValue('type', e.target.value as PaymentType)}>
            {Object.entries(PAYMENT_TYPE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Name" required hint="As shown to cashiers, e.g. GCash (Store)" error={form.errors.name}>
          <Input {...form.field('name')} />
        </FormField>
        {method && (
          <FormField label="Display order" error={form.errors.sort_order}>
            <Input type="number" min={0} max={999} value={form.values.sort_order} onChange={(e) => form.setValue('sort_order', Number(e.target.value))} className="w-28" />
          </FormField>
        )}
        <Checkbox
          {...form.checkbox('requires_reference')}
          checked={referenceLocked ? false : form.values.requires_reference}
          disabled={referenceLocked}
          label="Require a reference number"
          description={referenceLocked ? 'Not applicable to this type.' : 'Cashiers must enter the transaction / approval number.'}
        />
        <Checkbox {...form.checkbox('is_active')} label="Active" />
      </form>
    </Modal>
  )
}
