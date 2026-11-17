import { localStore } from '@/lib/localStore'
import { uuid } from '@/lib/uuid'
import { api } from '@/lib/api/client'
import { ApiError, ErrorCode } from '@/lib/api/errors'
import type { PosItem, Sale } from '../types'

const V1 = '/api/v1'

/* ------------------------------------------------------------------ cached reads */

/**
 * Read from the server and keep a copy; when the server cannot be reached,
 * answer with the copy (so the POS still opens offline).
 */
export async function cachedRead<T>(key: string, read: () => Promise<T>): Promise<T> {
  try {
    const fresh = await read()
    await localStore.set(`cache.${key}`, fresh)
    return fresh
  } catch (e) {
    if (ApiError.from(e).code === ErrorCode.Network) {
      const copy = await localStore.get<T>(`cache.${key}`)
      if (copy !== null) return copy
    }
    throw e
  }
}

/* ------------------------------------------------------------------ catalog */

export interface OfflineItem extends PosItem {
  barcodes: Array<{ code: string; unit_id: number | null }>
}

export interface OfflineCatalog {
  branchId: number
  version: string
  savedAt: string
  items: OfflineItem[]
}

const catalogKey = (branchId: number) => `catalog.${branchId}`

export const offlineCatalog = {
  load: (branchId: number) => localStore.get<OfflineCatalog>(catalogKey(branchId)),

  /** Download the catalog when it changed (the server answers "unchanged" otherwise). */
  async refresh(branchId: number): Promise<OfflineCatalog | null> {
    const current = await offlineCatalog.load(branchId)
    const res = await api.get<{ version: string; unchanged: boolean; items?: OfflineItem[] }>(`${V1}/pos/catalog`, {
      params: current ? { version: current.version } : {},
    })
    if (res.unchanged && current) {
      const touched = { ...current, savedAt: new Date().toISOString() }
      await localStore.set(catalogKey(branchId), touched)
      return touched
    }
    const next: OfflineCatalog = { branchId, version: res.version, savedAt: new Date().toISOString(), items: res.items ?? [] }
    await localStore.set(catalogKey(branchId), next)
    return next
  },
}

/** Name / SKU / barcode search over the saved catalog. */
export function searchCatalog(items: OfflineItem[], q: string, categoryId: number | null): OfflineItem[] {
  const term = q.trim().toLowerCase()
  return items
    .filter((i) => (term ? i.name.toLowerCase().includes(term) || i.sku.toLowerCase().includes(term) || i.barcodes.some((b) => b.code === q.trim()) : true))
    .filter((i) => (!term && categoryId ? i.category_id === categoryId : true))
    .slice(0, 60)
}

/** Exact barcode or SKU; a pack/box barcode selects that unit. */
export function scanCatalog(items: OfflineItem[], code: string): (OfflineItem & { scanned_unit_id: number | null }) | null {
  const c = code.trim()
  for (const item of items) {
    const barcode = item.barcodes.find((b) => b.code === c)
    if (barcode) return { ...item, scanned_unit_id: item.units.some((u) => u.id === barcode.unit_id) ? barcode.unit_id : null }
  }
  const bySku = items.find((i) => i.sku.toLowerCase() === c.toLowerCase())
  return bySku ? { ...bySku, scanned_unit_id: null } : null
}

/* ------------------------------------------------------------------ queue */

/** A sale made offline, waiting to be uploaded. */
export interface QueuedSale {
  client_uuid: string
  invoice_no: string
  sold_at: string
  cash_register_session_id: number
  total: string
  pricing_version: string | null
  items: Array<{ product_variant_id: number; product_unit_id: number | null; qty: string; unit_price: string }>
  payments: Array<{ payment_method_id: number; amount: string; reference_no: string | null }>
  /** Set when the server refused it (kept for a manager to sort out). */
  error?: string
  /** The receipt as printed, for reprinting from the device. */
  receipt: Sale
}

