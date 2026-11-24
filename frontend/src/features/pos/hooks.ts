import { useEffect, useState, useSyncExternalStore } from 'react'

/** True while the screen is phone-sized (narrower than Tailwind's `md`, 768px). */
export function useIsPhone(): boolean {
  const query = '(max-width: 767px)'
  return useSyncExternalStore(
    (onChange) => {
      const list = window.matchMedia(query)
      list.addEventListener('change', onChange)
      return () => list.removeEventListener('change', onChange)
    },
    () => window.matchMedia(query).matches,
    () => false,
  )
}

/** `value`, updated only after it has stopped changing for `delay` ms. */
export function useDebouncedValue<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay)
    return () => window.clearTimeout(timer)
  }, [value, delay])
  return debounced
}

const storageKey = (sessionId: number) => `avantpos.pos.cart.${sessionId}`

/** The cart in progress survives a page reload (never with the Senior/PWD ID number). */
export const cartStorage = {
  load(sessionId: number): unknown {
    try {
      const raw = window.localStorage.getItem(storageKey(sessionId))
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  },
  save(sessionId: number, payload: unknown | null): void {
    try {
      if (payload === null) window.localStorage.removeItem(storageKey(sessionId))
      else window.localStorage.setItem(storageKey(sessionId), JSON.stringify(payload))
    } catch {
      // Private mode / storage full: the cart just is not kept across reloads.
    }
  },
}
