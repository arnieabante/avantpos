/**
 * Response envelopes of the AvantPOS API (see backend `App\Support\Api\ApiResponse`).
 *
 *   success:  { data, meta? }
 *   message:  { message }
 *   error:    { message, code, errors?, context? }
 */

export interface Pagination {
  current_page?: number
  per_page?: number
  total?: number
  last_page?: number
  next_cursor?: string
  prev_cursor?: string
}

export interface ApiEnvelope<T> {
  data: T
  meta?: { pagination?: Pagination } & Record<string, unknown>
}

export interface MessageResponse {
  message: string
}

export interface ApiErrorBody {
  message?: string
  code?: string
  errors?: Record<string, string[]>
  context?: Record<string, unknown>
}

/** A page of a paginated collection, unwrapped from its envelope. */
export interface Paginated<T> {
  items: T[]
  pagination: Pagination
}
