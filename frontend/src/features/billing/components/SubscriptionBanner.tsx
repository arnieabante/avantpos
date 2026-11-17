import { Link } from 'react-router'
import { AlertTriangle } from 'lucide-react'
import { useAuth } from '@/features/auth/useAuth'
import { formatDate } from '@/lib/format'

/** Shown on every page while the subscription is overdue or expired. */
export function SubscriptionBanner() {
  const { account, can } = useAuth()
  const s = account?.subscription
  if (!s || !['past_due', 'grace', 'expired'].includes(s.status)) return null
  const expired = s.status === 'expired'

  return (
    <div role="status" className={expired ? 'mb-4 flex items-center gap-3 rounded-md border border-[#ffb4a6] bg-[#ffe5e0] px-4 py-3 text-sm text-[#a52a14] print:hidden' : 'mb-4 flex items-center gap-3 rounded-md border border-[#ffd27a] bg-[#fff2d6] px-4 py-3 text-sm text-[#8a5a00] print:hidden'}>
      <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
      <p className="flex-1">
        {expired
          ? 'Your AvantPOS subscription has expired: you can view your records, but not record sales or changes.'
          : `Your AvantPOS subscription payment is overdue${s.current_period_end ? ` since ${formatDate(s.current_period_end)}` : ''}.`}
      </p>
      {can('billing.manage') || can('billing.view') ? (
        <Link to="/settings/billing" className="font-medium underline">
          {expired ? 'Renew now' : 'Pay now'}
        </Link>
      ) : (
        <span>Ask the owner to renew it.</span>
      )}
    </div>
  )
}
