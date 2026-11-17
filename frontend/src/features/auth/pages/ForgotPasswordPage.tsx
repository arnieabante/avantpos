import { useState } from 'react'
import { Link } from 'react-router'
import { ArrowLeft, MailCheck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, FormField, Input } from '@/components/ui'
import { useForm } from '@/hooks/useForm'
import { authApi } from '../api'
import { AuthCard, AuthHeading } from '../AuthLayout'

export default function ForgotPasswordPage() {
  const [sentMessage, setSentMessage] = useState<string | null>(null)

  const form = useForm({
    initialValues: { email: '' },
    validate: (v) => ({ email: v.email.trim() ? undefined : 'Enter your e-mail address.' }),
    onSubmit: async ({ email }) => setSentMessage(await authApi.forgotPassword(email.trim())),
  })

  return (
    <AuthCard>
      {sentMessage ? (
        <div className="text-center">
          <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
            <MailCheck className="size-6" />
          </div>
          <AuthHeading title="Check your e-mail" description={sentMessage} />
          <p className="text-sm text-ink-subtle">The link expires in 60 minutes. Check your spam folder if you don't see it.</p>
        </div>
      ) : (
        <>
          <AuthHeading title="Forgot your password?" description="Enter the e-mail address of your account and we'll send you a reset link." />
          <form onSubmit={form.handleSubmit} noValidate className="space-y-4">
            <ApiErrorAlert error={form.formError} />
            <FormField label="E-mail address" error={form.errors.email}>
              <Input {...form.field('email')} type="email" autoComplete="email" inputMode="email" autoFocus />
            </FormField>
            <Button type="submit" size="lg" fullWidth loading={form.submitting}>
              Send reset link
            </Button>
          </form>
        </>
      )}

      <Link to="/login" className="mt-6 flex items-center justify-center gap-1.5 text-sm font-medium text-brand-500 hover:underline">
        <ArrowLeft className="size-4" /> Back to sign in
      </Link>
    </AuthCard>
  )
}
