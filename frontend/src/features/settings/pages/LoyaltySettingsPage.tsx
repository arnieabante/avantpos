import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, Card, CardBody, CardHeader, Checkbox, FormField, Input, PageHeader, PageLoader, useToast } from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { customerApi, customerKeys } from '@/features/customers/api'
import type { LoyaltySettings } from '@/features/customers/types'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatMoney } from '@/lib/format'

const trim = (v: string) => v.replace(/\.00$/, '')

export default function LoyaltySettingsPage() {
  const settings = useQuery({ queryKey: customerKeys.loyaltySettings, queryFn: customerApi.loyaltySettings })
  if (settings.isPending) return <PageLoader />
  if (settings.isError) return <ApiErrorAlert error={ApiError.from(settings.error)} />
  return <LoyaltyForm settings={settings.data} />
}

function LoyaltyForm({ settings }: { settings: LoyaltySettings }) {
  const { can } = useAuth()
  const account = useAccount()
  const queryClient = useQueryClient()
  const toast = useToast()
  const featureOn = Boolean(account.subscription?.plan.features?.loyalty)
  const canEdit = can('settings.discounts') && featureOn

  const form = useForm({
    initialValues: {
      enabled: settings.enabled,
      spend_per_point: trim(settings.spend_per_point),
      point_value: settings.point_value,
      min_redeem_points: String(settings.min_redeem_points),
    },
    validate: (v) => ({
      spend_per_point: /^\d+(\.\d{1,2})?$/.test(v.spend_per_point.trim()) && !/^0*(\.0*)?$/.test(v.spend_per_point.trim()) ? undefined : 'Enter an amount, e.g. 100',
      point_value: /^\d+(\.\d{1,2})?$/.test(v.point_value.trim()) && !/^0*(\.0*)?$/.test(v.point_value.trim()) ? undefined : 'Enter an amount, e.g. 1.00',
      min_redeem_points: /^\d+$/.test(v.min_redeem_points.trim()) ? undefined : 'Enter a whole number.',
    }),
    onSubmit: async (v) => {
      const saved = await customerApi.saveLoyaltySettings({
        enabled: v.enabled,
        spend_per_point: v.spend_per_point.trim(),
        point_value: v.point_value.trim(),
        min_redeem_points: Number(v.min_redeem_points.trim()),
      })
      queryClient.setQueryData(customerKeys.loyaltySettings, saved)
      toast.success('Loyalty settings saved')
    },
  })
  const v = form.values

  return (
    <form onSubmit={form.handleSubmit} noValidate className="space-y-6">
      <PageHeader title="Loyalty points" description="How customers earn and use points. Earning and redeeming happen at the POS." />
      {!featureOn && <Alert tone="warning">Loyalty points are not included in your current plan.</Alert>}
      <ApiErrorAlert error={form.formError} />
      <Card>
        <CardHeader title="Program" />
        <CardBody className="grid gap-4 md:grid-cols-3">
          <div className="md:col-span-3">
            <Checkbox {...form.checkbox('enabled')} disabled={!canEdit} label="Customers earn points on purchases" />
          </div>
          <FormField label="Spend per point" error={form.errors.spend_per_point} hint="Pesos spent to earn 1 point.">
            <Input {...form.field('spend_per_point')} disabled={!canEdit} inputMode="decimal" leftAddon="₱" />
          </FormField>
          <FormField label="Value of 1 point" error={form.errors.point_value} hint="Discount per point when redeemed.">
            <Input {...form.field('point_value')} disabled={!canEdit} inputMode="decimal" leftAddon="₱" />
          </FormField>
          <FormField label="Minimum points to redeem" error={form.errors.min_redeem_points}>
            <Input {...form.field('min_redeem_points')} disabled={!canEdit} inputMode="numeric" />
          </FormField>
          {!form.errors.spend_per_point && !form.errors.point_value && v.spend_per_point && v.point_value && (
            <p className="text-sm text-ink-muted md:col-span-3">
              Example: a {formatMoney(v.spend_per_point)} purchase earns 1 point, worth {formatMoney(v.point_value)} on a later purchase.
            </p>
          )}
        </CardBody>
      </Card>
      {canEdit && (
        <div className="flex justify-end">
          <Button type="submit" loading={form.submitting}>
            Save
          </Button>
        </div>
      )}
    </form>
  )
}
