import axios, { type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'
import { env } from '@/lib/env'
import { ApiError, ErrorCode, SESSION_BLOCKING_CODES } from './errors'
import type { ApiEnvelope, MessageResponse, Paginated } from './types'

/**
 * The single HTTP client of the SPA.
 *
 * Authentication is Laravel Sanctum's SPA cookie flow:
 *   1. GET /sanctum/csrf-cookie sets the XSRF-TOKEN cookie (done lazily below),
 *   2. axios echoes it back in the X-XSRF-TOKEN header on every request,
 *   3. the session cookie is sent because of `withCredentials`.
 * No token is ever stored in localStorage.
 */

declare module 'axios' {
  interface AxiosRequestConfig {
    /** Internal: request was already retried after refreshing the CSRF cookie. */
    _csrfRetried?: boolean
    /** Do not broadcast 401/blocked-session events for this request (e.g. GET /auth/me). */
    skipSessionEvents?: boolean
    /** Do not send X-Branch-Id (requests that are not branch-specific, e.g. GET /auth/me). */
    skipBranchHeader?: boolean
  }
}

export const BRANCH_HEADER = 'X-Branch-Id'
const XSRF_COOKIE = 'XSRF-TOKEN'

export const http = axios.create({
  baseURL: env.apiUrl,
  withCredentials: true,
  // Send X-XSRF-TOKEN to the API even though it is on another origin (api.avantpos.ph).
  withXSRFToken: true,
  xsrfCookieName: XSRF_COOKIE,
  xsrfHeaderName: 'X-XSRF-TOKEN',
  timeout: 30_000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

/* -------------------------------------------------------------------------- */
/* Session events                                                              */
/* -------------------------------------------------------------------------- */

/** Which API (and therefore which login) a request belongs to. */
export type ApiScope = 'tenant' | 'admin'

export type SessionEvent =
  | { type: 'unauthenticated'; scope: ApiScope }
  | { type: 'blocked'; scope: ApiScope; error: ApiError }
  /** The selected branch was deactivated or access was removed. */
  | { type: 'branch-invalid'; scope: ApiScope }
  /** The account must set its own password first (password chosen by someone else). */
  | { type: 'password-change-required'; scope: ApiScope }

type Listener = (event: SessionEvent) => void
const listeners = new Set<Listener>()

/** Subscribe to "you were logged out" / "your business is no longer accessible" signals. */
export function onSessionEvent(listener: Listener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function emit(event: SessionEvent): void {
  listeners.forEach((listener) => listener(event))
}

export function scopeOf(url: string | undefined): ApiScope {
  return (url ?? '').replace(/^https?:\/\/[^/]+/, '').startsWith('/api/admin/') ? 'admin' : 'tenant'
}

/* -------------------------------------------------------------------------- */
/* Branch context                                                              */
/* -------------------------------------------------------------------------- */

let activeBranchId: number | null = null

/** Sets the X-Branch-Id sent with every tenant request (validated server-side). */
export function setActiveBranchId(id: number | null): void {
  activeBranchId = id
}

export function getActiveBranchId(): number | null {
  return activeBranchId
}

/* -------------------------------------------------------------------------- */
/* CSRF                                                                        */
/* -------------------------------------------------------------------------- */

let csrfRequest: Promise<void> | null = null

function hasXsrfCookie(): boolean {
  return document.cookie.split('; ').some((c) => c.startsWith(`${XSRF_COOKIE}=`))
}

/** Makes sure the XSRF-TOKEN cookie exists (one request even when called concurrently). */
export function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && hasXsrfCookie()) {
    return Promise.resolve()
  }

  csrfRequest ??= http
    .get('/sanctum/csrf-cookie', { skipSessionEvents: true })
    .then(() => undefined)
    .finally(() => {
      csrfRequest = null
    })

  return csrfRequest
}

const SAFE_METHODS = ['get', 'head', 'options']

http.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
  if (activeBranchId !== null && !config.skipBranchHeader && scopeOf(config.url) === 'tenant') {
    config.headers.set(BRANCH_HEADER, String(activeBranchId))
  }

  const method = (config.method ?? 'get').toLowerCase()
  if (!SAFE_METHODS.includes(method) && !config.url?.includes('/sanctum/csrf-cookie')) {
    await ensureCsrfCookie()
  }

  return config
})

http.interceptors.response.use(
  (response) => response,
  async (error: unknown) => {
    const apiError = ApiError.from(error)
    const config = (axios.isAxiosError(error) ? error.config : undefined) as
      | (InternalAxiosRequestConfig & AxiosRequestConfig)
      | undefined

    // Expired CSRF token (e.g. tab left open overnight): refresh once and retry.
    if (apiError.code === ErrorCode.CsrfMismatch && config && !config._csrfRetried) {
      config._csrfRetried = true
      await ensureCsrfCookie(true)
      return http.request(config)
    }

    if (config && !config.skipSessionEvents) {
      const scope = scopeOf(config.url)

      if (apiError.status === 401) {
        emit({ type: 'unauthenticated', scope })
      } else if (apiError.status === 403 && SESSION_BLOCKING_CODES.includes(apiError.code)) {
        emit({ type: 'blocked', scope, error: apiError })
      } else if (apiError.code === ErrorCode.BranchForbidden) {
        emit({ type: 'branch-invalid', scope })
      } else if (apiError.code === 'PASSWORD_CHANGE_REQUIRED') {
        emit({ type: 'password-change-required', scope })
      }
    }

    return Promise.reject(apiError)
  },
)

/* -------------------------------------------------------------------------- */
/* Typed helpers — always resolve to the unwrapped `data`                      */
/* -------------------------------------------------------------------------- */

export const api = {
  async get<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await http.get<ApiEnvelope<T>>(url, config)
    return response.data.data
  },

  async getPage<T>(url: string, params?: Record<string, unknown>, config?: AxiosRequestConfig): Promise<Paginated<T>> {
    const response = await http.get<ApiEnvelope<T[]>>(url, { ...config, params })
    return { items: response.data.data, pagination: response.data.meta?.pagination ?? {} }
  },

  async post<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await http.post<ApiEnvelope<T>>(url, body, config)
    return response.data.data
  },

  async put<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await http.put<ApiEnvelope<T>>(url, body, config)
    return response.data.data
  },

  async patch<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await http.patch<ApiEnvelope<T>>(url, body, config)
    return response.data.data
  },

  async delete(url: string, config?: AxiosRequestConfig): Promise<void> {
    await http.delete(url, config)
  },

  /** For endpoints that answer `{ message }` only. */
  async send(method: 'post' | 'put' | 'patch' | 'delete', url: string, body?: unknown, config?: AxiosRequestConfig): Promise<string> {
    const response = await http.request<MessageResponse>({ ...config, method, url, data: body })
    return response.data?.message ?? ''
  },
}
