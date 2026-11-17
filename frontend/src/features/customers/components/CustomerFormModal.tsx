import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Checkbox, FormField, Input, Modal, Select, Textarea, useToast } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { customerApi, customerKeys, type CustomerInput } from '../api'
import type { Customer, ScPwdType } from '../types'

function initial(c: Customer | null): CustomerInput {
  return {
    name: c?.name ?? '',
    code: c?.code ?? '',
    phone: c?.phone ?? '',
    email: c?.email ?? '',
    tin: c?.tin ?? '',
    address: c?.address ?? '',
    birthdate: c?.birthdate ?? '',
    customer_group_id: c?.group?.id ?? '',
    sc_pwd_id_type: c?.sc_pwd?.type ?? '',
    sc_pwd_id_no: c?.sc_pwd?.id_no ?? '',
    allow_credit: c?.allow_credit ?? false,
    credit_limit: c && c.credit_limit !== '0.00' ? c.credit_limit : '',
    notes: c?.notes ?? '',
  }
}

/** Add / edit a customer. Credit fields appear only for credit managers on plans with credit sales. */
export function CustomerFormModal({ customer, onClose, onSaved }: { customer: Customer | null; onClose: () => void; onSaved?: (c: Customer) => void }) {
  const { can } = useAuth()
  const account = useAccount()
  const queryClient = useQueryClient()
  const toast = useToast()
  const canCredit = can('customers.credit_manage') && Boolean(account.subscription?.plan.features?.credit_sales)
  const groups = useQuery({ queryKey: customerKeys.groups, queryFn: customerApi.groups, staleTime: 60_000 })

  const form = useForm<CustomerInput>({
    initialValues: initial(customer),
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter the customer name.',
      sc_pwd_id_no: v.sc_pwd_id_type && !v.sc_pwd_id_no.trim() ? 'Enter the ID number.' : undefined,
      credit_limit: v.allow_credit && v.credit_limit.trim() && !/^\d+(\.\d{1,2})?$/.test(v.credit_limit.trim()) ? 'Enter an amount, e.g. 2000' : undefined,
    }),
    onSubmit: async (v) => {
      const saved = await customerApi.save(v, canCredit, customer?.id)
      queryClient.setQueryData(customerKeys.detail(saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: customerKeys.all, predicate: (q) => q.queryKey[1] !== 'detail' })
      toast.success(customer ? 'Customer saved' : 'Customer added')
      onSaved?.(saved)
      onClose()
    },
  })
  const err = (k: string) => form.errors[k]
  const v = form.values

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      dismissible={!form.submitting}
      title={customer ? `Edit ${customer.name}` : 'Add customer'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="customer-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="customer-form" onSubmit={form.handleSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
        <ApiErrorAlert error={form.formError} className="sm:col-span-2" />
        <FormField label="Name" required error={err('name')} className="sm:col-span-2">
          <Input {...form.field('name')} maxLength={150} data-autofocus placeholder="e.g. Aling Nena" />
        </FormField>
        <FormField label="Mobile number" error={err('phone')} hint="Optional. Used to find the customer at the counter.">
          <Input {...form.field('phone')} inputMode="tel" placeholder="0917 123 4567" />
        </FormField>
        <FormField label="Group" error={err('customer_group_id')}>
          <Select value={String(v.customer_group_id)} onChange={(e) => form.setValue('customer_group_id', e.target.value ? Number(e.target.value) : '')}>
            <option value="">No group (retail prices)</option>
            {(groups.data ?? []).map((g) => (
              <option key={g.id} value={g.id}>
                {g.name}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="E-mail" error={err('email')}>
          <Input {...form.field('email')} type="email" maxLength={150} />
        </FormField>
        <FormField label="Customer code" error={err('code')}>
          <Input {...form.field('code')} maxLength={30} />
        </FormField>
        <FormField label="Birthdate" error={err('birthdate')}>
          <Input type="date" {...form.field('birthdate')} />
        </FormField>
        <FormField label="TIN" error={err('tin')}>
          <Input {...form.field('tin')} placeholder="123-456-789" />
        </FormField>
        <FormField label="Address" error={err('address')} className="sm:col-span-2">
          <Input {...form.field('address')} maxLength={255} />
        </FormField>

        <fieldset className="grid gap-4 rounded-md bg-canvas p-4 sm:col-span-2 sm:grid-cols-2">
          <legend className="sr-only">Senior Citizen / PWD</legend>
          <FormField label="Senior Citizen / PWD" error={err('sc_pwd_id_type')} hint="For the 20% statutory discount at the POS.">
            <Select value={v.sc_pwd_id_type} onChange={(e) => form.setValue('sc_pwd_id_type', e.target.value as ScPwdType | '')}>
              <option value="">Not applicable</option>
              <option value="senior">Senior Citizen (OSCA ID)</option>
              <option value="pwd">PWD (PWD ID)</option>
            </Select>
          </FormField>
          {v.sc_pwd_id_type && (
            <FormField label="ID number" required error={err('sc_pwd_id_no')} hint="Stored encrypted; others see only the last 4 characters.">
              <Input {...form.field('sc_pwd_id_no')} maxLength={40} autoComplete="off" />
            </FormField>
          )}
        </fieldset>

        {canCredit && (
          <fieldset className="grid gap-4 rounded-md bg-canvas p-4 sm:col-span-2 sm:grid-cols-2">
            <legend className="sr-only">Credit</legend>
            <div className="sm:col-span-2">
              <Checkbox {...form.checkbox('allow_credit')} label="Allow credit (utang)" description="The customer may pay later, up to the credit limit." />
            </div>
            {v.allow_credit && (
              <FormField label="Credit limit" error={err('credit_limit')} hint="0 or blank = no credit beyond what is already owed.">
                <Input {...form.field('credit_limit')} inputMode="decimal" leftAddon="₱" />
              </FormField>
            )}
          </fieldset>
        )}

        <FormField label="Notes" error={err('notes')} className="sm:col-span-2">
          <Textarea {...form.field('notes')} rows={2} maxLength={2000} />
        </FormField>
      </form>
    </Modal>
  )
}
