/**
 * Date presets for report filters, in the business's time zone (Philippines).
 * Dates are plain "YYYY-MM-DD" strings; arithmetic is done on UTC midnights.
 */
const TZ = 'Asia/Manila'

export function todayIn(tz = TZ, now = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
}

const parse = (d: string) => new Date(`${d}T00:00:00Z`)
const fmt = (d: Date) => d.toISOString().slice(0, 10)
export const addDays = (d: string, n: number) => fmt(new Date(parse(d).getTime() + n * 86_400_000))

export type Preset = 'today' | 'yesterday' | 'this_week' | 'last_7' | 'this_month' | 'last_month' | 'last_30' | 'this_year'

export const PRESETS: Array<{ key: Preset; label: string }> = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'this_week', label: 'This week' },
  { key: 'last_7', label: 'Last 7 days' },
  { key: 'this_month', label: 'This month' },
  { key: 'last_month', label: 'Last month' },
  { key: 'last_30', label: 'Last 30 days' },
  { key: 'this_year', label: 'This year' },
]

export function presetRange(preset: Preset, today = todayIn()): { from: string; to: string } {
  const monthStart = `${today.slice(0, 8)}01`
  switch (preset) {
    case 'today':
      return { from: today, to: today }
    case 'yesterday':
      return { from: addDays(today, -1), to: addDays(today, -1) }
    case 'this_week': {
      const back = (parse(today).getUTCDay() + 6) % 7 // weeks start on Monday
      return { from: addDays(today, -back), to: today }
    }
    case 'last_7':
      return { from: addDays(today, -6), to: today }
    case 'this_month':
      return { from: monthStart, to: today }
    case 'last_month': {
      const end = addDays(monthStart, -1)
      return { from: `${end.slice(0, 8)}01`, to: end }
    }
    case 'last_30':
      return { from: addDays(today, -29), to: today }
    case 'this_year':
      return { from: `${today.slice(0, 4)}-01-01`, to: today }
  }
}
