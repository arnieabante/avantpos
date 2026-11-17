import { Alert } from '@/components/ui'
import { ErrorCode, type ApiError } from '@/lib/api/errors'

/** Standard rendering of a non-field API error inside forms and pages. */
export function ApiErrorAlert({ error, className }: { error: ApiError | null | undefined; className?: string }) {
  if (!error) return null

  const loginUrl = typeof error.context.login_url === 'string' ? error.context.login_url : null
  const tone = error.code === ErrorCode.TooManyRequests || error.code === ErrorCode.Network ? 'warning' : 'danger'

  return (
    <Alert tone={tone} className={className}>
      {error.message}
      {error.code === ErrorCode.TenantMismatch && loginUrl && (
        <>
          {' '}
          <a href={loginUrl} className="font-medium underline underline-offset-2">
            Go to your business login
          </a>
        </>
      )}
    </Alert>
  )
}
