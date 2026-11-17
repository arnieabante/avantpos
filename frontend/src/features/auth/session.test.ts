import { describe, expect, it } from 'vitest'
import { makeAccount } from '@/test/utils'
import { verificationRequestPath } from './api'
import { pickActiveBranch } from './session'

describe('verificationRequestPath', () => {
  const api = 'http://localhost:8000'
  const hash = 'a'.repeat(40)

  it('accepts our signed verification URL', () => {
    expect(verificationRequestPath(`${api}/api/v1/auth/email/verify/5/${hash}?expires=1&signature=abc`, api)).toBe(
      `/api/v1/auth/email/verify/5/${hash}?expires=1&signature=abc`,
    )
  })

  it('rejects other hosts, other paths and unsigned URLs', () => {
    expect(verificationRequestPath(`https://evil.example/api/v1/auth/email/verify/5/${hash}?expires=1&signature=abc`, api)).toBeNull()
    expect(verificationRequestPath(`${api}/api/v1/auth/logout?expires=1&signature=abc`, api)).toBeNull()
    expect(verificationRequestPath(`${api}/api/v1/auth/email/verify/5/${hash}`, api)).toBeNull()
    expect(verificationRequestPath('not a url', api)).toBeNull()
    expect(verificationRequestPath(null, api)).toBeNull()
  })
})

describe('pickActiveBranch', () => {
  const account = makeAccount()

  it('uses the remembered branch when still accessible', () => {
    expect(pickActiveBranch(account, 12)?.id).toBe(12)
  })

  it('falls back to the main branch when the stored one is gone', () => {
    expect(pickActiveBranch(account, 999)?.id).toBe(11)
    expect(pickActiveBranch(account, null)?.id).toBe(11)
  })

  it('returns null when the user has no branches', () => {
    expect(pickActiveBranch(makeAccount({ branches: [] }), null)).toBeNull()
  })
})
