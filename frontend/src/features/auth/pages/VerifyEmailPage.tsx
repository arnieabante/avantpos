import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router'
import { CheckCircle2, XCircle } from 'lucide-react'
import { ButtonLink, Spinner } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { authApi } from '../api'
import { AuthCard, AuthHeading } from '../AuthLayout'
import { useAuth } from '../useAuth'

type State = { status: 'verifying' } | { status: 'success'; message: string } | { status: 'error'; message: string }

/** Opened from the verification e-mail: /verify-email?url=<signed API URL> */
export default function VerifyEmailPage() {
  const [params] = useSearchParams()
  const signedUrl = params.get('url')
  const { session, refresh } = useAuth()
  const [state, setState] = useState<State>({ status: 'verifying' })
  const started = useRef(false)

  useEffect(() => {
    // Guard against React StrictMode's double effect: verify once.
    if (started.current) return
    started.current = true

    authApi
      .verifyEmail(signedUrl ?? '')
      .then(async (message) => {
        setState({ status: 'success', message })
        await refresh()
      })
      .catch((error: unknown) => {
        const apiError = ApiError.from(error)
        const message =
          apiError.status === 403 && apiError.code !== 'VERIFICATION_INVALID'
            ? 'This verification link has expired or is invalid. Sign in and request a new one from your dashboard.'
            : apiError.message
        setState({ status: 'error', message })
      })
  }, [signedUrl, refresh])

  const signedIn = session?.status === 'authenticated'

  return (
    <AuthCard className="text-center">
      {state.status === 'verifying' && (
        <div className="flex flex-col items-center gap-4 py-6">
          <Spinner size="lg" />
          <p className="text-sm text-ink-muted">Verifying your e-mail address…</p>
        </div>
      )}

      {state.status !== 'verifying' && (
        <>
          <div
            className={
              state.status === 'success'
                ? 'mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600'
                : 'mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-red-50 text-red-600'
            }
          >
            {state.status === 'success' ? <CheckCircle2 className="size-6" /> : <XCircle className="size-6" />}
          </div>
          <AuthHeading title={state.status === 'success' ? 'E-mail verified' : 'Verification failed'} description={state.message} />
          <ButtonLink to={signedIn ? '/' : '/login'} size="lg" fullWidth>
            {signedIn ? 'Go to dashboard' : 'Sign in'}
          </ButtonLink>
        </>
      )}
    </AuthCard>
  )
}
