import { useId, useState, type KeyboardEvent } from 'react'
import { ScanBarcode, X } from 'lucide-react'
import { cn } from '@/lib/utils'

interface BarcodeInputProps {
  value: string[]
  onChange: (codes: string[]) => void
  max?: number
  disabled?: boolean
  invalid?: boolean
  label?: string
  id?: string
}

const VALID = /^[A-Za-z0-9._\-/]+$/

/**
 * Barcodes as removable chips. Built for USB/Bluetooth scanners, which type
 * the code and press Enter: Enter (or comma / leaving the field) adds the
 * code instead of submitting the form. Duplicates are ignored.
 */
export function BarcodeInput({ value, onChange, max = 10, disabled, invalid, label = 'Barcodes', id }: BarcodeInputProps) {
  const [draft, setDraft] = useState('')
  const [hint, setHint] = useState<string | null>(null)
  const generatedId = useId()
  const inputId = id ?? generatedId

  function add(raw: string) {
    const code = raw.trim()
    if (!code) return
    if (!VALID.test(code) || code.length < 3 || code.length > 64) {
      setHint('Use 3–64 letters, numbers, dashes, dots or slashes.')
      return
    }
    if (value.includes(code)) {
      setHint(`${code} is already listed.`)
      setDraft('')
      return
    }
    if (value.length >= max) {
      setHint(`Up to ${max} barcodes.`)
      return
    }
    onChange([...value, code])
    setDraft('')
    setHint(null)
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault() // never submit the product form from a scan
      add(draft)
    } else if (event.key === 'Backspace' && draft === '' && value.length > 0) {
      onChange(value.slice(0, -1))
    }
  }

  return (
    <div>
      <div
        className={cn(
          'flex min-h-[2.375rem] flex-wrap items-center gap-1.5 rounded-md border bg-surface px-2 py-1 transition-[border-color,box-shadow] focus-within:border-brand-500 focus-within:shadow-focus',
          invalid ? 'border-[#ff3e1d]' : 'border-line',
          disabled && 'bg-[#eceef1]',
        )}
      >
        <ScanBarcode className="ml-1 size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
        {value.map((code) => (
          <span key={code} className="inline-flex items-center gap-1 rounded bg-brand-100 px-2 py-0.5 font-mono text-[0.8125rem] text-brand-500">
            {code}
            {!disabled && (
              <button type="button" onClick={() => onChange(value.filter((c) => c !== code))} aria-label={`Remove barcode ${code}`} className="rounded hover:text-brand-700">
                <X className="size-3.5" />
              </button>
            )}
          </span>
        ))}
        <input
          id={inputId}
          aria-label={label}
          value={draft}
          disabled={disabled}
          onChange={(e) => {
            setDraft(e.target.value)
            setHint(null)
          }}
          onKeyDown={onKeyDown}
          onBlur={() => add(draft)}
          placeholder={value.length === 0 ? 'Scan or type, then Enter' : ''}
          className="min-w-32 flex-1 bg-transparent py-1 text-[0.9375rem] text-ink-muted outline-none placeholder:text-ink-subtle"
          autoComplete="off"
          spellCheck={false}
        />
      </div>
      {hint && <p className="mt-1 text-[0.8125rem] text-[#e6a000]">{hint}</p>}
    </div>
  )
}
