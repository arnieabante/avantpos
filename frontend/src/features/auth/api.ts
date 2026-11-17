import { api, http } from '@/lib/api/client'
import { ApiError } from '@/lib/api/errors'
import { env } from '@/lib/env'
import type {
  AcceptInvitationPayload,
  Account,
  InvitationPreview,
  LoginPayload,
  RegisterPayload,
  ResetPasswordPayload,
  SetPinPayload,
} from './types'

const BASE = '/api/v1/auth'

export const authApi = {
  /** Current session. 401 when signed out (callers handle it; no global event). */
  me: () => api.get<Account>(`${BASE}/me`, { skipSessionEvents: true, skipBranchHeader: true }),

  login: (payload: LoginPayload) => api.post<Account>(`${BASE}/login`, payload),

  register: (payload: RegisterPayload) =>
    api.post<Account>(`${BASE}/register`, {
      ...payload,
      // Optional fields: send null rather than "" so the API treats them as absent.
      subdomain: payload.subdomain.trim() || null,
      business_type: payload.business_type || null,
    }),

  logout: () => api.send('post', `${BASE}/logout`, undefined, { skipSessionEvents: true }),

  forgotPassword: (email: string) => api.send('post', `${BASE}/forgot-password`, { email }),

  resetPassword: (payload: ResetPasswordPayload) => api.send('post', `${BASE}/reset-password`, payload),

  resendVerification: () => api.send('post', `${BASE}/email/verification-notification`),

  /**
   * Completes e-mail verification by calling the signed API URL from the e-mail
   * (the SPA page receives it as ?url=...). Only our own verify endpoint is accepted.
   */
  verifyEmail: async (signedUrl: string): Promise<string> => {
    const path = verificationRequestPath(signedUrl)
    if (!path) {
      throw new ApiError('This verification link is invalid.', { status: 400, code: 'VERIFICATION_INVALID' })
    }
    const response = await http.get<{ message: string }>(path, { skipSessionEvents: true })
    return response.data.message
  },

  invitation: (token: string) => api.get<InvitationPreview>(`${BASE}/invitations/${encodeURIComponent(token)}`),

  acceptInvitation: (token: string, payload: AcceptInvitationPayload) =>
    api.post<Account>(`${BASE}/invitations/${encodeURIComponent(token)}/accept`, {
      ...payload,
      phone: payload.phone.trim() || null,
    }),

  setPin: (payload: SetPinPayload) => api.send('put', `${BASE}/pin`, payload),

  updateProfile: (payload: { name: string; phone: string }) =>
    api.put<Account>(`${BASE}/profile`, { name: payload.name, phone: payload.phone.trim() || null }),

  changePassword: (payload: { current_password: string; password: string; password_confirmation: string }) =>
    api.send('put', `${BASE}/password`, payload),
}

const VERIFY_PATH = /^\/api\/v1\/auth\/email\/verify\/\d+\/[a-f0-9]{40}$/

/**
 * Returns "/api/v1/auth/email/verify/{id}/{hash}?expires=..&signature=.." when
 * `signedUrl` points at OUR API's verification endpoint, otherwise null.
 * Prevents the page from being abused to make credentialed requests elsewhere.
 */
export function verificationRequestPath(signedUrl: string | null | undefined, apiUrl: string = env.apiUrl): string | null {
  if (!signedUrl) return null

  let url: URL
  let api: URL
  try {
    url = new URL(signedUrl)
    api = new URL(apiUrl)
  } catch {
    return null
  }

  if (url.origin !== api.origin || !VERIFY_PATH.test(url.pathname)) return null
  if (!url.searchParams.get('signature') || !url.searchParams.get('expires')) return null

  return `${url.pathname}${url.search}`
}
