import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Tags } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Card, DataTable, EmptyState, FormField, Input, Modal, PageHeader, useConfirm, useToast, type Column } from '@/components/ui'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { catalogApi, catalogKeys } from '../api'
import type { Brand } from '../types'

export default function BrandsPage() {
  const confirm = useConfirm()
  const { run } = useAction()
  const [editing, setEditing] = useState<Brand | 'new' | null>(null)
  const brands = useQuery({ queryKey: catalogKeys.brands, queryFn: catalogApi.brands })

  async function remove(b: Brand) {
    if (!(await confirm({ title: `Delete the brand "${b.name}"?`, confirmLabel: 'Delete', danger: true }))) return
    await run(`delete-${b.id}`, () => catalogApi.deleteBrand(b.id), { invalidate: [catalogKeys.brands], success: 'Brand deleted' })
  }

  const columns: Column<Brand>[] = [
    { key: 'name', header: 'Brand', render: (b) => <span className="font-medium text-ink">{b.name}</span> },
    { key: 'products', header: 'Products', align: 'right', render: (b) => b.products_count ?? 0 },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (b) => (
        <div className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setEditing(b)}>
            Edit
          </Button>
          <Button size="sm" variant="ghost" onClick={() => remove(b)}>
            Delete
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title="Brands" actions={<Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add brand</Button>} />
      <Card>
        {brands.isError ? (
          <ApiErrorAlert error={ApiError.from(brands.error)} className="m-4" />
        ) : (
          <DataTable
            columns={columns}
            rows={brands.data}
            rowKey={(b) => b.id}
            loading={brands.isFetching}
            caption="Brands"
            empty={<EmptyState icon={<Tags className="size-5" />} title="No brands yet" />}
          />
        )}
      </Card>
      {editing !== null && <BrandModal brand={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function BrandModal({ brand, onClose }: { brand: Brand | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm({
    initialValues: { name: brand?.name ?? '' },
    validate: (v) => ({ name: v.name.trim() ? undefined : 'Enter a name.' }),
    onSubmit: async (v) => {
      await catalogApi.saveBrand(v.name.trim(), brand?.id)
      await queryClient.invalidateQueries({ queryKey: catalogKeys.brands })
      toast.success('Brand saved')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={brand ? 'Edit brand' : 'Add brand'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="brand-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="brand-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Name" required error={form.errors.name}>
          <Input {...form.field('name')} data-autofocus />
        </FormField>
      </form>
    </Modal>
  )
}
