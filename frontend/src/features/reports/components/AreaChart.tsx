import { useId } from 'react'
import { formatMoney } from '@/lib/format'

/** AvantPOS logo colors: sky blue fill, navy line. */
const FILL = '#409be6'
const LINE = '#034f89'

/** Short axis label: 1,200 -> 1.2k, 3,400,000 -> 3.4M. */
function compact(n: number): string {
  if (n >= 1_000_000) return `${+(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000) return `${+(n / 1_000).toFixed(1)}k`
  return String(Math.round(n))
}

/** Rounds the top of the axis up to a tidy number so gridlines land on clean values. */
function niceMax(max: number): number {
  if (max <= 0) return 1
  const step = 10 ** Math.floor(Math.log10(max))
  return Math.ceil(max / step) * step
}

/**
 * Area chart (inline SVG, no chart library). Values are decimal strings;
 * they are only converted to numbers to plot the line.
 */
export function AreaChart({ data, height = 180, label }: { data: Array<{ label: string; value: string; hint?: string }>; height?: number; label: string }) {
  const gradientId = useId()
  const values = data.map((d) => Math.max(0, Number(d.value)))
  const peak = Math.max(...values, 0)
  const top = niceMax(peak)
  const n = data.length
  const x = (i: number) => (n > 1 ? (i / (n - 1)) * 100 : 50)
  const y = (v: number) => height - (v / top) * (height - 4)

  const line = values.map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i)},${y(v)}`).join(' ')
  const area = n > 0 ? `${line} L${x(n - 1)},${height} L${x(0)},${height} Z` : ''
  const ticks = [0, 0.25, 0.5, 0.75, 1]

  return (
    <figure aria-label={label} className="w-full">
      <div className="flex gap-2">
        <div className="relative w-9 shrink-0 text-right text-[10px] tabular-nums text-ink-subtle" style={{ height }} aria-hidden="true">
          {ticks.map((f) => (
            <span key={f} className="absolute right-0 -translate-y-1/2" style={{ top: y(f * top) }}>
              {compact(f * top)}
            </span>
          ))}
        </div>
        <svg viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" className="w-full min-w-0 overflow-visible" style={{ height }} role="img">
          <title>{label}</title>
          <defs>
            <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor={FILL} stopOpacity="0.85" />
              <stop offset="100%" stopColor={FILL} stopOpacity="0.15" />
            </linearGradient>
          </defs>
          {ticks.map((f) => (
            <line key={f} x1="0" x2="100" y1={y(f * top)} y2={y(f * top)} stroke="currentColor" className="text-line-soft" strokeWidth="1" vectorEffect="non-scaling-stroke" />
          ))}
          {n > 0 && <path d={area} fill={`url(#${gradientId})`} />}
          {n > 0 && <path d={line} fill="none" stroke={LINE} strokeWidth="2" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />}
          {data.map((d, i) => (
            <rect key={d.label} x={x(i) - 50 / Math.max(n, 1)} y="0" width={100 / Math.max(n, 1)} height={height} fill="transparent">
              <title>{`${d.hint ?? d.label}: ${formatMoney(d.value)}`}</title>
            </rect>
          ))}
        </svg>
      </div>
      <figcaption className="mt-1 flex justify-between pl-11 text-xs text-ink-subtle">
        <span>{data[0]?.hint ?? data[0]?.label}</span>
        <span>Peak {formatMoney(data[values.indexOf(peak)]?.value ?? '0')}</span>
        <span>{data[n - 1]?.hint ?? data[n - 1]?.label}</span>
      </figcaption>
    </figure>
  )
}