const queueKey = (branchId: number, userId: number) => `queue.${branchId}.${userId}`

export const offlineQueue = {
  async list(branchId: number, userId: number): Promise<QueuedSale[]> {
    return (await localStore.get<QueuedSale[]>(queueKey(branchId, userId))) ?? []
  },
  async add(branchId: number, userId: number, sale: QueuedSale): Promise<void> {
    const all = await offlineQueue.list(branchId, userId)
    if (!all.some((s) => s.client_uuid === sale.client_uuid)) all.push(sale)
    await localStore.set(queueKey(branchId, userId), all)
  },
  async replace(branchId: number, userId: number, sales: QueuedSale[]): Promise<void> {
    await localStore.set(queueKey(branchId, userId), sales)
  },
}

/* ------------------------------------------------------------------ invoice numbers */

const numberKey = (terminalId: number) => `next-invoice.${terminalId}`

const asInt = (v: string | null | undefined) => (v && /^\d+$/.test(v) ? BigInt(v) : 0n)

/**
 * The terminal's next invoice number: the higher of what the server last said
 * and what this device already used offline. Zero-padded like the server's.
 */
export const invoiceCounter = {
  async peek(terminalId: number, serverNext: string | null): Promise<string> {
    const stored = await localStore.get<string>(numberKey(terminalId))
    const next = asInt(stored) > asInt(serverNext) ? stored! : (serverNext ?? '1')
    return next.padStart(serverNext?.length ?? 9, '0')
  },
  /** Mark `used` as taken: the next one is used + 1. */
  async use(terminalId: number, used: string): Promise<void> {
    const stored = await localStore.get<string>(numberKey(terminalId))
    const next = (asInt(used) + 1n).toString().padStart(used.length, '0')
    if (asInt(next) > asInt(stored)) await localStore.set(numberKey(terminalId), next)
  },
}

/* ------------------------------------------------------------------ sync */

export interface SyncResult {
  client_uuid: string
  status: 'accepted' | 'flagged' | 'duplicate' | 'rejected'
  sale_id?: number
  invoice_no?: string
  issues?: string[]
  message?: string
  /** Invoice number printed on the device (differs from invoice_no when renumbered). */
  printed?: string
}

/**
 * Upload queued sales (oldest first). Uploaded ones leave the queue; refused
 * ones stay, marked with the reason. Throws when the server cannot be reached.
 */
export async function syncQueue(branchId: number, userId: number, terminalId: number | null): Promise<{ results: SyncResult[]; remaining: QueuedSale[] }> {
  const queue = await offlineQueue.list(branchId, userId)
  const pending = queue.filter((s) => !s.error)
  if (pending.length === 0) return { results: [], remaining: queue }

  const response = await api.post<{ results: SyncResult[]; next_invoice_no: string | null }>(`${V1}/pos/sync`, {
    client_batch_uuid: uuid(),
    sales: pending.slice(0, 200).map(({ receipt: _receipt, error: _error, ...sale }) => sale),
  })
  const printed = new Map(pending.map((s) => [s.client_uuid, s.invoice_no]))
  const results = response.results.map((r) => ({ ...r, printed: printed.get(r.client_uuid) }))
  const byId = new Map(results.map((r) => [r.client_uuid, r]))
  const remaining = queue
    .map((s) => {
      const r = byId.get(s.client_uuid)
      return r?.status === 'rejected' ? { ...s, error: r.message ?? 'Refused by the server.' } : s
    })
    .filter((s) => {
      const r = byId.get(s.client_uuid)
      return !r || r.status === 'rejected'
    })
  await offlineQueue.replace(branchId, userId, remaining)
  if (terminalId && response.next_invoice_no) await invoiceCounter.use(terminalId, (asInt(response.next_invoice_no) - 1n).toString().padStart(response.next_invoice_no.length, '0'))
  return { results, remaining }
}
