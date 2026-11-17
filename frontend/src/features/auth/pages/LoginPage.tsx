import { Link, useNavigate, useSearchParams } from 'react-router'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Checkbox, FormField, Input, PasswordInput } from '@/components/ui'
import { useForm } from '@/hooks/useForm'
import { safeRedirectPath } from '@/lib/utils'
import { authApi } from '../api'
import { AuthCard, AuthHeading } from '../AuthLayout'
import { useAuth } from '../useAuth'

export default function LoginPage() {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const redirectTo = safeRedirectPath(params.get('redirect'))

  const form = useForm({
    initialValues: { email: '', password: '', remember: false },
    validate: (v) => ({
      email: v.email.trim() ? undefined : 'Enter your e-mail address.',
      password: v.password ? undefined : 'Enter your password.',
    }),
    onSubmit: async (values) => {
      const account = await authApi.login({ ...values, email: values.email.trim() })
      signIn(account)
      navigate(redirectTo, { replace: true })
    },
  })

  return (
    <AuthCard>
      <AuthHeading title="Sign in" description="Welcome back! Sign in to your business account." />

      <form onSubmit={form.handleSubmit} noValidate className="space-y-4">
        <ApiErrorAlert error={form.formError} />

        <FormField label="E-mail address" error={form.errors.email}>
          <Input {...form.field('email')} type="email" autoComplete="username" inputMode="email" autoFocus />
        </FormField>

        <FormField
          label="Password"
          error={form.errors.password}
          labelAction={
            <Link to="/forgot-password" className="text-sm font-medium text-brand-500 hover:underline">
              Forgot password?
            </Link>
          }
        >
          <PasswordInput {...form.field('password')} autoComplete="current-password" />
        </FormField>

        <Checkbox {...form.checkbox('remember')} label="Keep me signed in on this device" />

        <Button type="submit" size="lg" fullWidth loading={form.submitting}>
          Sign in
        </Button>
      </form>

      <p className="mt-6 text-center text-sm text-ink-muted">
        New to AvantPOS?{' '}
        <Link to="/register" className="font-medium text-brand-500 hover:underline">
          Register your business
        </Link>
      </p>
    </AuthCard>
  )
}
