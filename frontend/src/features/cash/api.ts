import { api } from '@/lib/api/client'
import type { CashSession, MovementType, PosTerminal } from './types'

const V1 = '/api/v1'

export interface SessionFilters {
  page?: number
  status?: 'open' | 'closed'
  from?: string
  to?: string
}

export const cashKeys = {
  all: ['cash'] as const,
  terminals: ['cash', 'terminals'] as const,
  sessions: (f: SessionFilters) => ['cash', 'sessions', f] as const,
  session: (id: number) => ['cash', 'session', id] as const,
}

function clean<T extends object>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== '')) as Partial<T>
}

export const cashApi = {
  terminals: () => api.get<{ terminals: PosTerminal[]; my_session: CashSession | null }>(`${V1}/pos/terminals`),
  sessions: (f: SessionFilters) => api.getPage<CashSession>(`${V1}/cash-sessions`, clean({ per_page: 25, ...f })),
  session: (id: number) => api.get<CashSession>(`${V1}/cash-sessions/${id}`),
  open: (body: { client_uuid: string; terminal_id: number; cash_register_id: number | null; opening_float: string }) =>
    api.post<CashSession>(`${V1}/cash-sessions`, body),
  move: (id: number, body: { client_uuid: string; type: MovementType; amount: string; reason: string }) =>
    api.post<CashSession>(`${V1}/cash-sessions/${id}/movements`, body),
  close: (id: number, body: { denominations: Record<string, number> | null; counted_cash: string | null; notes: string | null }) =>
    api.post<CashSession>(`${V1}/cash-sessions/${id}/close`, body),
}
