/**
 * Display formatting for the Philippine market.
 *
 * Money arrives from the API as decimal STRINGS ("1234.50") and is only ever
 * formatted here, never calculated: all financial arithmetic happens server-side.
 */

export const DEFAULT_TIMEZONE = 'Asia/Manila'
export const DEFAULT_CURRENCY = 'PHP'
const LOCALE = 'en-PH'

const moneyFormatters = new Map<string, Intl.NumberFormat>()

/** formatMoney("1234.5") → "₱1,234.50". Accepts decimal strings without float conversion. */
export function formatMoney(amount: string | number | null | undefined, currency = DEFAULT_CURRENCY): string {
  if (amount === null || amount === undefined || amount === '') {
    return '—'
  }

  let formatter = moneyFormatters.get(currency)
  if (!formatter) {
    formatter = new Intl.NumberFormat(LOCALE, { style: 'currency', currency, minimumFractionDigits: 2 })
    moneyFormatters.set(currency, formatter)
  }

  // Intl.NumberFormat formats numeric strings exactly (no binary float rounding).
  return formatter.format(amount as unknown as number)
}

/** formatNumber(1500) → "1,500" */
export function formatNumber(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return new Intl.NumberFormat(LOCALE).format(value as unknown as number)
}

export function formatDate(iso: string | null | undefined, timeZone = DEFAULT_TIMEZONE): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeZone }).format(new Date(iso))
}

export function formatDateTime(iso: string | null | undefined, timeZone = DEFAULT_TIMEZONE): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(iso))
}

/** Whole days from now until `iso` (0 when today or past). */
export function daysUntil(iso: string | null | undefined, now: Date = new Date()): number | null {
  if (!iso) return null
  const diff = new Date(iso).getTime() - now.getTime()
  return Math.max(0, Math.ceil(diff / 86_400_000))
}

/**
 * Philippine mobile numbers for display: "+639171234567" / "09171234567" → "0917 123 4567".
 * Anything else is returned unchanged (the backend owns validation/normalisation).
 */
export function formatPhone(value: string | null | undefined): string {
  if (!value) return '—'
  const digits = value.replace(/\D/g, '')
  const local = digits.startsWith('63') && digits.length === 12 ? `0${digits.slice(2)}` : digits
  if (/^09\d{9}$/.test(local)) {
    return `${local.slice(0, 4)} ${local.slice(4, 7)} ${local.slice(7)}`
  }
  return value
}

/** "sari_sari" → "Sari sari" */
export function humanize(value: string | null | undefined): string {
  if (!value) return '—'
  const text = value.replace(/[_-]+/g, ' ').trim()
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** Initials for avatars: "Juan dela Cruz" → "JC" */
export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : ''
  return (first + last).toUpperCase()
}

/** formatPercent("12.0000") → "12%", "12.5000" → "12.5%" (display only, string-based). */
export function formatPercent(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return `${value.includes('.') ? value.replace(/\.?0+$/, '') : value}%`
}
