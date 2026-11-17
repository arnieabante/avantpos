import { useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, Trash2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Alert,
  Button,
  Card,
  CardBody,
  CardFooter,
  CardHeader,
  Checkbox,
  FormField,
  Input,
  PageHeader,
  PageLoader,
  Select,
  SuffixInput,
  useConfirm,
  useToast,
} from '@/components/ui'
import { BUSINESS_TYPES } from '@/features/auth/types'
import { useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { env } from '@/lib/env'
import { settingsApi, settingsKeys, type BusinessInput } from '../api'
import { AddressFields } from '../components/AddressFields'
import { addressFromApi } from '../formDefaults'
import { DATE_FORMATS, TIME_FORMATS, type AddressInput, type Business } from '../types'

export default function BusinessProfilePage() {
  const business = useQuery({ queryKey: settingsKeys.business, queryFn: settingsApi.business })

  if (business.isPending) return <PageLoader />
  if (business.isError) return <ApiErrorAlert error={ApiError.from(business.error)} />

  return (
    <>
      <PageHeader title="Business profile" description="Shown on receipts and invoices. Keep your BIR details accurate." />
      <div className="space-y-6">
        <LogoCard business={business.data} />
        <ProfileForm business={business.data} />
      </div>
    </>
  )
}

function toInput(b: Business): BusinessInput {
  return {
    name: b.name,
    business_type: b.business_type ?? '',
    subdomain: b.subdomain ?? '',
    registered_name: b.registered_name ?? '',
    business_style: b.business_style ?? '',
    tin: b.tin ?? '',
    tin_branch_code: b.tin_branch_code,
    rdo_code: b.rdo_code ?? '',
    vat_registered: b.vat_registered,
    email: b.email ?? '',
    phone: b.phone ?? '',
    ...addressFromApi(b.address),
    timezone: b.timezone,
    date_format: b.date_format,
    time_format: b.time_format,
  }
}

function ProfileForm({ business }: { business: Business }) {
  const { can, refresh } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const canEdit = can('business.manage')

  const form = useForm({
    initialValues: toInput(business),
    validate: (v) => ({ name: v.name.trim() ? undefined : 'Enter the business name.' }),
    onSubmit: async (values) => {
      const saved = await settingsApi.updateBusiness(values)
      queryClient.setQueryData(settingsKeys.business, saved)
      form.reset(toInput(saved))
      toast.success('Business profile saved')
      // Name / VAT status are part of the session payload.
      await refresh()
    },
  })

  const address: AddressInput = {
    address_line: form.values.address_line,
    region_code: form.values.region_code,
    province_code: form.values.province_code,
    city_code: form.values.city_code,
    barangay_code: form.values.barangay_code,
    postal_code: form.values.postal_code,
  }
  const timezones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [business.timezone]

  return (
    <form onSubmit={form.handleSubmit} noValidate>
      <fieldset disabled={!canEdit || form.submitting} className="space-y-6">
        {!canEdit && <Alert tone="info">You can view the business profile. Only owners and administrators can change it.</Alert>}
        <ApiErrorAlert error={form.formError} />

        <Card>
          <CardHeader title="Business" />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <FormField label="Business / trade name" required error={form.errors.name} className="sm:col-span-2">
              <Input {...form.field('name')} />
            </FormField>
            <FormField label="Type of business" error={form.errors.business_type}>
              <Select {...form.field('business_type')}>
                <option value="">Select…</option>
                {BUSINESS_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Web address" hint="Your staff can sign in here." error={form.errors.subdomain}>
              <SuffixInput
                {...form.field('subdomain')}
                suffix={`.${env.baseDomain}`}
                autoCapitalize="none"
                spellCheck={false}
                onChange={(e) => form.setValue('subdomain', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
              />
            </FormField>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="BIR registration" description="As printed on your BIR Certificate of Registration (Form 2303)." />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            <FormField label="Registered name" hint="Taxpayer's name" error={form.errors.registered_name}>
              <Input {...form.field('registered_name')} />
            </FormField>
            <FormField label="Business style" error={form.errors.business_style}>
              <Input {...form.field('business_style')} />
            </FormField>
            <FormField label="TIN" hint="e.g. 123-456-789" error={form.errors.tin}>
              <Input {...form.field('tin')} inputMode="numeric" placeholder="000-000-000" />
            </FormField>
            <div className="grid grid-cols-2 gap-4">
              <FormField label="TIN branch code" error={form.errors.tin_branch_code}>
                <Input {...form.field('tin_branch_code')} inputMode="numeric" maxLength={5} />
              </FormField>
              <FormField label="RDO code" error={form.errors.rdo_code}>
                <Input {...form.field('rdo_code')} maxLength={4} />
              </FormField>
            </div>
            <Checkbox
              {...form.checkbox('vat_registered')}
              className="sm:col-span-2"
              label="VAT-registered"
              description="Switches the default tax to 12% VAT. Non-VAT businesses default to 3% percentage tax."
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Contact and address" />
          <CardBody className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="E-mail" error={form.errors.email}>
                <Input {...form.field('email')} type="email" />
              </FormField>
              <FormField label="Phone" hint="Mobile or landline" error={form.errors.phone}>
                <Input {...form.field('phone')} type="tel" />
              </FormField>
            </div>
            <AddressFields
              value={address}
              errors={form.errors}
              disabled={!canEdit}
              onChange={(next) => (Object.keys(next) as Array<keyof AddressInput>).forEach((key) => form.setValue(key, next[key]))}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Regional settings" description="Currency is Philippine Peso (₱)." />
          <CardBody className="grid gap-4 sm:grid-cols-3">
            <FormField label="Timezone" error={form.errors.timezone}>
              <Select {...form.field('timezone')}>
                {timezones.map((tz) => (
                  <option key={tz} value={tz}>
                    {tz}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Date format" error={form.errors.date_format}>
              <Select {...form.field('date_format')}>
                {DATE_FORMATS.map((f) => (
                  <option key={f.value} value={f.value}>
                    {f.label}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Time format" error={form.errors.time_format}>
              <Select {...form.field('time_format')}>
                {TIME_FORMATS.map((f) => (
                  <option key={f.value} value={f.value}>
                    {f.label}
                  </option>
                ))}
              </Select>
            </FormField>
          </CardBody>
          {canEdit && (
            <CardFooter>
              <Button variant="secondary" onClick={() => form.reset(toInput(business))} disabled={form.submitting}>
                Discard changes
              </Button>
              <Button type="submit" loading={form.submitting}>
                Save profile
              </Button>
            </CardFooter>
          )}
        </Card>
      </fieldset>
    </form>
  )
}

const MAX_LOGO_BYTES = 2 * 1024 * 1024

function LogoCard({ business }: { business: Business }) {
  const { can, refresh } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const input = useRef<HTMLInputElement>(null)
  const canEdit = can('business.manage')

  async function upload(file: File) {
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      toast.error('Use a PNG, JPG or WebP image.')
      return
    }
    if (file.size > MAX_LOGO_BYTES) {
      toast.error('The logo must be 2 MB or smaller.')
      return
    }
    try {
      queryClient.setQueryData(settingsKeys.business, await settingsApi.uploadLogo(file))
      toast.success('Logo updated')
      await refresh()
    } catch (error) {
      toast.error(ApiError.from(error).message)
    }
  }

  async function remove() {
    if (!(await confirm({ title: 'Remove the logo?', description: 'Receipts will print without a logo.', confirmLabel: 'Remove', danger: true }))) return
    try {
      queryClient.setQueryData(settingsKeys.business, await settingsApi.removeLogo())
      toast.success('Logo removed')
      await refresh()
    } catch (error) {
      toast.error(ApiError.from(error).message)
    }
  }

  return (
    <Card>
      <CardBody className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-dashed border-line bg-canvas">
          {business.logo_url ? (
            <img src={business.logo_url} alt={`${business.name} logo`} className="size-full object-contain" />
          ) : (
            <ImagePlus className="size-7 text-line" aria-hidden="true" />
          )}
        </div>
        <div className="min-w-0 flex-1">
          <p className="font-medium text-ink">Logo</p>
          <p className="text-sm text-ink-subtle">PNG, JPG or WebP, up to 2 MB. Printed on receipts when enabled.</p>
        </div>
        {canEdit && (
          <div className="flex gap-2">
            <input
              ref={input}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              className="hidden"
              aria-label="Upload logo"
              onChange={(e) => {
                const file = e.target.files?.[0]
                e.target.value = ''
                if (file) void upload(file)
              }}
            />
            <Button variant="outline" leftIcon={<ImagePlus className="size-4" />} onClick={() => input.current?.click()}>
              {business.logo_url ? 'Replace' : 'Upload'}
            </Button>
            {business.logo_url && (
              <Button variant="ghost" leftIcon={<Trash2 className="size-4" />} onClick={remove}>
                Remove
              </Button>
            )}
          </div>
        )}
      </CardBody>
    </Card>
  )
}
