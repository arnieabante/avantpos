import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { ShieldCheck } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, FormField, Input, PasswordInput } from '@/components/ui'
import { AuthCard, AuthHeading, Logo } from '@/features/auth/AuthLayout'
import { useForm } from '@/hooks/useForm'
import { ApiError } from '@/lib/api/errors'
import { safeRedirectPath } from '@/lib/utils'
import { adminAuthApi } from './adminSession'
import { useAdminAuth } from './useAdminAuth'

export default function AdminLoginPage() {
  const { signIn } = useAdminAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const redirect = safeRedirectPath(params.get('redirect'), '/admin')

  // Two-factor sign-in: the code is asked for after the password is accepted.
  const [needCode, setNeedCode] = useState(false)
  const form = useForm({
    initialValues: { email: '', password: '', code: '' },
    validate: (v) => ({
      email: v.email.trim() ? undefined : 'Enter your e-mail address.',
      password: v.password ? undefined : 'Enter your password.',
      code: needCode && !/^\d{6}$/.test(v.code.trim()) ? 'Enter the 6-digit code.' : undefined,
    }),
    onSubmit: async (values) => {
      try {
        signIn(await adminAuthApi.login({ email: values.email.trim(), password: values.password, ...(needCode ? { code: values.code.trim() } : {}) }))
      } catch (e) {
        if (ApiError.from(e).code === 'TWO_FACTOR_REQUIRED') {
          setNeedCode(true)
          return
        }
        throw e
      }
      navigate(redirect.startsWith('/admin') ? redirect : '/admin', { replace: true })
    },
  })

  return (
    <div className="flex min-h-dvh flex-col items-center justify-center bg-slate-900 px-4 py-10">
      <Logo inverted className="mb-8" />
      <AuthCard>
        <div className="mb-4 flex size-11 items-center justify-center rounded-full bg-brand-50 text-brand-500">
          <ShieldCheck className="size-6" />
        </div>
        <AuthHeading title="Platform administration" description="Restricted to AvantPOS staff." />
        <form onSubmit={form.handleSubmit} noValidate className="space-y-4">
          <ApiErrorAlert error={form.formError} />
          <FormField label="E-mail address" error={form.errors.email}>
            <Input {...form.field('email')} type="email" autoComplete="username" autoFocus />
          </FormField>
          <FormField label="Password" error={form.errors.password}>
            <PasswordInput {...form.field('password')} autoComplete="current-password" />
          </FormField>
          {needCode && (
            <FormField label="Authenticator code" hint="The 6-digit code from your authenticator app." error={form.errors.code}>
              <Input {...form.field('code')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} autoFocus />
            </FormField>
          )}
          <Button type="submit" size="lg" fullWidth loading={form.submitting}>
            {needCode ? 'Verify and sign in' : 'Sign in'}
          </Button>
        </form>
      </AuthCard>
    </div>
  )
}
