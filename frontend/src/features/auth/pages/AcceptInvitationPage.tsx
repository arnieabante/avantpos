import { Link, useNavigate, useSearchParams } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { MailX } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Button, FormField, Input, PasswordInput, Spinner } from '@/components/ui'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime } from '@/lib/format'
import { authApi } from '../api'
import { AuthCard, AuthHeading } from '../AuthLayout'
import type { InvitationPreview } from '../types'
import { useAuth } from '../useAuth'

/** Opened from the invitation e-mail: /accept-invitation?token=... */
export default function AcceptInvitationPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''

  const invitation = useQuery({
    queryKey: ['invitation-preview', token],
    queryFn: () => authApi.invitation(token),
    enabled: token !== '',
    retry: false,
    staleTime: Infinity,
  })

  if (!token || invitation.isError) {
    const message = invitation.error ? ApiError.from(invitation.error).message : 'This invitation link is incomplete.'
    return (
      <AuthCard className="text-center">
        <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-red-50 text-red-600">
          <MailX className="size-6" />
        </div>
        <AuthHeading title="Invitation unavailable" description={message} />
        <p className="text-sm text-ink-subtle">Ask your business administrator to send you a new invitation.</p>
        <Link to="/login" className="mt-6 block text-sm font-medium text-brand-500 hover:underline">
          Go to sign in
        </Link>
      </AuthCard>
    )
  }

  if (invitation.isPending) {
    return (
      <AuthCard className="flex justify-center py-12">
        <Spinner size="lg" label="Loading invitation" />
      </AuthCard>
    )
  }

  return <AcceptForm token={token} invitation={invitation.data} />
}

function AcceptForm({ token, invitation }: { token: string; invitation: InvitationPreview }) {
  const { session, signIn } = useAuth()
  const navigate = useNavigate()

  const form = useForm({
    initialValues: { name: invitation.name ?? '', phone: '', password: '', password_confirmation: '' },
    validate: (v) => ({
      name: v.name.trim() ? undefined : 'Enter your full name.',
      password: v.password ? undefined : 'Choose a password.',
      password_confirmation: v.password === v.password_confirmation ? undefined : 'The passwords do not match.',
    }),
    onSubmit: async (values) => {
      const account = await authApi.acceptInvitation(token, values)
      signIn(account)
      navigate('/', { replace: true })
    },
  })

  return (
    <AuthCard>
      <AuthHeading
        title={`Join ${invitation.business_name}`}
        description={
          <>
            You've been invited{invitation.role ? <> as <strong className="text-ink">{invitation.role}</strong></> : null}. Set up your account to get started.
          </>
        }
      />

      {session?.status === 'authenticated' && (
        <Alert tone="warning" className="mb-4">
          You are currently signed in as {session.account.user.email}. Accepting will sign you in as the invited user instead.
        </Alert>
      )}

      <form onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />

        <FormField label="E-mail address" hint={`Invitation expires ${formatDateTime(invitation.expires_at)}`}>
          <Input value={invitation.email} readOnly disabled autoComplete="username" />
        </FormField>
        <FormField label="Full name" required error={form.errors.name}>
          <Input {...form.field('name')} autoComplete="name" autoFocus />
        </FormField>
        <FormField label="Mobile number" hint="Optional, e.g. 0917 123 4567" error={form.errors.phone}>
          <Input {...form.field('phone')} type="tel" autoComplete="tel" inputMode="tel" placeholder="09XX XXX XXXX" />
        </FormField>
        <FormField label="Password" required hint="At least 8 characters with upper- and lowercase letters and numbers." error={form.errors.password}>
          <PasswordInput {...form.field('password')} autoComplete="new-password" />
        </FormField>
        <FormField label="Confirm password" required error={form.errors.password_confirmation}>
          <PasswordInput {...form.field('password_confirmation')} autoComplete="new-password" />
        </FormField>

        <Button type="submit" size="lg" fullWidth loading={form.submitting}>
          Accept invitation
        </Button>
      </form>
    </AuthCard>
  )
}
