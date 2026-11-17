import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ScanBarcode } from 'lucide-react'
import { Input, Spinner } from '@/components/ui'
import { cn } from '@/lib/utils'
import { inventoryApi, inventoryKeys } from '../api'
import { formatQty } from '../format'
import type { PickerItem, StockVariant } from '../types'

/** Minimum shape of a picker result. */
export interface PickableItem {
  variant: StockVariant
  qty_on_hand: string
}

export interface PickerSource<T extends PickableItem> {
  key: string
  fetch: (q: string) => Promise<T[]>
}

const inventorySource: PickerSource<PickerItem> = { key: 'inventory', fetch: inventoryApi.picker }

interface ItemPickerProps<T extends PickableItem> {
  onSelect: (item: T) => void
  /** Where results come from (default: the inventory picker). */
  source?: PickerSource<T>
  placeholder?: string
  label?: string
  disabled?: boolean
  className?: string
}

/**
 * Search box for stock-tracked items. Built for barcode scanners: a scan
 * ends with Enter, which adds the first match right away and keeps the
 * focus here for the next scan.
 */
export function ItemPicker<T extends PickableItem = PickerItem>({
  onSelect,
  source = inventorySource as unknown as PickerSource<T>,
  placeholder = 'Scan a barcode or search by name / SKU…',
  label = 'Add item',
  disabled,
  className,
}: ItemPickerProps<T>) {
  const queryClient = useQueryClient()
  const listId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const [notFound, setNotFound] = useState<string | null>(null)

  useEffect(() => {
    const q = text.trim()
    const timer = setTimeout(() => setTerm(q), 250)
    return () => clearTimeout(timer)
  }, [text])

  const results = useQuery({
    queryKey: [...inventoryKeys.picker(term), source.key],
    queryFn: () => source.fetch(term),
    enabled: term.length > 0,
    staleTime: 10_000,
  })
  const items = term === text.trim() ? (results.data ?? []) : []

  const choose = (item: T) => {
    onSelect(item)
    setText('')
    setTerm('')
    setOpen(false)
    setActive(0)
    setNotFound(null)
    inputRef.current?.focus()
  }

  const onKeyDown = async (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      setOpen(true)
      setActive((i) => (items.length === 0 ? 0 : (i + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length))
      return
    }
    if (event.key === 'Escape') {
      setOpen(false)
      return
    }
    if (event.key !== 'Enter') return

    // Never submit the surrounding form from here.
    event.preventDefault()
    const q = text.trim()
    if (!q) return

    // A scanner types faster than the debounce: look the code up now.
    const found = q === term && results.data ? results.data : await queryClient.fetchQuery({ queryKey: [...inventoryKeys.picker(q), source.key], queryFn: () => source.fetch(q), staleTime: 10_000 })
    const pick = found[q === term ? active : 0] ?? found[0]
    if (pick) choose(pick)
    else setNotFound(q)
  }

  const showList = open && text.trim() !== '' && (items.length > 0 || results.isFetching)

  return (
    <div className={cn('relative', className)}>
      <Input
        ref={inputRef}
        role="combobox"
        aria-label={label}
        aria-expanded={showList}
        aria-controls={listId}
        aria-autocomplete="list"
        autoComplete="off"
        disabled={disabled}
        value={text}
        placeholder={placeholder}
        leftAddon={<ScanBarcode className="size-4" />}
        rightAddon={results.isFetching ? <Spinner size="sm" className="mr-3" /> : undefined}
        onChange={(e) => {
          setText(e.target.value)
          setOpen(true)
          setActive(0)
          setNotFound(null)
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onKeyDown={onKeyDown}
      />
      {notFound && <p className="mt-1 text-xs text-[#ff3e1d]">No stock-tracked item matches “{notFound}”.</p>}
      {showList && (
        <ul id={listId} role="listbox" className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border border-line-soft bg-surface py-1 shadow-raised">
          {items.length === 0 && <li className="px-4 py-2 text-sm text-ink-subtle">Searching…</li>}
          {items.map((item, i) => (
            <li
              key={item.variant.id}
              role="option"
              aria-selected={i === active}
              className={cn('flex cursor-pointer items-center justify-between gap-3 px-4 py-2 text-sm', i === active ? 'bg-brand-100 text-brand-500' : 'text-ink hover:bg-canvas')}
              onMouseDown={(e) => e.preventDefault()}
              onMouseEnter={() => setActive(i)}
              onClick={() => choose(item)}
            >
              <span className="min-w-0">
                <span className="block truncate font-medium">{item.variant.name}</span>
                <span className="block truncate text-xs text-ink-subtle">{item.variant.sku}</span>
              </span>
              <span className="shrink-0 text-xs text-ink-subtle">{formatQty(item.qty_on_hand, item.variant.unit)} on hand</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
