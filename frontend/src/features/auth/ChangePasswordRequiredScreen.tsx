import { KeyRound } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { AppFooter } from '@/components/layout/AppFooter'
import { Button, FormField, PasswordInput } from '@/components/ui'
import { useForm } from '@/hooks/useForm'
import { AuthCard, AuthHeading, Logo } from './AuthLayout'

export interface PasswordChange {
  current_password: string
  password: string
  password_confirmation: string
}

/**
 * First sign-in with a password someone else chose (seeded account, added by an
 * administrator, reset by another admin): the person sets their own before
 * anything else. The API refuses everything else until then.
 */
export function ChangePasswordRequiredScreen({
  email,
  onSubmit,
  onSignOut,
  dark = false,
}: {
  email: string
  onSubmit: (values: PasswordChange) => Promise<unknown>
  onSignOut: () => void
  dark?: boolean
}) {
  const form = useForm<PasswordChange>({
    initialValues: { current_password: '', password: '', password_confirmation: '' },
    validate: (v) => ({
      current_password: v.current_password ? undefined : 'Enter the password you signed in with.',
      password: !v.password ? 'Choose a new password.' : v.password === v.current_password ? 'Choose a password different from the one you were given.' : undefined,
      password_confirmation: v.password === v.password_confirmation ? undefined : 'The passwords do not match.',
    }),
    onSubmit: async (values) => {
      await onSubmit(values)
    },
  })

  return (
    <div className={dark ? 'flex min-h-dvh flex-col bg-slate-900' : 'flex min-h-dvh flex-col'}>
      <main className="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
        <Logo inverted={dark} className="mb-8" />
        <AuthCard>
          <div className="mb-4 flex size-11 items-center justify-center rounded-full bg-brand-50 text-brand-500">
            <KeyRound className="size-6" aria-hidden="true" />
          </div>
          <AuthHeading
            title="Set your own password"
            description={
              <>
                You are signed in as <span className="font-medium text-ink">{email}</span> with a password someone else gave you. Choose your own to continue.
              </>
            }
          />
          <form onSubmit={form.handleSubmit} noValidate className="space-y-4">
            <ApiErrorAlert error={form.formError} />
            <FormField label="Current password" required hint="The password you just signed in with." error={form.errors.current_password}>
              <PasswordInput {...form.field('current_password')} autoComplete="current-password" data-autofocus />
            </FormField>
            <FormField label="New password" required hint="At least 8 characters with upper- and lowercase letters and numbers." error={form.errors.password}>
              <PasswordInput {...form.field('password')} autoComplete="new-password" />
            </FormField>
            <FormField label="Confirm new password" required error={form.errors.password_confirmation}>
              <PasswordInput {...form.field('password_confirmation')} autoComplete="new-password" />
            </FormField>
            <Button type="submit" fullWidth loading={form.submitting}>
              Save and continue
            </Button>
            <Button type="button" variant="link" fullWidth onClick={onSignOut}>
              Sign out
            </Button>
          </form>
        </AuthCard>
      </main>
      <AppFooter className={dark ? 'text-slate-400' : undefined} />
    </div>
  )
}
