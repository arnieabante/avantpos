import { isAxiosError } from 'axios'
import type { ApiErrorBody } from './types'

/** Error codes the UI reacts to specifically (see backend exceptions). */
export const ErrorCode = {
  Network: 'NETWORK_ERROR',
  Unknown: 'UNKNOWN_ERROR',
  Validation: 'VALIDATION_ERROR',
  Unauthenticated: 'UNAUTHENTICATED',
  Forbidden: 'FORBIDDEN',
  NotFound: 'NOT_FOUND',
  CsrfMismatch: 'CSRF_TOKEN_MISMATCH',
  TooManyRequests: 'TOO_MANY_REQUESTS',
  EmailNotVerified: 'EMAIL_NOT_VERIFIED',
  TenantSuspended: 'TENANT_SUSPENDED',
  TenantCancelled: 'TENANT_CANCELLED',
  TenantMismatch: 'TENANT_MISMATCH',
  UserInactive: 'USER_INACTIVE',
  BranchForbidden: 'BRANCH_FORBIDDEN',
  BranchRequired: 'BRANCH_REQUIRED',
} as const

/** Codes meaning "this signed-in user can no longer use this business here". */
export const SESSION_BLOCKING_CODES: readonly string[] = [
  ErrorCode.TenantSuspended,
  ErrorCode.TenantCancelled,
  ErrorCode.TenantMismatch,
  ErrorCode.UserInactive,
]

/**
 * Every failed API call is turned into an ApiError, so components only ever
 * deal with one shape: status, machine-readable code, message, field errors.
 */
export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly errors: Record<string, string[]>
  readonly context: Record<string, unknown>

  constructor(
    message: string,
    options: {
      status?: number
      code?: string
      errors?: Record<string, string[]>
      context?: Record<string, unknown>
    } = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = options.status ?? 0
    this.code = options.code ?? ErrorCode.Unknown
    this.errors = options.errors ?? {}
    this.context = options.context ?? {}
  }

  /** First validation message for a field, e.g. `fieldError('email')`. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }

  get isValidation(): boolean {
    return this.code === ErrorCode.Validation
  }

  static from(error: unknown): ApiError {
    if (error instanceof ApiError) {
      return error
    }

    if (isAxiosError(error)) {
      if (!error.response) {
        return new ApiError(
          'Cannot reach the AvantPOS server. Check your internet connection and try again.',
          { code: ErrorCode.Network },
        )
      }

      const { status } = error.response
      const body = (error.response.data ?? {}) as ApiErrorBody
      const isObject = typeof body === 'object' && body !== null

      return new ApiError(
        (isObject && typeof body.message === 'string' && body.message) || fallbackMessage(status),
        {
          status,
          code: (isObject && body.code) || `HTTP_${status}`,
          errors: (isObject && body.errors) || {},
          context: (isObject && body.context) || {},
        },
      )
    }

    return new ApiError(error instanceof Error ? error.message : 'Something went wrong.')
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

function fallbackMessage(status: number): string {
  if (status >= 500) return 'Something went wrong on our side. Please try again.'
  if (status === 404) return 'Resource not found.'
  if (status === 403) return 'You do not have permission to perform this action.'
  return 'Request failed.'
}
