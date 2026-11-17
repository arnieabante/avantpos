import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderTree, Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, Checkbox, DataTable, EmptyState, FormField, Input, Modal, PageHeader, Select, useConfirm, useToast, type Column } from '@/components/ui'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { catalogApi, catalogKeys } from '../api'
import type { Category } from '../types'

/** POS tile colours offered in the picker. */
const COLORS = ['#696CFF', '#03C3EC', '#71DD37', '#FFAB00', '#FF3E1D', '#8592A3', '#E83E8C', '#20C997']

/** Parents first, each followed by its subcategories. */
function ordered(categories: Category[]): Category[] {
  const parents = categories.filter((c) => c.parent_id === null)
  return parents.flatMap((p) => [p, ...categories.filter((c) => c.parent_id === p.id)])
}

export default function CategoriesPage() {
  const confirm = useConfirm()
  const { run } = useAction()
  const [editing, setEditing] = useState<Category | 'new' | null>(null)
  const categories = useQuery({ queryKey: catalogKeys.categories, queryFn: catalogApi.categories })

  async function remove(c: Category) {
    if (!(await confirm({ title: `Delete the category "${c.name}"?`, confirmLabel: 'Delete', danger: true }))) return
    await run(`delete-${c.id}`, () => catalogApi.deleteCategory(c.id), { invalidate: [catalogKeys.categories], success: 'Category deleted' })
  }

  const columns: Column<Category>[] = [
    {
      key: 'name',
      header: 'Category',
      render: (c) => (
        <span className={c.parent_id ? 'flex items-center gap-2 pl-6' : 'flex items-center gap-2 font-medium text-ink'}>
          <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: c.color ?? '#d9dee3' }} aria-hidden="true" />
          {c.parent_id && <span className="text-ink-subtle">—</span>}
          {c.name}
        </span>
      ),
    },
    { key: 'products', header: 'Products', align: 'right', render: (c) => c.products_count ?? 0 },
    { key: 'status', header: 'Status', render: (c) => <Badge tone={c.is_active ? 'success' : 'neutral'}>{c.is_active ? 'Active' : 'Hidden'}</Badge> },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      render: (c) => (
        <div className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setEditing(c)}>
            Edit
          </Button>
          <Button size="sm" variant="ghost" onClick={() => remove(c)}>
            Delete
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Categories"
        description="Group products for the POS screen and reports. Up to two levels, e.g. Drinks › Softdrinks."
        actions={<Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add category</Button>}
      />
      <Card>
        {categories.isError ? (
          <ApiErrorAlert error={ApiError.from(categories.error)} className="m-4" />
        ) : (
          <DataTable
            columns={columns}
            rows={categories.data ? ordered(categories.data) : undefined}
            rowKey={(c) => c.id}
            loading={categories.isFetching}
            caption="Categories"
            empty={<EmptyState icon={<FolderTree className="size-5" />} title="No categories yet" description="Categories make products easier to find at the POS." />}
          />
        )}
      </Card>
      {editing !== null && <CategoryModal category={editing === 'new' ? null : editing} all={categories.data ?? []} onClose={() => setEditing(null)} />}
    </>
  )
}

function CategoryModal({ category, all, onClose }: { category: Category | null; all: Category[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const hasChildren = Boolean(category && all.some((c) => c.parent_id === category.id))

  const form = useForm({
    initialValues: {
      name: category?.name ?? '',
      parent_id: (category?.parent_id ?? '') as number | '',
      color: category?.color ?? COLORS[0]!,
      sort_order: String(category?.sort_order ?? 0),
      is_active: category?.is_active ?? true,
    },
    validate: (v) => ({ name: v.name.trim() ? undefined : 'Enter a name.' }),
    onSubmit: async (v) => {
      await catalogApi.saveCategory(
        { name: v.name.trim(), parent_id: v.parent_id === '' ? null : v.parent_id, color: v.color || null, sort_order: Number(v.sort_order) || 0, is_active: v.is_active },
        category?.id,
      )
      await queryClient.invalidateQueries({ queryKey: catalogKeys.categories })
      toast.success('Category saved')
      onClose()
    },
  })

  const parents = all.filter((c) => c.parent_id === null && c.id !== category?.id)

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!form.submitting}
      title={category ? 'Edit category' : 'Add category'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="category-form" loading={form.submitting}>
            Save
          </Button>
        </>
      }
    >
      <form id="category-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        <FormField label="Name" required error={form.errors.name}>
          <Input {...form.field('name')} data-autofocus />
        </FormField>
        <FormField label="Parent category" hint={hasChildren ? 'Has subcategories, so it stays top-level.' : 'Optional'} error={form.errors.parent_id}>
          <Select value={String(form.values.parent_id)} disabled={hasChildren} onChange={(e) => form.setValue('parent_id', e.target.value ? Number(e.target.value) : '')}>
            <option value="">None (top-level)</option>
            {parents.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
        </FormField>
        <fieldset>
          <legend className="mb-1.5 text-[0.8125rem] font-medium tracking-[0.02em] text-ink uppercase">POS tile colour</legend>
          <div className="flex flex-wrap gap-2">
            {COLORS.map((color) => (
              <button
                key={color}
                type="button"
                onClick={() => form.setValue('color', color)}
                aria-label={`Colour ${color}`}
                aria-pressed={form.values.color === color}
                className="size-8 rounded-md ring-offset-2 aria-pressed:ring-2 aria-pressed:ring-ink-muted"
                style={{ backgroundColor: color }}
              />
            ))}
          </div>
        </fieldset>
        <div className="grid grid-cols-2 gap-4">
          <FormField label="Display order" error={form.errors.sort_order}>
            <Input {...form.field('sort_order')} type="number" min={0} />
          </FormField>
          <Checkbox className="self-end pb-2" {...form.checkbox('is_active')} label="Show at the POS" />
        </div>
      </form>
    </Modal>
  )
}
