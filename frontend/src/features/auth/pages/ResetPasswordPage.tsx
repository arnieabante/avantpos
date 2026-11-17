import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { CheckCircle2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, ButtonLink, FormField, Input, PasswordInput } from '@/components/ui'
import { useForm } from '@/hooks/useForm'
import { authApi } from '../api'
import { AuthCard, AuthHeading } from '../AuthLayout'

/** Opened from the password-reset e-mail: /reset-password?token=...&email=... */
export default function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [done, setDone] = useState<string | null>(null)

  const form = useForm({
    initialValues: { password: '', password_confirmation: '' },
    validate: (v) => ({
      password: v.password ? undefined : 'Choose a new password.',
      password_confirmation: v.password === v.password_confirmation ? undefined : 'The passwords do not match.',
    }),
    onSubmit: async (values) => setDone(await authApi.resetPassword({ token, email, ...values })),
  })

  if (!token || !email) {
    return (
      <AuthCard>
        <AuthHeading title="Invalid reset link" />
        <Alert tone="danger">This password reset link is incomplete. Please request a new one.</Alert>
        <Link to="/forgot-password" className="mt-6 block text-center text-sm font-medium text-brand-500 hover:underline">
          Request a new link
        </Link>
      </AuthCard>
    )
  }

  if (done) {
    return (
      <AuthCard className="text-center">
        <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
          <CheckCircle2 className="size-6" />
        </div>
        <AuthHeading title="Password changed" description={done} />
        <ButtonLink to="/login" size="lg" fullWidth>
          Sign in
        </ButtonLink>
      </AuthCard>
    )
  }

  // The API reports token problems on "email" ("This password reset token is invalid.").
  const linkError = form.errors.email

  return (
    <AuthCard>
      <AuthHeading title="Set a new password" description="All other devices signed in to your account will be signed out." />
      <form onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />
        {linkError && (
          <Alert tone="danger">
            {linkError}{' '}
            <Link to="/forgot-password" className="font-medium underline">
              Request a new link
            </Link>
          </Alert>
        )}

        <FormField label="E-mail address">
          <Input value={email} readOnly disabled autoComplete="username" />
        </FormField>
        <FormField label="New password" required hint="At least 8 characters with upper- and lowercase letters and numbers." error={form.errors.password}>
          <PasswordInput {...form.field('password')} autoComplete="new-password" autoFocus />
        </FormField>
        <FormField label="Confirm new password" required error={form.errors.password_confirmation}>
          <PasswordInput {...form.field('password_confirmation')} autoComplete="new-password" />
        </FormField>
        <Button type="submit" size="lg" fullWidth loading={form.submitting}>
          Reset password
        </Button>
      </form>
    </AuthCard>
  )
}
