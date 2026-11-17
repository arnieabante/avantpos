import { useState } from 'react'
import { Alert, Button, useToast } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { authApi } from './api'
import { useAccount } from './useAuth'

/**
 * Most of the API requires a verified e-mail (`actor.user:verified`),
 * so unverified users see this on every page until they verify.
 */
export function EmailVerificationBanner() {
  const { user } = useAccount()
  const toast = useToast()
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState(false)

  if (user.email_verified) return null

  async function resend() {
    setSending(true)
    try {
      toast.success(await authApi.resendVerification())
      setSent(true)
    } catch (error) {
      toast.error('Could not send the e-mail', ApiError.from(error).message)
    } finally {
      setSending(false)
    }
  }

  return (
    <Alert
      tone="warning"
      title="Please verify your e-mail address"
      className="mb-6"
      action={
        <Button size="sm" variant="secondary" onClick={resend} loading={sending} disabled={sent}>
          {sent ? 'Link sent' : 'Resend link'}
        </Button>
      }
    >
      We sent a verification link to <strong>{user.email}</strong>. Most features unlock after you verify.
    </Alert>
  )
}
