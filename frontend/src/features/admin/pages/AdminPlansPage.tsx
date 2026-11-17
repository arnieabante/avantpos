import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, Checkbox, FormField, Input, Modal, PageHeader, PageLoader, Textarea, useToast } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatMoney } from '@/lib/format'
import { useAdminAuth } from '../useAdminAuth'
import { adminApi, adminKeys, type AdminPlan } from '../api'
import { ADMIN_FEATURE_LABELS } from '../labels'

const LIMITS = [
  ['max_branches', 'Branches'],
  ['max_users', 'Users'],
  ['max_terminals', 'POS terminals'],
  ['max_products', 'Products'],
] as const

/** /admin/plans — prices, limits, trial and grace days, features. */
export default function AdminPlansPage() {
  const { admin } = useAdminAuth()
  const q = useQuery({ queryKey: adminKeys.plans, queryFn: adminApi.plans })
  const [editing, setEditing] = useState<AdminPlan | 'new' | null>(null)
  const canEdit = ['super_admin', 'billing'].includes(admin?.role ?? '')

  if (q.isPending) return <PageLoader />
  if (q.isError) return <ApiErrorAlert error={ApiError.from(q.error)} />

  return (
    <>
      <PageHeader
        title="Subscription plans"
        description="Changes apply to new invoices; businesses keep what they already paid for."
        actions={
          canEdit && (
            <Button leftIcon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              New plan
            </Button>
          )
        }
      />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {q.data.plans.map((p) => (
          <Card key={p.id} className="flex flex-col p-5">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="text-lg font-semibold text-ink">{p.name}</p>
                <p className="text-xs text-ink-subtle">
                  {p.code} · {p.businesses ?? 0} businesses
                </p>
              </div>
              <div className="flex gap-1">
                {!p.is_public && <Badge tone="neutral">Hidden</Badge>}
                {!p.is_active && <Badge tone="danger">Inactive</Badge>}
              </div>
            </div>
            <p className="mt-2 text-sm text-ink">
              {formatMoney(p.monthly_price)} / month · {formatMoney(p.annual_price)} / year
            </p>
            <p className="text-sm">
              {p.trial_days} trial days · {p.grace_days} grace days
            </p>
            <p className="mt-1 text-sm">{LIMITS.map(([k, label]) => `${p[k] ?? '∞'} ${label.toLowerCase()}`).join(' · ')}</p>
            <p className="mt-2 text-xs text-ink-subtle">
              {Object.entries(p.features)
                .filter(([, on]) => on)
                .map(([k]) => ADMIN_FEATURE_LABELS[k] ?? k)
                .join(', ')}
            </p>
            {canEdit && (
              <Button className="mt-4 self-start" size="sm" variant="outline" onClick={() => setEditing(p)}>
                Edit
              </Button>
            )}
          </Card>
        ))}
      </div>
      {editing && <PlanModal plan={editing === 'new' ? null : editing} featureKeys={q.data.feature_keys} onClose={() => setEditing(null)} />}
    </>
  )
}

