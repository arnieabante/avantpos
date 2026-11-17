import { isRouteErrorResponse, useRouteError } from 'react-router'
import { AlertOctagon, Compass } from 'lucide-react'
import { Button, ButtonLink, EmptyState } from '@/components/ui'

export function NotFoundPage() {
  return (
    <div className="flex min-h-dvh items-center justify-center p-4">
      <EmptyState
        icon={<Compass className="size-5" />}
        title="Page not found"
        description="The page you're looking for doesn't exist or has moved."
        action={<ButtonLink to="/">Back to dashboard</ButtonLink>}
      />
    </div>
  )
}

/** Route-level error boundary: unexpected render errors and failed lazy-loaded chunks. */
export function RouteErrorPage() {
  const error = useRouteError()

  if (isRouteErrorResponse(error) && error.status === 404) {
    return <NotFoundPage />
  }

  if (import.meta.env.DEV) {
    console.error(error)
  }

  return (
    <div className="flex min-h-dvh items-center justify-center p-4">
      <EmptyState
        icon={<AlertOctagon className="size-5" />}
        title="Something went wrong"
        description="An unexpected error occurred. Reloading usually fixes it — this can happen right after AvantPOS is updated."
        action={<Button onClick={() => window.location.reload()}>Reload page</Button>}
      />
    </div>
  )
}
