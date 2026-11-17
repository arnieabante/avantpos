import { Link, useNavigate } from 'react-router'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Checkbox, FormField, Input, PasswordInput, Select, SuffixInput } from '@/components/ui'
import { useForm } from '@/hooks/useForm'
import { env } from '@/lib/env'
import { authApi } from '../api'
import { AuthCard, AuthHeading } from '../AuthLayout'
import { BUSINESS_TYPES, type RegisterPayload } from '../types'
import { useAuth } from '../useAuth'

const initialValues: RegisterPayload = {
  business_name: '',
  business_type: '',
  subdomain: '',
  vat_registered: false,
  owner_name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  accept_terms: false,
}

export default function RegisterPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()

  const form = useForm({
    initialValues,
    // Quick checks only; the API validates everything (formats, uniqueness, password rules).
    validate: (v) => ({
      business_name: v.business_name.trim() ? undefined : 'Enter your business name.',
      owner_name: v.owner_name.trim() ? undefined : 'Enter your full name.',
      email: v.email.trim() ? undefined : 'Enter your e-mail address.',
      phone: v.phone.trim() ? undefined : 'Enter your mobile number.',
      password: v.password ? undefined : 'Choose a password.',
      password_confirmation: v.password_confirmation === v.password ? undefined : 'The passwords do not match.',
      accept_terms: v.accept_terms ? undefined : 'Please accept the Terms of Service and Privacy Policy.',
    }),
    onSubmit: async (values) => {
      const account = await authApi.register(values)
      signIn(account)
      navigate('/', { replace: true })
    },
  })

  return (
    <AuthCard className="max-w-xl">
      <AuthHeading title="Register your business" description="Start your free trial. No credit card required." />

      <form onSubmit={form.handleSubmit} noValidate className="space-y-6">
        <ApiErrorAlert error={form.formError} />

        <fieldset className="space-y-4">
          <legend className="mb-3 text-sm font-semibold text-ink">Business</legend>

          <FormField label="Business name" required error={form.errors.business_name}>
            <Input {...form.field('business_name')} autoComplete="organization" autoFocus />
          </FormField>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Type of business" error={form.errors.business_type}>
              <Select {...form.field('business_type')}>
                <option value="">Select…</option>
                {BUSINESS_TYPES.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </Select>
            </FormField>

            <FormField label="Business web address" hint="Optional. You can set it later." error={form.errors.subdomain}>
              <SuffixInput
                {...form.field('subdomain')}
                suffix={`.${env.baseDomain}`}
                placeholder="mystore"
                autoCapitalize="none"
                spellCheck={false}
                onChange={(e) => form.setValue('subdomain', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
              />
            </FormField>
          </div>

          <Checkbox
            {...form.checkbox('vat_registered')}
            label="My business is VAT-registered"
            description="Leave unchecked if you are non-VAT. You can change tax settings later."
            error={form.errors.vat_registered}
          />
        </fieldset>

        <fieldset className="space-y-4 border-t border-line pt-6">
          <legend className="mb-3 text-sm font-semibold text-ink">Owner account</legend>

          <FormField label="Full name" required error={form.errors.owner_name}>
            <Input {...form.field('owner_name')} autoComplete="name" />
          </FormField>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="E-mail address" required error={form.errors.email}>
              <Input {...form.field('email')} type="email" autoComplete="email" inputMode="email" />
            </FormField>
            <FormField label="Mobile number" required hint="e.g. 0917 123 4567" error={form.errors.phone}>
              <Input {...form.field('phone')} type="tel" autoComplete="tel" inputMode="tel" placeholder="09XX XXX XXXX" />
            </FormField>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Password" required hint="At least 8 characters with upper- and lowercase letters and numbers." error={form.errors.password}>
              <PasswordInput {...form.field('password')} autoComplete="new-password" />
            </FormField>
            <FormField label="Confirm password" required error={form.errors.password_confirmation}>
              <PasswordInput {...form.field('password_confirmation')} autoComplete="new-password" />
            </FormField>
          </div>
        </fieldset>

        <Checkbox
          {...form.checkbox('accept_terms')}
          error={form.errors.accept_terms}
          label={<>I agree to the AvantPOS Terms of Service and Privacy Policy (Data Privacy Act of 2012).</>}
        />

        <Button type="submit" size="lg" fullWidth loading={form.submitting}>
          Create my business account
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-ink-muted">
        Already registered?{' '}
        <Link to="/login" className="font-medium text-brand-500 hover:underline">
          Sign in
        </Link>
      </p>
    </AuthCard>
  )
}
