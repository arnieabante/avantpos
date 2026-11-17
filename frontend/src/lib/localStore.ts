/**
 * Small key–value store on IndexedDB (room for a full product catalog and the
 * offline sales queue, unlike localStorage). Falls back to memory where
 * IndexedDB is not available (tests, some private windows) — offline data
 * then lasts only while the page is open.
 */

const DB_NAME = 'avantpos'
const STORE = 'kv'

let dbPromise: Promise<IDBDatabase | null> | null = null
const memory = new Map<string, unknown>()

function open(): Promise<IDBDatabase | null> {
  if (dbPromise) return dbPromise
  dbPromise = new Promise((resolve) => {
    try {
      if (typeof indexedDB === 'undefined') return resolve(null)
      const request = indexedDB.open(DB_NAME, 1)
      request.onupgradeneeded = () => request.result.createObjectStore(STORE)
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => resolve(null)
      request.onblocked = () => resolve(null)
    } catch {
      resolve(null)
    }
  })
  return dbPromise
}

function run<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest): Promise<T | undefined> {
  return open().then(
    (db) =>
      new Promise<T | undefined>((resolve, reject) => {
        if (!db) return resolve(undefined)
        const tx = db.transaction(STORE, mode)
        const request = action(tx.objectStore(STORE))
        tx.oncomplete = () => resolve(request.result as T)
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error)
      }),
  )
}

export const localStore = {
  async get<T>(key: string): Promise<T | null> {
    const db = await open()
    if (!db) return (memory.get(key) as T | undefined) ?? null
    return (await run<T>('readonly', (s) => s.get(key))) ?? null
  },

  async set(key: string, value: unknown): Promise<void> {
    const db = await open()
    if (!db) {
      memory.set(key, structuredClone(value))
      return
    }
    await run('readwrite', (s) => s.put(value, key))
  },

  async remove(key: string): Promise<void> {
    const db = await open()
    if (!db) {
      memory.delete(key)
      return
    }
    await run('readwrite', (s) => s.delete(key))
  },

  /** Tests only: forget everything kept in memory. */
  resetMemory(): void {
    memory.clear()
  },
}
