import { describe, expect, it } from 'vitest'
import { daysUntil, formatMoney, formatPhone, humanize, initials } from './format'
import { pageWindow } from './pagination'
import { safeRedirectPath } from './utils'

describe('formatMoney', () => {
  it('formats decimal strings as Philippine pesos without float rounding', () => {
    expect(formatMoney('1234.5')).toBe('₱1,234.50')
    expect(formatMoney('0.10')).toBe('₱0.10')
    // A float would print 9,007,199,254,740,992.00 — strings stay exact.
    expect(formatMoney('9007199254740993.05')).toBe('₱9,007,199,254,740,993.05')
  })

  it('shows a dash for missing amounts', () => {
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney('')).toBe('—')
  })
})

describe('formatPhone', () => {
  it('formats PH mobile numbers in either notation', () => {
    expect(formatPhone('+639171234567')).toBe('0917 123 4567')
    expect(formatPhone('09171234567')).toBe('0917 123 4567')
  })

  it('leaves other numbers unchanged', () => {
    expect(formatPhone('(02) 8123 4567')).toBe('(02) 8123 4567')
  })
})

describe('helpers', () => {
  it('counts whole days remaining', () => {
    const now = new Date('2026-11-14T00:00:00Z')
    expect(daysUntil('2026-11-28T00:00:00Z', now)).toBe(14)
    expect(daysUntil('2026-11-01T00:00:00Z', now)).toBe(0)
    expect(daysUntil(null, now)).toBeNull()
  })

  it('humanizes keys and builds initials', () => {
    expect(humanize('sari_sari')).toBe('Sari sari')
    expect(initials('Juan dela Cruz')).toBe('JC')
    expect(initials('Nena')).toBe('N')
  })
})

describe('safeRedirectPath', () => {
  it('accepts in-app paths only', () => {
    expect(safeRedirectPath('/products?page=2')).toBe('/products?page=2')
    expect(safeRedirectPath('//evil.com')).toBe('/')
    expect(safeRedirectPath('/\\evil.com')).toBe('/')
    expect(safeRedirectPath('https://evil.com')).toBe('/')
    expect(safeRedirectPath(null, '/admin')).toBe('/admin')
  })
})

describe('pageWindow', () => {
  it('lists every page when there are few', () => {
    expect(pageWindow(2, 5)).toEqual([1, 2, 3, 4, 5])
  })

  it('collapses distant pages into gaps', () => {
    expect(pageWindow(10, 20)).toEqual([1, null, 9, 10, 11, null, 20])
    expect(pageWindow(1, 20)).toEqual([1, 2, null, 20])
  })
})
