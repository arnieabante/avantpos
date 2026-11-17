import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface DropdownItem {
  key: string
  label: ReactNode
  icon?: ReactNode
  description?: ReactNode
  onSelect: () => void
  danger?: boolean
  active?: boolean
  disabled?: boolean
}

interface DropdownProps {
  /** Render prop for the trigger button; spread `props` onto a <button>. */
  trigger: (props: { onClick: () => void; 'aria-expanded': boolean; 'aria-haspopup': 'menu'; 'aria-controls': string }) => ReactNode
  items: DropdownItem[]
  header?: ReactNode
  align?: 'left' | 'right'
  className?: string
}

/** Menu button with keyboard support (Arrow keys, Home/End, Escape). */
export function Dropdown({ trigger, items, header, align = 'right', className }: DropdownProps) {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) return

    menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]:not([disabled])')?.focus()

    function onPointerDown(event: PointerEvent) {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        rootRef.current?.querySelector<HTMLElement>('[aria-haspopup="menu"]')?.focus()
      }
    }

    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  function onMenuKeyDown(event: React.KeyboardEvent) {
    const elements = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? [])
    const index = elements.indexOf(document.activeElement as HTMLElement)
    const move = (next: number) => {
      event.preventDefault()
      elements[(next + elements.length) % elements.length]?.focus()
    }
    if (event.key === 'ArrowDown') move(index + 1)
    else if (event.key === 'ArrowUp') move(index - 1)
    else if (event.key === 'Home') move(0)
    else if (event.key === 'End') move(elements.length - 1)
    else if (event.key === 'Tab') setOpen(false)
  }

  return (
    <div ref={rootRef} className={cn('relative', className)}>
      {trigger({ onClick: () => setOpen((v) => !v), 'aria-expanded': open, 'aria-haspopup': 'menu', 'aria-controls': menuId })}
      {open && (
        <div
          ref={menuRef}
          id={menuId}
          role="menu"
          onKeyDown={onMenuKeyDown}
          className={cn(
            'absolute z-40 mt-2 w-64 animate-fade-in overflow-hidden rounded-md bg-surface py-1.5 shadow-raised',
            align === 'right' ? 'right-0' : 'left-0',
          )}
        >
          {header && <div className="mb-1 border-b border-line-soft px-4 pt-1.5 pb-3">{header}</div>}
          {items.map((item) => (
            <button
              key={item.key}
              type="button"
              role="menuitem"
              disabled={item.disabled}
              onClick={() => {
                setOpen(false)
                item.onSelect()
              }}
              className={cn(
                'mx-1.5 flex w-[calc(100%-0.75rem)] items-start gap-3 rounded-md px-3 py-2 text-left text-[0.9375rem] transition-colors focus:bg-brand-500/10 focus:text-brand-500 focus:outline-none disabled:opacity-50',
                item.danger ? 'text-[#ff3e1d] hover:bg-[#ffe0db]' : 'text-ink-muted hover:bg-brand-500/10 hover:text-brand-500',
                item.active && 'bg-brand-500/10 font-medium text-brand-500',
              )}
            >
              {item.icon && <span className="mt-0.5 shrink-0 text-current opacity-70">{item.icon}</span>}
              <span className="min-w-0 flex-1">
                <span className="block truncate">{item.label}</span>
                {item.description && <span className="block truncate text-xs text-ink-subtle">{item.description}</span>}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
