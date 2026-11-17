import { multiplyToMoney } from '@/lib/decimal'
import { formatMoney } from '@/lib/format'
import { countTotal, type Counts } from '../count'
import { DENOMINATIONS } from '../types'

/** Bill and coin count grid: type how many of each, see the running total. */
export function DenominationCounter({ value, onChange, disabled }: { value: Counts; onChange: (next: Counts) => void; disabled?: boolean }) {
  const total = countTotal(value)

  return (
    <div>
      <div className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        {(['bill', 'coin'] as const).map((kind) => (
          <div key={kind} className="space-y-2">
            <p className="text-xs font-medium tracking-wide text-ink-muted uppercase">{kind === 'bill' ? 'Bills' : 'Coins'}</p>
            {DENOMINATIONS.filter((d) => d.kind === kind).map((d) => {
              const pieces = value[d.key] ?? ''
              const valid = pieces === '' || /^\d+$/.test(pieces)
              const line = valid && pieces ? multiplyToMoney(pieces, d.value) : null
              return (
                <div key={d.key} className="grid grid-cols-[4.5rem_1fr_6rem] items-center gap-3">
                  <label htmlFor={`count-${d.key}`} className="text-sm font-medium text-ink">
                    {d.label}
                  </label>
                  <input
                    id={`count-${d.key}`}
                    aria-label={`Number of ${d.label} ${d.kind}s`}
                    inputMode="numeric"
                    disabled={disabled}
                    value={pieces}
                    placeholder="0"
                    aria-invalid={!valid}
                    onChange={(e) => onChange({ ...value, [d.key]: e.target.value.replace(/[^\d]/g, '') })}
                    className="h-9 w-full rounded-md border border-line bg-surface px-3 text-right text-sm text-ink focus:border-brand-500 focus:shadow-focus focus:outline-none"
                  />
                  <span className="text-right text-sm text-ink-muted">{line ? formatMoney(line) : '—'}</span>
                </div>
              )
            })}
          </div>
        ))}
      </div>
      <p className="mt-4 flex items-baseline justify-end gap-3 border-t border-line-soft pt-3">
        <span className="text-sm text-ink-muted">Total counted</span>
        <span className="text-xl font-medium text-ink" data-testid="count-total">
          {formatMoney(total)}
        </span>
      </p>
    </div>
  )
}
