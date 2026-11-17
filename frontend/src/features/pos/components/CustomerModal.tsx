import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, UserRound } from 'lucide-react'
import { Badge, Button, FormField, Input, Modal, Spinner } from '@/components/ui'
import type { Customer } from '@/features/customers/types'
import { formatMoney, formatPhone } from '@/lib/format'
import { cn } from '@/lib/utils'
import { posApi } from '../api'
import type { PosSetup } from '../types'

interface Props {
  customer: Customer | null
  redeemPoints: number | null
  loyalty: PosSetup['loyalty']
  /** Points cannot be used on a Senior/PWD sale. */
  scPwdActive: boolean
  onSave: (customer: Customer | null, redeemPoints: number | null) => void
  onClose: () => void
}

/** Attach a customer (for their prices, points or account) and optionally use points. */
export function CustomerModal({ customer, redeemPoints, loyalty, scPwdActive, onSave, onClose }: Props) {
  const [query, setQuery] = useState('')
  const [picked, setPicked] = useState<Customer | null>(customer)
  const [points, setPoints] = useState(redeemPoints ? String(redeemPoints) : '')
  const term = query.trim()
  const results = useQuery({
    queryKey: ['pos', 'customers', term],
    queryFn: () => posApi.customers(term),
    enabled: term.length >= 2,
    staleTime: 30_000,
  })

  const available = picked ? Math.floor(Number(picked.loyalty_points)) : 0
  const canRedeem = !!loyalty && !!picked && !scPwdActive && available > 0
  const pointsError =
    points && (!/^\d+$/.test(points) || Number(points) > available)
      ? `Enter whole points, up to ${available}.`
      : points && loyalty && Number(points) < loyalty.min_redeem_points
        ? `Use at least ${loyalty.min_redeem_points} points.`
        : undefined

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title="Customer"
      footer={
        <>
          {customer && (
            <Button variant="ghost" className="mr-auto" onClick={() => onSave(null, null)}>
              Remove customer
            </Button>
          )}
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!picked || !!pointsError} onClick={() => onSave(picked, canRedeem && points ? Number(points) : null)}>
            Use customer
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Input
          aria-label="Search customers"
          placeholder="Name, phone or code"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          leftAddon={<Search className="size-4" />}
          data-autofocus
        />
        {term.length >= 2 && (
          <div className="max-h-60 overflow-y-auto rounded-md border border-line">
            {results.isPending ? (
              <div className="flex justify-center p-4">
                <Spinner />
              </div>
            ) : results.data?.length ? (
              <ul role="listbox" aria-label="Customers">
                {results.data.map((c) => (
                  <li key={c.id}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={picked?.id === c.id}
                      onClick={() => {
                        setPicked(c)
                        setPoints('')
                      }}
                      className={cn('flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-brand-100/40', picked?.id === c.id && 'bg-brand-100/60')}
                    >
                      <span>
                        <span className="block font-medium text-ink">{c.name}</span>
                        <span className="block text-xs text-ink-subtle">{[formatPhone(c.phone), c.code].filter((v) => v && v !== '—').join(' · ')}</span>
                      </span>
                      {c.group && <Badge tone="info">{c.group.name}</Badge>}
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="p-3 text-sm text-ink-subtle">No customer found.</p>
            )}
          </div>
        )}

        {picked && (
          <div className="rounded-md bg-brand-100/40 p-3 text-sm">
            <p className="flex items-center gap-2 font-medium text-ink">
              <UserRound className="size-4" aria-hidden="true" /> {picked.name}
            </p>
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1">
              {picked.group && (
                <>
                  <dt>Group</dt>
                  <dd className="text-right text-ink">{picked.group.name}</dd>
                </>
              )}
              {loyalty && (
                <>
                  <dt>Points</dt>
                  <dd className="text-right text-ink">{available}</dd>
                </>
              )}
              {picked.allow_credit && (
                <>
                  <dt>Can still charge</dt>
                  <dd className="text-right text-ink">{formatMoney(picked.available_credit ?? '0')}</dd>
                </>
              )}
            </dl>
          </div>
        )}

        {canRedeem && (
          <FormField
            label="Use points"
            hint={`1 point = ${formatMoney(loyalty.point_value)}. Points are a discount on this sale.`}
            error={pointsError}
          >
            <Input value={points} inputMode="numeric" onChange={(e) => setPoints(e.target.value.replace(/\D/g, ''))} placeholder="0" />
          </FormField>
        )}
        {picked && loyalty && scPwdActive && <p className="text-xs text-ink-subtle">Points cannot be used on a Senior Citizen / PWD sale.</p>}
      </div>
    </Modal>
  )
}
