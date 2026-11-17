import { Input } from '@/components/ui'
import { WEEKDAYS, type DayHours, type OperatingHours, type Weekday } from '../types'
import { DEFAULT_DAY } from '../formDefaults'

interface Props {
  value: OperatingHours
  onChange: (next: OperatingHours) => void
  errors?: Record<string, string | undefined>
  disabled?: boolean
}

/** Opening hours per weekday, with a "Closed" switch for each day. */
export function OperatingHoursEditor({ value, onChange, errors = {}, disabled }: Props) {
  const update = (day: Weekday, patch: Partial<DayHours>) => {
    const current = value[day] ?? DEFAULT_DAY
    const next: DayHours = { ...current, ...patch }
    onChange({ ...value, [day]: next.closed ? { closed: true } : { closed: false, open: next.open ?? '08:00', close: next.close ?? '20:00' } })
  }

  return (
    <div className="divide-y divide-line rounded-lg border border-line">
      {WEEKDAYS.map(({ key, label }) => {
        const day = value[key] ?? DEFAULT_DAY
        const error = errors[`operating_hours.${key}.open`] ?? errors[`operating_hours.${key}.close`]
        return (
          <div key={key} className="px-3 py-2">
            <div className="flex flex-wrap items-center gap-3">
              <span className="w-24 text-sm font-medium text-ink">{label}</span>
              <label className="flex items-center gap-2 text-sm text-ink-muted">
                <input
                  type="checkbox"
                  className="size-4 accent-brand-500"
                  checked={day.closed}
                  disabled={disabled}
                  onChange={(e) => update(key, { closed: e.target.checked })}
                />
                Closed
              </label>
              {!day.closed && (
                <div className="flex items-center gap-2">
                  <Input type="time" aria-label={`${label} opening time`} value={day.open ?? ''} disabled={disabled} onChange={(e) => update(key, { open: e.target.value })} className="h-9 w-32" />
                  <span className="text-sm text-ink-subtle">to</span>
                  <Input type="time" aria-label={`${label} closing time`} value={day.close ?? ''} disabled={disabled} onChange={(e) => update(key, { close: e.target.value })} className="h-9 w-32" />
                </div>
              )}
            </div>
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
          </div>
        )
      })}
    </div>
  )
}
