import { formatMoney } from '@/lib/format'

/**
 * Simple bar chart (inline SVG, no chart library). Values are decimal
 * strings; they are only converted to numbers to size the bars.
 */
export function BarChart({ data, height = 180, label }: { data: Array<{ label: string; value: string; hint?: string }>; height?: number; label: string }) {
  const values = data.map((d) => Math.max(0, Number(d.value)))
  const max = Math.max(...values, 0)
  const width = 100 / Math.max(data.length, 1)

  return (
    <figure aria-label={label} className="w-full">
      <svg viewBox={`0 0 100 ${height}`} preserveAspectRatio="none" className="w-full" style={{ height }} role="img">
        <title>{label}</title>
        {[0.25, 0.5, 0.75].map((f) => (
          <line key={f} x1="0" x2="100" y1={height - f * (height - 4)} y2={height - f * (height - 4)} stroke="currentColor" className="text-line-soft" strokeWidth="0.4" vectorEffect="non-scaling-stroke" />
        ))}
        {data.map((d, i) => {
          const h = max > 0 ? (values[i]! / max) * (height - 4) : 0
          return (
            <rect key={d.label} x={i * width + width * 0.15} y={height - h} width={width * 0.7} height={Math.max(h, values[i]! > 0 ? 1 : 0)} rx="0.6" className="fill-brand-500">
              <title>{`${d.hint ?? d.label}: ${formatMoney(d.value)}`}</title>
            </rect>
          )
        })}
      </svg>
      <figcaption className="mt-1 flex justify-between text-xs text-ink-subtle">
        <span>{data[0]?.hint ?? data[0]?.label}</span>
        <span>Peak {formatMoney(data[values.indexOf(max)]?.value ?? '0')}</span>
        <span>{data[data.length - 1]?.hint ?? data[data.length - 1]?.label}</span>
      </figcaption>
    </figure>
  )
}
