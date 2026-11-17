import { AxiosError, type InternalAxiosRequestConfig } from 'axios'
import { describe, expect, it } from 'vitest'
import { ApiError, ErrorCode } from './errors'

const config = { headers: {} } as InternalAxiosRequestConfig

describe('ApiError.from', () => {
  it('maps network failures to NETWORK_ERROR with status 0', () => {
    const error = ApiError.from(new AxiosError('Network Error', 'ERR_NETWORK', config))
    expect(error.status).toBe(0)
    expect(error.code).toBe(ErrorCode.Network)
  })

  it('keeps the backend message, code and context', () => {
    const error = ApiError.from(
      new AxiosError('x', 'ERR_BAD_RESPONSE', config, {}, {
        status: 403,
        statusText: '',
        headers: {},
        config,
        data: { message: 'This account does not belong to this business address.', code: 'TENANT_MISMATCH', context: { login_url: 'https://a.avantpos.ph' } },
      }),
    )
    expect(error.status).toBe(403)
    expect(error.code).toBe(ErrorCode.TenantMismatch)
    expect(error.context.login_url).toBe('https://a.avantpos.ph')
  })

  it('falls back to a generic message for non-JSON server errors', () => {
    const error = ApiError.from(
      new AxiosError('x', 'ERR_BAD_RESPONSE', config, {}, { status: 502, statusText: '', headers: {}, config, data: '<html>Bad gateway</html>' }),
    )
    expect(error.code).toBe('HTTP_502')
    expect(error.message).toMatch(/something went wrong/i)
  })

  it('returns ApiError instances unchanged', () => {
    const original = new ApiError('x', { code: 'X' })
    expect(ApiError.from(original)).toBe(original)
  })
})
