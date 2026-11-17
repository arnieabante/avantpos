import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Receipt } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, CardBody, Checkbox, EmptyState, FormField, Input, Modal, PageHeader, PageLoader, Select, Textarea, useConfirm, useToast } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { useAction } from '@/hooks/useAction'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { cn } from '@/lib/utils'
import { settingsApi, settingsKeys, type ReceiptTemplateInput } from '../api'
import { RECEIPT_LAYOUT_LABELS, type ReceiptLayoutKey, type ReceiptTemplate } from '../types'

const PAPER_LABEL: Record<ReceiptTemplate['paper'], string> = { '58mm': '58 mm thermal', '80mm': '80 mm thermal', a4: 'A4 invoice' }

const DEFAULT_LAYOUT: Record<ReceiptLayoutKey, boolean> = {
  show_business_address: true,
  show_tin: true,
  show_branch: true,
  show_cashier: true,
  show_customer: true,
  show_item_sku: false,
  show_tax_breakdown: true,
  show_payment_details: true,
}

export default function ReceiptTemplatesPage() {
  const { can } = useAuth()
  const canManage = can('settings.receipt')
  const confirm = useConfirm()
  const { run, isPending } = useAction()
  const [editing, setEditing] = useState<ReceiptTemplate | 'new' | null>(null)
  const templates = useQuery({ queryKey: settingsKeys.receipts, queryFn: settingsApi.receiptTemplates })

  async function remove(t: ReceiptTemplate) {
    if (!(await confirm({ title: `Delete "${t.name}"?`, confirmLabel: 'Delete', danger: true }))) return
    await run(`delete-${t.id}`, () => settingsApi.deleteReceiptTemplate(t.id), { invalidate: [settingsKeys.receipts], success: 'Template deleted' })
  }

  if (templates.isPending) return <PageLoader />

  return (
    <>
      <PageHeader
        title="Receipts"
        description="Layouts for thermal receipts and A4 invoices. The default is used by every terminal."
        actions={canManage && <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add template</Button>}
      />
      {templates.isError ? (
        <ApiErrorAlert error={ApiError.from(templates.error)} />
      ) : templates.data.length === 0 ? (
        <EmptyState icon={<Receipt className="size-5" />} title="No receipt templates" />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {templates.data.map((t) => (
            <Card key={t.id} className="flex flex-col">
              <CardBody className="flex-1">
                <div className="mb-3 flex items-start justify-between gap-2">
                  <div>
                    <p className="font-medium text-ink">{t.name}</p>
                    <p className="text-sm text-ink-subtle">{PAPER_LABEL[t.paper]}</p>
                  </div>
                  {t.is_default && <Badge tone="brand">Default</Badge>}
                </div>
                <ReceiptPreview template={t} compact />
              </CardBody>
              {canManage && (
                <div className="flex flex-wrap justify-end gap-1 border-t border-line px-3 py-2">
                  {!t.is_default && (
                    <>
                      <Button
                        size="sm"
                        variant="ghost"
                        loading={isPending(`default-${t.id}`)}
                        onClick={() => run(`default-${t.id}`, () => settingsApi.makeDefaultReceiptTemplate(t.id), { invalidate: [settingsKeys.receipts], success: `${t.name} is now the default` })}
                      >
                        Make default
                      </Button>
                      <Button size="sm" variant="ghost" loading={isPending(`delete-${t.id}`)} onClick={() => remove(t)}>
                        Delete
                      </Button>
                    </>
                  )}
                  <Button size="sm" variant="secondary" onClick={() => setEditing(t)}>
                    Edit
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
      {editing !== null && <TemplateModal template={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  )
}

function TemplateModal({ template, onClose }: { template: ReceiptTemplate | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const form = useForm<ReceiptTemplateInput>({
    initialValues: {
      name: template?.name ?? '',
      paper: template?.paper ?? '80mm',
      header_text: template?.header_text ?? '',
      footer_text: template?.footer_text ?? 'Thank you for your purchase!',
      show_logo: template?.show_logo ?? true,
      layout: template?.layout ?? DEFAULT_LAYOUT,
    },
    validate: (v) => ({ name: v.name.trim() ? undefined : 'Enter a name.' }),
    onSubmit: async (values) => {
      if (template) await settingsApi.updateReceiptTemplate(template.id, values)
      else await settingsApi.createReceiptTemplate(values)
      await queryClient.invalidateQueries({ queryKey: settingsKeys.receipts })
      toast.success('Receipt template saved')
      onClose()
    },
  })

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      dismissible={!form.submitting}
      title={template ? `Edit ${template.name}` : 'Add receipt template'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={form.submitting}>
            Cancel
          </Button>
          <Button type="submit" form="receipt-form" loading={form.submitting}>
            Save template
          </Button>
        </>
      }
    >
      <div className="grid gap-6 md:grid-cols-2">
        <form id="receipt-form" onSubmit={form.handleSubmit} noValidate className="space-y-4">
          <ApiErrorAlert error={form.formError} />
          <div className="grid grid-cols-2 gap-4">
            <FormField label="Name" required error={form.errors.name}>
              <Input {...form.field('name')} />
            </FormField>
            <FormField label="Paper" error={form.errors.paper}>
              <Select {...form.field('paper')}>
                {Object.entries(PAPER_LABEL).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </Select>
            </FormField>
          </div>
          <FormField label="Header message" hint="Printed under the business details" error={form.errors.header_text}>
            <Textarea {...form.field('header_text')} rows={2} maxLength={500} />
          </FormField>
          <FormField label="Footer message" hint='e.g. "Salamat po! Balik po kayo."' error={form.errors.footer_text}>
            <Textarea {...form.field('footer_text')} rows={2} maxLength={500} />
          </FormField>
          <fieldset className="space-y-2">
            <legend className="mb-2 text-sm font-medium text-ink">Show on receipt</legend>
            <Checkbox {...form.checkbox('show_logo')} label="Logo" />
            {(Object.keys(RECEIPT_LAYOUT_LABELS) as ReceiptLayoutKey[]).map((key) => (
              <Checkbox
                key={key}
                label={RECEIPT_LAYOUT_LABELS[key]}
                checked={form.values.layout[key]}
                onChange={(e) => form.setValue('layout', { ...form.values.layout, [key]: e.target.checked })}
              />
            ))}
          </fieldset>
        </form>
        <div>
          <p className="mb-2 text-sm font-medium text-ink">Preview</p>
          <div className="rounded-lg bg-slate-100 p-4">
            <ReceiptPreview template={{ ...form.values, header_text: form.values.header_text || null, footer_text: form.values.footer_text || null }} />
          </div>
        </div>
      </div>
    </Modal>
  )
}

type PreviewTemplate = Pick<ReceiptTemplate, 'paper' | 'header_text' | 'footer_text' | 'show_logo' | 'layout'>

/**
 * Visual approximation of the printed receipt using the business details from the session.
 * Sample items only illustrate the layout; real receipts are rendered from sales (Phase 5).
 */
function ReceiptPreview({ template, compact = false }: { template: PreviewTemplate; compact?: boolean }) {
  const { tenant } = useAccount()
  const { activeBranch } = useAuth()
  const l = template.layout
  const width = template.paper === '58mm' ? 'max-w-[220px]' : template.paper === '80mm' ? 'max-w-[300px]' : 'max-w-[420px]'

  return (
    <div className={cn('mx-auto bg-white font-mono text-[11px] leading-snug text-slate-800 shadow-sm', width, compact ? 'p-3' : 'p-4')} aria-label="Receipt preview">
      <div className="text-center">
        {template.show_logo && tenant.logo_url && <img src={tenant.logo_url} alt="" className="mx-auto mb-1 h-10 object-contain" />}
        <p className="font-bold">{tenant.name}</p>
        {l.show_business_address && <p>Business address</p>}
        {l.show_tin && <p>{tenant.vat_registered ? 'VAT REG TIN' : 'NON-VAT REG TIN'}: 000-000-000-00000</p>}
        {l.show_branch && activeBranch && <p>{activeBranch.name}</p>}
        {template.header_text && <p className="mt-1 whitespace-pre-line">{template.header_text}</p>}
      </div>
      <Divider />
      <p>SI No.: 000000001</p>
      {l.show_cashier && <p>Cashier: Juan</p>}
      {l.show_customer && <p>Customer: Walk-in</p>}
      <Divider />
      <Line left={`Sample item${l.show_item_sku ? ' (SKU-001)' : ''}`} right="2 x 50.00" />
      <Line left="" right="100.00" />
      <Divider />
      <Line left="TOTAL" right="100.00" bold />
      {l.show_tax_breakdown && tenant.vat_registered && (
        <>
          <Line left="VATable sales" right="89.29" />
          <Line left="VAT (12%)" right="10.71" />
        </>
      )}
      {l.show_payment_details && (
        <>
          <Line left="Cash" right="200.00" />
          <Line left="Change" right="100.00" />
        </>
      )}
      {template.footer_text && (
        <>
          <Divider />
          <p className="text-center whitespace-pre-line">{template.footer_text}</p>
        </>
      )}
    </div>
  )
}

function Divider() {
  return <div className="my-1.5 border-t border-dashed border-slate-400" />
}

function Line({ left, right, bold }: { left: string; right: string; bold?: boolean }) {
  return (
    <div className={cn('flex justify-between gap-2', bold && 'font-bold')}>
      <span className="min-w-0 truncate">{left}</span>
      <span className="shrink-0">{right}</span>
    </div>
  )
}
