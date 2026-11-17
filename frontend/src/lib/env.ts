/**
 * Build-time configuration (Vite `VITE_*` variables). See `.env.example`.
 */
export const env = {
  /** Laravel API origin, without trailing slash. */
  apiUrl: (import.meta.env.VITE_API_URL || 'http://localhost:8000').replace(/\/+$/, ''),
  /** Base domain for tenant subdomains, e.g. arnelmotoshop.avantpos.ph */
  baseDomain: import.meta.env.VITE_BASE_DOMAIN || 'avantpos.ph',
} as const