function PlanModal({ plan, featureKeys, onClose }: { plan: AdminPlan | null; featureKeys: string[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [v, setV] = useState({
    code: plan?.code ?? '',
    name: plan?.name ?? '',
    description: plan?.description ?? '',
    monthly_price: plan?.monthly_price ?? '',
    annual_price: plan?.annual_price ?? '',
    trial_days: String(plan?.trial_days ?? 14),
    grace_days: String(plan?.grace_days ?? 7),
    max_branches: plan?.max_branches?.toString() ?? '',
    max_users: plan?.max_users?.toString() ?? '',
    max_terminals: plan?.max_terminals?.toString() ?? '',
    max_products: plan?.max_products?.toString() ?? '',
    is_public: plan?.is_public ?? true,
    is_active: plan?.is_active ?? true,
    sort_order: String(plan?.sort_order ?? 0),
  })
  const [features, setFeatures] = useState<Record<string, boolean>>(() => Object.fromEntries(featureKeys.map((k) => [k, plan?.features[k] ?? false])))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const set = (p: Partial<typeof v>) => setV((x) => ({ ...x, ...p }))
  const num = (s: string) => (s.trim() === '' ? null : Number(s))

  const save = async () => {
    setBusy(true)
    setError(null)
    setErrors({})
    try {
      await adminApi.savePlan(
        {
          code: v.code.trim(), name: v.name.trim(), description: v.description.trim() || null,
          monthly_price: v.monthly_price.trim(), annual_price: v.annual_price.trim(),
          trial_days: Number(v.trial_days), grace_days: Number(v.grace_days),
          max_branches: num(v.max_branches), max_users: num(v.max_users), max_terminals: num(v.max_terminals), max_products: num(v.max_products),
          is_public: v.is_public, is_active: v.is_active, sort_order: Number(v.sort_order) || 0, features,
        },
        plan?.id,
      )
      await queryClient.invalidateQueries({ queryKey: adminKeys.plans })
      toast.success('Plan saved')
      onClose()
    } catch (e) {
      const err = ApiError.from(e)
      if (err.isValidation) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else setError(err)
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      dismissible={!busy}
      title={plan ? `Edit ${plan.name}` : 'New plan'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy}>
            Save plan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={error} />
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Name" required error={errors.name}>
            <Input value={v.name} maxLength={80} onChange={(e) => set({ name: e.target.value })} data-autofocus />
          </FormField>
          <FormField label="Code" required hint="lowercase, e.g. pro_plus" error={errors.code}>
            <Input value={v.code} maxLength={40} disabled={!!plan} onChange={(e) => set({ code: e.target.value })} />
          </FormField>
          <FormField label="Order" error={errors.sort_order}>
            <Input value={v.sort_order} inputMode="numeric" onChange={(e) => set({ sort_order: e.target.value.replace(/\D/g, '') })} />
          </FormField>
          <FormField label="Monthly price (VAT incl.)" required error={errors.monthly_price}>
            <Input value={v.monthly_price} inputMode="decimal" leftAddon="₱" onChange={(e) => set({ monthly_price: e.target.value.replace(/[^\d.]/g, '') })} />
          </FormField>
          <FormField label="Yearly price (VAT incl.)" required error={errors.annual_price}>
            <Input value={v.annual_price} inputMode="decimal" leftAddon="₱" onChange={(e) => set({ annual_price: e.target.value.replace(/[^\d.]/g, '') })} />
          </FormField>
          <div className="grid grid-cols-2 gap-2">
            <FormField label="Trial days" error={errors.trial_days}>
              <Input value={v.trial_days} inputMode="numeric" onChange={(e) => set({ trial_days: e.target.value.replace(/\D/g, '') })} />
            </FormField>
            <FormField label="Grace days" error={errors.grace_days}>
              <Input value={v.grace_days} inputMode="numeric" onChange={(e) => set({ grace_days: e.target.value.replace(/\D/g, '') })} />
            </FormField>
          </div>
          {LIMITS.map(([k, label]) => (
            <FormField key={k} label={`Max ${label.toLowerCase()}`} hint="Empty = unlimited" error={errors[k]}>
              <Input value={v[k]} inputMode="numeric" onChange={(e) => set({ [k]: e.target.value.replace(/\D/g, '') })} />
            </FormField>
          ))}
        </div>
        <FormField label="Description">
          <Textarea rows={2} maxLength={1000} value={v.description} onChange={(e) => set({ description: e.target.value })} />
        </FormField>
        <fieldset>
          <legend className="mb-2 text-[0.8125rem] font-medium tracking-[0.02em] text-ink uppercase">Features</legend>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {featureKeys.map((k) => (
              <Checkbox key={k} label={ADMIN_FEATURE_LABELS[k] ?? k} checked={features[k] ?? false} onChange={(e) => setFeatures((f) => ({ ...f, [k]: e.target.checked }))} />
            ))}
          </div>
        </fieldset>
        <div className="flex gap-6">
          <Checkbox label="Shown to businesses" checked={v.is_public} onChange={(e) => set({ is_public: e.target.checked })} />
          <Checkbox label="Active" checked={v.is_active} onChange={(e) => set({ is_active: e.target.checked })} />
        </div>
      </div>
    </Modal>
  )
}
