import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Badge, Button, Card, CardBody, CardFooter, CardHeader, DetailList, FormField, Input, PageHeader, PasswordInput, useToast } from '@/components/ui'
import { authApi } from '@/features/auth/api'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { useForm } from '@/hooks/useForm'
import { formatDateTime, formatPhone } from '@/lib/format'

/** "My account": profile summary, contact details, password and POS PIN. */
export default function AccountPage() {
  const account = useAccount()
  const { user } = account

  return (
    <>
      <PageHeader title="My account" description="Your profile and sign-in security." />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Profile" />
          <CardBody className="py-2">
            <DetailList
              items={[
                { label: 'Name', value: user.name },
                {
                  label: 'E-mail',
                  value: (
                    <span className="inline-flex items-center gap-2">
                      {user.email}
                      <Badge tone={user.email_verified ? 'success' : 'warning'}>{user.email_verified ? 'Verified' : 'Not verified'}</Badge>
                    </span>
                  ),
                },
                { label: 'Mobile', value: formatPhone(user.phone) },
                { label: 'Roles', value: account.roles.join(', ') || '—' },
                { label: 'Business', value: account.tenant.name },
                { label: 'Last sign-in', value: formatDateTime(user.last_login_at, account.tenant.timezone) },
              ]}
            />
          </CardBody>
        </Card>

        <ProfileForm />
        <PasswordForm />
        <PinForm />
      </div>
    </>
  )
}

function PinForm() {
  const { user } = useAccount()
  const { refresh } = useAuth()
  const toast = useToast()

  const form = useForm({
    initialValues: { current_password: '', pin: '', pin_confirmation: '' },
    validate: (v) => ({
      current_password: v.current_password ? undefined : 'Enter your current password.',
      pin: /^\d{4,6}$/.test(v.pin) ? undefined : 'The PIN must be 4 to 6 digits.',
      pin_confirmation: v.pin === v.pin_confirmation ? undefined : 'The PINs do not match.',
    }),
    onSubmit: async (values) => {
      const message = await authApi.setPin(values)
      toast.success(message || 'Your PIN has been saved.')
      form.reset()
      await refresh()
    },
  })

  const digitsOnly = (name: 'pin' | 'pin_confirmation') => ({
    ...form.field(name),
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => form.setValue(name, e.target.value.replace(/\D/g, '').slice(0, 6)),
  })

  return (
    <Card>
      <CardHeader
        title="POS PIN"
        description="A 4–6 digit PIN for quick cashier sign-in and supervisor approvals on the POS."
        actions={<Badge tone={user.has_pin ? 'success' : 'neutral'}>{user.has_pin ? 'Set' : 'Not set'}</Badge>}
      />
      <form onSubmit={form.handleSubmit} noValidate>
        <CardBody className="space-y-4">
          <ApiErrorAlert error={form.formError} />
          <FormField label="Current password" required error={form.errors.current_password}>
            <PasswordInput {...form.field('current_password')} autoComplete="current-password" />
          </FormField>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label={user.has_pin ? 'New PIN' : 'PIN'} required error={form.errors.pin}>
              <Input {...digitsOnly('pin')} type="password" inputMode="numeric" autoComplete="off" maxLength={6} />
            </FormField>
            <FormField label="Confirm PIN" required error={form.errors.pin_confirmation}>
              <Input {...digitsOnly('pin_confirmation')} type="password" inputMode="numeric" autoComplete="off" maxLength={6} />
            </FormField>
          </div>
          <p className="text-sm text-ink-subtle">Avoid easy PINs like 1234 or 0000.</p>
        </CardBody>
        <CardFooter>
          <Button type="submit" loading={form.submitting}>
            {user.has_pin ? 'Change PIN' : 'Save PIN'}
          </Button>
        </CardFooter>
      </form>
    </Card>
  )
}

function ProfileForm() {
  const { user } = useAccount()
  const { signIn } = useAuth()
  const toast = useToast()

  const form = useForm({
    initialValues: { name: user.name, phone: user.phone ?? '' },
    validate: (v) => ({ name: v.name.trim() ? undefined : 'Enter your name.' }),
    onSubmit: async (values) => {
      // The response is the refreshed session payload.
      signIn(await authApi.updateProfile(values))
      toast.success('Profile saved')
    },
  })

  return (
    <Card>
      <CardHeader title="Contact details" description="Your e-mail address is your sign-in and cannot be changed here." />
      <form onSubmit={form.handleSubmit} noValidate>
        <CardBody className="space-y-4">
          <ApiErrorAlert error={form.formError} />
          <FormField label="Name" required error={form.errors.name}>
            <Input {...form.field('name')} autoComplete="name" />
          </FormField>
          <FormField label="Mobile number" hint="e.g. 0917 123 4567" error={form.errors.phone}>
            <Input {...form.field('phone')} type="tel" autoComplete="tel" />
          </FormField>
        </CardBody>
        <CardFooter>
          <Button type="submit" loading={form.submitting}>
            Save details
          </Button>
        </CardFooter>
      </form>
    </Card>
  )
}

function PasswordForm() {
  const toast = useToast()

  const form = useForm({
    initialValues: { current_password: '', password: '', password_confirmation: '' },
    validate: (v) => ({
      current_password: v.current_password ? undefined : 'Enter your current password.',
      password: v.password ? undefined : 'Choose a new password.',
      password_confirmation: v.password === v.password_confirmation ? undefined : 'The passwords do not match.',
    }),
    onSubmit: async (values) => {
      toast.success(await authApi.changePassword(values))
      form.reset()
    },
  })

  return (
    <Card>
      <CardHeader title="Password" description="Changing it signs you out on your other devices." />
      <form onSubmit={form.handleSubmit} noValidate>
        <CardBody className="space-y-4">
          <ApiErrorAlert error={form.formError} />
          <FormField label="Current password" required error={form.errors.current_password}>
            <PasswordInput {...form.field('current_password')} autoComplete="current-password" />
          </FormField>
          <FormField label="New password" required hint="At least 8 characters with upper- and lowercase letters and numbers." error={form.errors.password}>
            <PasswordInput {...form.field('password')} autoComplete="new-password" />
          </FormField>
          <FormField label="Confirm new password" required error={form.errors.password_confirmation}>
            <PasswordInput {...form.field('password_confirmation')} autoComplete="new-password" />
          </FormField>
        </CardBody>
        <CardFooter>
          <Button type="submit" loading={form.submitting}>
            Change password
          </Button>
        </CardFooter>
      </form>
    </Card>
  )
}
