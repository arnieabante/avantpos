import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { MoreVertical, Plus, Truck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Badge,
  Button,
  Card,
  Checkbox,
  DataTable,
  Dropdown,
  EmptyState,
  FilterBar,
  FormField,
  IconButton,
  Input,
  Modal,
  PageHeader,
  Pagination,
  SearchInput,
  Select,
  Textarea,
  useConfirm,
  useToast,
  type Column,
} from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatPhone } from '@/lib/format'
import { purchasingApi, purchasingKeys, type SupplierFilters, type SupplierInput } from '../api'
import type { Supplier } from '../types'

export default function SuppliersPage() {
  const { can } = useAuth()
  const confirm = useConfirm()
  const { run } = useAction()
  const canManage = can('suppliers.manage')
  const [filters, setFilters] = useState<SupplierFilters>({ page: 1 })
  const [editing, setEditing] = useState<Supplier | 'new' | null>(null)
  const setFilter = (patch: Partial<SupplierFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }))

  const list = useQuery({ queryKey: purchasingKeys.suppliers(filters), queryFn: () => purchasingApi.suppliers(filters), placeholderData: (prev) => prev })
  const refresh = { invalidate: [purchasingKeys.all] }

  async function remove(s: Supplier) {
    if (!(await confirm({ title: `Delete ${s.name}?`, description: 'Only suppliers you have never ordered from can be deleted.', confirmLabel: 'Delete', danger: true }))) return
    await run(`delete-${s.id}`, () => purchasingApi.deleteSupplier(s.id), { ...refresh, success: 'Supplier deleted' })
  }

  const columns: Column<Supplier>[] = [
    {
      key: 'name',
      header: 'Supplier',
      render: (s) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink">{s.name}</p>
          <p className="truncate text-xs text-ink-subtle">{[s.code, s.contact_person].filter(Boolean).join(' · ') || '—'}</p>
        </div>
      ),
    },
    { key: 'phone', header: 'Phone', className: 'hidden md:table-cell', render: (s) => (s.phone ? formatPhone(s.phone) : '—') },
    { key: 'terms', header: 'Terms', className: 'hidden lg:table-cell', render: (s) => (s.payment_terms_days > 0 ? `${s.payment_terms_days} days` : 'Cash / COD') },
    { key: 'open', header: 'Open orders', align: 'right', className: 'hidden sm:table-cell', render: (s) => s.open_orders_count ?? 0 },
    {
      key: 'status',
      header: 'Status',
      render: (s) => (
        <div className="flex flex-wrap gap-1">
          <Badge tone={s.is_active ? 'success' : 'neutral'}>{s.is_active ? 'Active' : 'Inactive'}</Badge>
          {s.vat_registered && <Badge tone="info">VAT</Badge>}
        </div>
      ),
    },
    ...(canManage
      ? [
          {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right' as const,
            render: (s: Supplier) => (
              <Dropdown
                trigger={(props) => (
                  <IconButton label={`Actions for ${s.name}`} size="sm" {...props}>
                    <MoreVertical className="size-4" />
                  </IconButton>
                )}
                items={[
                  { key: 'edit', label: 'Edit', onSelect: () => setEditing(s) },
                  s.is_active
                    ? { key: 'off', label: 'Deactivate', onSelect: () => void run(`off-${s.id}`, () => purchasingApi.supplierAction(s.id, 'deactivate'), { ...refresh, success: `${s.name} deactivated` }) }
                    : { key: 'on', label: 'Activate', onSelect: () => void run(`on-${s.id}`, () => purchasingApi.supplierAction(s.id, 'activate'), { ...refresh, success: `${s.name} activated` }) },
                  { key: 'delete', label: 'Delete', danger: true, onSelect: () => void remove(s) },
                ]}
              />
            ),
          },
        ]
      : []),
  ]

  return (
    <>
      <PageHeader
        title="Suppliers"
        description="Who you buy from. Shared by all branches."
        actions={
          canManage && (
            <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              Add supplier
            </Button>
          )
        }
      />
      <Card>
        <FilterBar>
          <SearchInput value={filters.search ?? ''} onChange={(search) => setFilter({ search })} placeholder="Name, code, contact or phone…" />
          <Select aria-label="Status" className="sm:w-40" value={filters.status ?? ''} onChange={(e) => setFilter({ status: (e.target.value || undefined) as SupplierFilters['status'] })}>
            <option value="">All suppliers</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </Select>
        </FilterBar>
        {list.isError ? (
          <ApiErrorAlert error={ApiError.from(list.error)} className="m-4" />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={list.data?.items}
              rowKey={(s) => s.id}
              loading={list.isFetching}
              onRowClick={canManage ? (s) => setEditing(s) : undefined}
              caption="Suppliers"
              empty={
                <EmptyState
                  icon={<Truck className="size-5" />}
                  title={filters.search ? 'No suppliers found' : 'No suppliers yet'}
                  description="Add the distributors and wholesalers you buy from."
                  action={canManage && !filters.search ? <Button onClick={() => setEditing('new')}>Add supplier</Button> : undefined}
                />
              }
            />
            <Pagination meta={list.data?.pagination} onPageChange={(page) => setFilter({ page })} disabled={list.isFetching} className="border-t border-line-soft" />
          </>
        )}
      </Card>
      {editing !== null && <SupplierModal supplier={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function SupplierModal({ supplier, onClose }: { supplier: Supplier | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm<SupplierInput>({
    initialValues: {
      code: supplier?.code ?? '',
      name: supplier?.name ?? '',
      contact_person: supplier?.contact_person ?? '',
      phone: supplier?.phone ?? '',
      email: supplier?.email ?? '',
      tin: supplier?.tin ?? '',
      vat_registered: supplier?.vat_registered ?? true,
      address: supplier?.address ?? '',
      payment_terms_days: String(supplier?.payment_terms_days ?? 0),
      notes: supplier?.notes ?? '',
    },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter the supplier name.',
      payment_terms_days: /^\d{0,3}$/.test(v.payment_terms_days.trim()) ? undefined : 'Enter a number of days.',
    }),
    onSubmit: async (v) => {
      await purchasingApi.saveSupplier(v, supplier?.id)
      await queryClient.invalidateQueries({ queryKey: purchasingKeys.all })
      toast.success(supplier ? 'Supplier saved' : 'Supplier added')
      onClose()
    },
  })
  const err = (k: string) => form.errors[k]

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      dismissible={!form.submitting}
      title={supplier ? `Edit ${supplier.name}` : 'Add supplier'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="supplier-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="supplier-form" onSubmit={form.handleSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
        <ApiErrorAlert error={form.formError} className="sm:col-span-2" />
        <FormField label="Name" required error={err('name')} className="sm:col-span-2">
          <Input {...form.field('name')} maxLength={150} data-autofocus placeholder="e.g. Coca-Cola Beverages Philippines" />
        </FormField>
        <FormField label="Code" error={err('code')} hint="Optional short code.">
          <Input {...form.field('code')} maxLength={30} />
        </FormField>
        <FormField label="Contact person" error={err('contact_person')}>
          <Input {...form.field('contact_person')} maxLength={120} />
        </FormField>
        <FormField label="Phone" error={err('phone')}>
          <Input {...form.field('phone')} inputMode="tel" placeholder="0917 123 4567" />
        </FormField>
        <FormField label="E-mail" error={err('email')}>
          <Input {...form.field('email')} type="email" maxLength={150} />
        </FormField>
        <FormField label="TIN" error={err('tin')}>
          <Input {...form.field('tin')} placeholder="123-456-789" />
        </FormField>
        <FormField label="Payment terms (days)" error={err('payment_terms_days')} hint="0 = cash on delivery.">
          <Input {...form.field('payment_terms_days')} inputMode="numeric" />
        </FormField>
        <div className="sm:col-span-2">
          <Checkbox
            {...form.checkbox('vat_registered')}
            label="VAT-registered supplier"
            description="Their invoices include 12% VAT you can claim as input tax (when your business is VAT-registered)."
          />
        </div>
        <FormField label="Address" error={err('address')} className="sm:col-span-2">
          <Input {...form.field('address')} maxLength={255} />
        </FormField>
        <FormField label="Notes" error={err('notes')} className="sm:col-span-2">
          <Textarea {...form.field('notes')} rows={2} maxLength={2000} />
        </FormField>
      </form>
    </Modal>
  )
}
