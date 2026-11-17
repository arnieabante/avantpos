import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { PauseCircle, Play, Trash2 } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, EmptyState, IconButton, Modal, Spinner } from '@/components/ui'
import { ApiError } from '@/lib/api/errors'
import { formatDateTime, formatMoney } from '@/lib/format'
import { posApi, posKeys } from '../api'

interface Props {
  /** The current cart has items: resuming would replace it. */
  cartInUse: boolean
  onResume: (cart: unknown) => void
  onClose: () => void
}

/** Carts on hold in this branch (any cashier). Resuming takes the cart off the list. */
export function HeldCartsModal({ cartInUse, onResume, onClose }: Props) {
  const queryClient = useQueryClient()
  const held = useQuery({ queryKey: posKeys.held, queryFn: posApi.held })
  const [busy, setBusy] = useState<number | null>(null)
  const [error, setError] = useState<ApiError | null>(null)

  const act = async (id: number, action: 'resume' | 'discard') => {
    setBusy(id)
    setError(null)
    try {
      if (action === 'resume') {
        const resumed = await posApi.resume(id)
        await queryClient.invalidateQueries({ queryKey: posKeys.held })
        onResume(resumed.cart)
        return
      }
      await posApi.discard(id)
      await queryClient.invalidateQueries({ queryKey: posKeys.held })
    } catch (e) {
      setError(ApiError.from(e))
      await queryClient.invalidateQueries({ queryKey: posKeys.held })
    }
    setBusy(null)
  }

  return (
    <Modal open onClose={onClose} size="lg" title="Held sales" description={cartInUse ? 'Finish or hold the current sale before resuming another.' : undefined}>
      <div className="space-y-3">
        <ApiErrorAlert error={error} />
        {held.isPending ? (
          <div className="flex justify-center p-6">
            <Spinner />
          </div>
        ) : held.isError ? (
          <ApiErrorAlert error={ApiError.from(held.error)} />
        ) : held.data.length === 0 ? (
          <EmptyState icon={<PauseCircle className="size-5" />} title="No sales on hold" />
        ) : (
          <ul className="divide-y divide-line rounded-md border border-line">
            {held.data.map((h) => (
              <li key={h.id} className="flex items-center gap-3 p-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-ink">
                    {h.label || h.customer?.name || `${h.item_count} item${h.item_count === 1 ? '' : 's'}`}
                    {h.preview?.total && <span className="ml-2 text-sm font-normal text-ink-muted">{formatMoney(h.preview.total)}</span>}
                  </p>
                  <p className="truncate text-xs text-ink-subtle">
                    {[h.preview?.names?.join(', '), `${h.held_by?.name ?? ''} · ${formatDateTime(h.held_at)}`].filter(Boolean).join(' — ')}
                  </p>
                </div>
                <Button size="sm" leftIcon={<Play className="size-4" />} disabled={cartInUse || busy !== null} loading={busy === h.id} onClick={() => act(h.id, 'resume')}>
                  Resume
                </Button>
                <IconButton label={`Discard ${h.label ?? 'held sale'}`} disabled={busy !== null} onClick={() => act(h.id, 'discard')}>
                  <Trash2 className="size-4" />
                </IconButton>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  )
}
