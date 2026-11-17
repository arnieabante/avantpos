import { useCallback, useState } from 'react'
import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useToast } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'

interface ActionOptions {
  /** Toast shown on success (omit for none). */
  success?: string
  /** Queries to refetch afterwards. */
  invalidate?: QueryKey[]
}

/**
 * For one-click actions (activate, make default, delete...): tracks which
 * action is running, refreshes the given queries and reports the outcome
 * as a toast. Returns true on success.
 */
export function useAction() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [pending, setPending] = useState<string | null>(null)

  const run = useCallback(
    async (key: string, fn: () => Promise<unknown>, options: ActionOptions = {}): Promise<boolean> => {
      setPending(key)
      try {
        await fn()
        await Promise.all((options.invalidate ?? []).map((queryKey) => queryClient.invalidateQueries({ queryKey })))
        if (options.success) toast.success(options.success)
        return true
      } catch (error) {
        toast.error(ApiError.from(error).message)
        return false
      } finally {
        setPending(null)
      }
    },
    [queryClient, toast],
  )

  return { run, pending, isPending: (key: string) => pending === key }
}
