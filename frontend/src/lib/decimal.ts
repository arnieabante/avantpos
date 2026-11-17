/**
 * Exact decimal arithmetic on strings via BigInt, for on-screen PREVIEWS
 * only (e.g. "estimated total" while typing an order). The server computes
 * the real figures; never send these back as amounts.
 */

const SCALE = 7 // enough for quantity (3) x unit cost (4)

function toScaled(value: string, scale = SCALE): bigint | null {
  const v = value.trim()
  const match = /^(-)?(\d+)(?:\.(\d+))?$/.exec(v)
  if (!match) return null
  const [, sign, whole, frac = ''] = match
  if (frac.length > scale) return null
  const digits = BigInt(whole + frac.padEnd(scale, '0'))
  return sign ? -digits : digits
}

function fromScaled(value: bigint, scale: number): string {
  const negative = value < 0n
  const digits = (negative ? -value : value).toString().padStart(scale + 1, '0')
  const whole = digits.slice(0, digits.length - scale)
  const frac = digits.slice(digits.length - scale)
  return `${negative ? '-' : ''}${whole}${scale > 0 ? `.${frac}` : ''}`
}

/** Round a 10^SCALE value to 2 decimals, half up (away from zero). */
function roundToCents(value: bigint): bigint {
  const factor = 10n ** BigInt(SCALE - 2)
  const half = factor / 2n
  return value >= 0n ? (value + half) / factor : -((-value + half) / factor)
}

/** qty × price rounded to centavos, as a "123.45" string; null when either is not a number. */
export function multiplyToMoney(qty: string, price: string): string | null {
  const a = toScaled(qty, 3)
  const b = toScaled(price, 4)
  if (a === null || b === null) return null
  return fromScaled(roundToCents(a * b), 2)
}

/** Sum of "123.45"-style amounts (nulls ignored). */
export function sumMoney(amounts: Array<string | null | undefined>): string {
  let total = 0n
  for (const amount of amounts) {
    const scaled = amount ? toScaled(amount, 2) : null
    if (scaled !== null) total += scaled
  }
  return fromScaled(total, 2)
}

/** a − b on "123.45"-style amounts; null when either is not a number. */
export function subtractMoney(a: string, b: string): string | null {
  const x = toScaled(a, 2)
  const y = toScaled(b, 2)
  if (x === null || y === null) return null
  return fromScaled(x - y, 2)
}

/** a + b on quantities (3 decimals), as a "1.500"-style string; null when either is not a number. */
export function addQty(a: string, b: string): string | null {
  const x = toScaled(a, 3)
  const y = toScaled(b, 3)
  if (x === null || y === null) return null
  return fromScaled(x + y, 3)
}

/** −1, 0 or 1; null when either is not a number. */
export function compareDecimal(a: string, b: string): -1 | 0 | 1 | null {
  const x = toScaled(a)
  const y = toScaled(b)
  if (x === null || y === null) return null
  return x === y ? 0 : x < y ? -1 : 1
}

/** "2.500" → "2.5", "3.000" → "3". */
export function trimQty(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value
}
