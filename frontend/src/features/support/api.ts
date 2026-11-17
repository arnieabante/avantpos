import { api } from '@/lib/api/client'

const V1 = '/api/v1/support/tickets'

export type TicketStatus = 'open' | 'pending' | 'resolved' | 'closed'

export interface Ticket {
  id: number
  number: string
  subject: string
  category: string | null
  priority: 'low' | 'normal' | 'high' | 'urgent'
  status: TicketStatus
  opened_by: { id?: number; name: string; email?: string } | null
  created_at: string
  updated_at: string
  messages?: Array<{ id: number; from: 'business' | 'support'; author: string; body: string; created_at: string }>
}

export const supportKeys = { all: ['support'] as const, one: (id: number) => ['support', id] as const }

export const supportApi = {
  list: () => api.get<Ticket[]>(V1),
  get: (id: number) => api.get<Ticket>(`${V1}/${id}`),
  open: (body: { subject: string; category: string; priority: string; body: string }) => api.post<Ticket>(V1, body),
  reply: (id: number, body: string) => api.post<Ticket>(`${V1}/${id}/reply`, { body }),
}

export const TICKET_STATUS: Record<TicketStatus, { label: string; tone: 'info' | 'warning' | 'success' | 'neutral' }> = {
  open: { label: 'Open', tone: 'info' },
  pending: { label: 'Answered', tone: 'warning' },
  resolved: { label: 'Resolved', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
}

export const TICKET_CATEGORIES: Record<string, string> = {
  technical: 'Technical problem',
  billing: 'Billing and subscription',
  account: 'Account and users',
  feature_request: 'Suggestion',
  other: 'Other',
}
