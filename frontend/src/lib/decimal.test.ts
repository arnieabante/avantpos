import { describe, expect, it } from 'vitest'
import { multiplyToMoney, sumMoney } from './decimal'

describe('decimal previews', () => {
  it('multiplies exactly and rounds half up to centavos', () => {
    expect(multiplyToMoney('5', '224')).toBe('1120.00')
    expect(multiplyToMoney('3', '0.1')).toBe('0.30') // 0.1 * 3 in floats is 0.30000000000000004
    expect(multiplyToMoney('1.5', '10.005')).toBe('15.01') // 15.0075 -> 15.01
    expect(multiplyToMoney('2', '0.0025')).toBe('0.01')
    expect(multiplyToMoney('abc', '1')).toBeNull()
    expect(multiplyToMoney('1.2345', '1')).toBeNull()
  })

  it('sums amounts', () => {
    expect(sumMoney(['0.10', '0.20', null, '1120.00'])).toBe('1120.30')
    expect(sumMoney([])).toBe('0.00')
  })
})
