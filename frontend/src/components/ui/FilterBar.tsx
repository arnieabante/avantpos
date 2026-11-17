import { useEffect, useState, type ReactNode } from 'react'
import { Search, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Input } from './FormField'

/** Layout row for list filters: search on the left, selects/buttons on the right. */
export function FilterBar({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('flex flex-col gap-3 px-5 py-5 sm:flex-row sm:items-center', className)}>{children}</div>
}

interface SearchInputProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  /** Debounce before calling onChange, so we don't hit the API on every key. */
  debounceMs?: number
  className?: string
  label?: string
}

export function SearchInput({ value, onChange, placeholder = 'Search…', debounceMs = 300, className, label = 'Search' }: SearchInputProps) {
  const [draft, setDraft] = useState(value)
  const [lastValue, setLastValue] = useState(value)

  // Follow external resets (e.g. "clear filters"): adjust state during render.
  if (value !== lastValue) {
    setLastValue(value)
    setDraft(value)
  }

  useEffect(() => {
    if (draft === value) return
    const timer = setTimeout(() => onChange(draft), debounceMs)
    return () => clearTimeout(timer)
  }, [draft, value, onChange, debounceMs])

  return (
    <div className={cn('w-full sm:max-w-xs', className)}>
      <Input
        type="search"
        aria-label={label}
        value={draft}
        placeholder={placeholder}
        onChange={(event) => setDraft(event.target.value)}
        leftAddon={<Search className="size-4" />}
        rightAddon={
          draft ? (
            <button
              type="button"
              className="rounded-md p-2 text-ink-subtle hover:text-ink"
              aria-label="Clear search"
              onClick={() => {
                setDraft('')
                onChange('')
              }}
            >
              <X className="size-4" />
            </button>
          ) : undefined
        }
      />
    </div>
  )
}
