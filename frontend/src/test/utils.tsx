import { render } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createMemoryRouter, RouterProvider, type RouteObject } from 'react-router'
import { AxiosError, AxiosHeaders, type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { ConfirmProvider, ToastProvider } from '@/components/ui'
import { http } from '@/lib/api/client'
import type { Account } from '@/features/auth/types'

export function renderRoutes(routes: RouteObject[], initialPath = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const router = createMemoryRouter(routes, { initialEntries: [initialPath] })

  const result = render(
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <ConfirmProvider>
          <RouterProvider router={router} />
        </ConfirmProvider>
      </ToastProvider>
    </QueryClientProvider>,
  )

  return { ...result, router, queryClient }
}

/* -------------------------------------------------------------------------- */
/* Fake HTTP backend for the real axios instance                               */
/* -------------------------------------------------------------------------- */

export interface FakeRequest {
  method: string
  url: string
  headers: Record<string, string>
  data: unknown
  /** Query parameters passed as axios `params`. */
  params: Record<string, unknown>
}

type Handler = (request: FakeRequest) => { status: number; data?: unknown } | undefined

/**
 * Replaces the axios adapter so the real client (interceptors included) runs
 * against scripted responses. Returns the list of requests made.
 */
export function fakeBackend(handler: Handler) {
  const requests: FakeRequest[] = []
  const original = http.defaults.adapter

  const adapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
    const headers = AxiosHeaders.from(config.headers).toJSON() as Record<string, string>
    const request: FakeRequest = {
      method: (config.method ?? 'get').toUpperCase(),
      url: config.url ?? '',
      headers,
      data: typeof config.data === 'string' ? JSON.parse(config.data) : config.data,
      params: (config.params ?? {}) as Record<string, unknown>,
    }
    requests.push(request)

    const reply = handler(request) ?? { status: 404, data: { message: 'Resource not found.', code: 'NOT_FOUND' } }
    const response: AxiosResponse = { data: reply.data ?? null, status: reply.status, statusText: '', headers: {}, config }

    // status 0 = the server could not be reached (no response at all).
    if (reply.status === 0) throw new AxiosError('Network Error', AxiosError.ERR_NETWORK, config)
    if (reply.status >= 200 && reply.status < 300) return response
    throw new AxiosError(`Request failed with status code ${reply.status}`, AxiosError.ERR_BAD_RESPONSE, config, {}, response)
  }

  http.defaults.adapter = adapter

  return {
    requests,
    restore: () => {
      http.defaults.adapter = original
    },
  }
}

export function clearCookies() {
  document.cookie.split('; ').forEach((cookie) => {
    const name = cookie.split('=')[0]
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  })
}

export function makeAccount(overrides: Partial<Account> = {}): Account {
  return {
    user: {
      id: 7,
      name: 'Arnel Santos',
      email: 'owner@motoshop.test',
      phone: '+639171234567',
      email_verified: true,
      has_pin: false,
      must_change_password: false,
      is_owner: true,
      last_login_at: '2026-11-14T01:00:00+00:00',
    },
    tenant: {
      id: 3,
      name: 'Arnel Motoshop',
      subdomain: 'arnelmotoshop',
      status: 'active',
      business_type: 'motor_shop',
      vat_registered: true,
      timezone: 'Asia/Manila',
      currency: 'PHP',
      logo_url: null,
    },
    roles: ['Business Owner'],
    permissions: ['business.view', 'pos.sell'],
    branches: [
      { id: 11, code: 'MAIN', name: 'Main Branch', is_main: true },
      { id: 12, code: 'CALOOCAN', name: 'Caloocan', is_main: false },
    ],
    subscription: {
      status: 'trialing',
      billing_cycle: 'monthly',
      trial_ends_at: '2026-11-28T00:00:00+00:00',
      current_period_end: null,
      plan: {
        code: 'professional',
        name: 'Professional',
        limits: { branches: 3, users: 15, terminals: 5, products: null },
        features: { pos: true },
      },
    },
    ...overrides,
  }
}
