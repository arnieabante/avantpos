import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Ban, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui'
import { ErrorCode, type ApiError } from '@/lib/api/errors'
import { AuthCard } from './AuthLayout'
import { useAuth } from './useAuth'

/**
 * Shown when the user is signed in but may not use the business here:
 * suspended/cancelled business, deactivated user, or wrong subdomain.
 */
export function SessionBlockedScreen({ error }: { error: ApiError }) {
  const { signOut } = useAuth()
  const navigate = useNavigate()
  const [signingOut, setSigningOut] = useState(false)
  const loginUrl = typeof error.context.login_url === 'string' ? error.context.login_url : null

  const title =
    error.code === ErrorCode.TenantMismatch
      ? 'You are signed in to a different business'
      : error.code === ErrorCode.UserInactive
        ? 'Your user account is deactivated'
        : 'This business account is not available'

  async function handleSignOut() {
    setSigningOut(true)
    await signOut()
    navigate('/login', { replace: true })
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-canvas p-4">
      <AuthCard>
        <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-red-50 text-red-600">
          <Ban className="size-6" />
        </div>
        <h1 className="text-xl font-semibold text-ink">{title}</h1>
        <p className="mt-2 text-sm text-ink-muted">{error.message}</p>

        <div className="mt-6 flex flex-col gap-2">
          {error.code === ErrorCode.TenantMismatch && loginUrl && (
            <Button onClick={() => window.location.assign(loginUrl)} rightIcon={<ExternalLink className="size-4" />} fullWidth>
              Go to my business
            </Button>
          )}
          <Button variant="secondary" onClick={handleSignOut} loading={signingOut} fullWidth>
            Sign out
          </Button>
        </div>
      </AuthCard>
    </div>
  )
}
