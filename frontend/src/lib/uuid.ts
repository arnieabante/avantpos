/**
 * RFC 4122 v4 UUID for idempotency keys.
 *
 * crypto.randomUUID() only exists in secure contexts (https / localhost);
 * shops often open the POS over plain http on the LAN (e.g. http://192.168.1.10),
 * so fall back to crypto.getRandomValues, which works everywhere.
 */
export function uuid(): string {
  if (typeof crypto.randomUUID === 'function') {
    try {
      return crypto.randomUUID()
    } catch {
      // insecure context: fall through
    }
  }
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
