import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '@/lib/api/errors'

export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        // Retry only network/server failures; 4xx answers are final.
        retry: (failureCount, error) => {
          const { status } = ApiError.from(error)
          return (status === 0 || status >= 500) && failureCount < 2
        },
        refetchOnWindowFocus: true,
      },
      mutations: { retry: false },
    },
  })
}
