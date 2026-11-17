import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError, ErrorCode } from '@/lib/api/errors'
import { offlineCatalog, offlineQueue, syncQueue, type OfflineCatalog, type QueuedSale, type SyncResult } from './store'

const SYNC_EVERY_MS = 20_000
const CATALOG_EVERY_MS = 5 * 60_000

export const isNetworkError = (e: unknown) => ApiError.from(e).code === ErrorCode.Network

/**
 * Connection state, saved catalog and the offline sales queue for the selling
 * screen. "Online" means the browser has a network AND the last request to the
 * server got through.
 */
export function useOfflinePos({ branchId, userId, terminalId }: { branchId: number; userId: number; terminalId: number | null }) {
  const [browserOnline, setBrowserOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine))
  const [serverDown, setServerDown] = useState(false)
  const [catalog, setCatalog] = useState<OfflineCatalog | null>(null)
  const [queue, setQueue] = useState<QueuedSale[]>([])
  const [syncing, setSyncing] = useState(false)
  const [lastResults, setLastResults] = useState<SyncResult[]>([])
  const syncingRef = useRef(false)
  const online = browserOnline && !serverDown

  useEffect(() => {
    const up = () => {
      setBrowserOnline(true)
      setServerDown(false)
    }
    const down = () => setBrowserOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => {
      window.removeEventListener('online', up)
      window.removeEventListener('offline', down)
    }
  }, [])

  const refreshCatalog = useCallback(async () => {
    try {
      const next = await offlineCatalog.refresh(branchId)
      setCatalog(next)
      setServerDown(false)
    } catch (e) {
      if (isNetworkError(e)) setServerDown(true)
    }
  }, [branchId])

  const sync = useCallback(async (): Promise<SyncResult[] | null> => {
    if (syncingRef.current) return null
    syncingRef.current = true
    setSyncing(true)
    try {
      const { results, remaining } = await syncQueue(branchId, userId, terminalId)
      setQueue(remaining)
      setServerDown(false)
      if (results.length) setLastResults(results)
      return results
    } catch (e) {
      if (isNetworkError(e)) setServerDown(true)
      return null
    } finally {
      syncingRef.current = false
      setSyncing(false)
    }
  }, [branchId, userId, terminalId])

  const enqueue = useCallback(
    async (sale: QueuedSale) => {
      await offlineQueue.add(branchId, userId, sale)
      setQueue(await offlineQueue.list(branchId, userId))
    },
    [branchId, userId],
  )

  // First load: what is saved on the device, then a fresh catalog.
  useEffect(() => {
    let alive = true
    void Promise.all([offlineCatalog.load(branchId), offlineQueue.list(branchId, userId)]).then(([c, q]) => {
      if (!alive) return
      setCatalog(c)
      setQueue(q)
      void refreshCatalog()
    })
    return () => {
      alive = false
    }
  }, [branchId, userId, refreshCatalog])

  // Upload waiting sales and keep the catalog fresh; when the server was
  // unreachable this also notices it is back.
  const pending = queue.some((s) => !s.error)
  useEffect(() => {
    if (!browserOnline) return
    // Upload right away (next tick) when sales are waiting and the network is back.
    const now = pending ? window.setTimeout(() => void sync(), 0) : undefined
    const syncTimer = window.setInterval(() => {
      if (pending || serverDown) void (pending ? sync() : refreshCatalog())
    }, SYNC_EVERY_MS)
    const catalogTimer = window.setInterval(() => void refreshCatalog(), CATALOG_EVERY_MS)
    return () => {
      window.clearTimeout(now)
      window.clearInterval(syncTimer)
      window.clearInterval(catalogTimer)
    }
  }, [browserOnline, pending, serverDown, sync, refreshCatalog])

  return {
    online,
    catalog,
    queue,
    syncing,
    lastResults,
    clearResults: () => setLastResults([]),
    sync,
    enqueue,
    markServerDown: () => setServerDown(true),
    markServerUp: () => setServerDown(false),
  }
}
