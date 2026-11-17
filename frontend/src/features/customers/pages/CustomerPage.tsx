import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { ArrowLeft, Banknote, Gift, Pencil } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Card,
  CardBody,
  CardHeader,
  DataTable,
  DetailList,
  FormField,
  Input,
  Modal,
  PageHeader,
  PageLoader,
  Pagination,
  Select,
  useConfirm,
  useToast,
  type Column,
} from '@/components/ui'
import { useAccount, useAuth } from '@/features/auth/useAuth'
import { settingsApi, settingsKeys } from '@/features/settings/api'
import { useAction } from '@/hooks/useAction'
import { ApiError } from '@/lib/api/errors'
import { formatDate, formatDateTime, formatMoney, formatNumber, formatPhone } from '@/lib/format'
import { cn } from '@/lib/utils'
import { customerApi, customerKeys } from '../api'
import { CustomerFormModal } from '../components/CustomerFormModal'
import { LEDGER_LABELS, SC_PWD_LABELS, type Customer, type LedgerEntry, type LoyaltyEntry } from '../types'

const isZero = (v: string | null | undefined) => !v || /^-?0*(\.0*)?$/.test(v)
const negative = (v: string) => v.trim().startsWith('-') && !isZero(v)

/** /customers/:customerId */
export default function CustomerPage() {
  const id = Number(useParams().customerId)
  const { can } = useAuth()
  const account = useAccount()
  const navigate = useNavigate()
  const confirm = useConfirm()
  const { run } = useAction()
  const features = account.subscription?.plan.features ?? {}
  const [editing, setEditing] = useState(false)
  const [modal, setModal] = useState<'payment' | 'opening' | 'adjust' | 'points' | null>(null)

  const customer = useQuery({ queryKey: customerKeys.detail(id), queryFn: () => customerApi.get(id), enabled: Number.isFinite(id) })
  if (customer.isPending) return <PageLoader />
  if (customer.isError) return <ApiErrorAlert error={ApiError.from(customer.error)} />

  const c = customer.data
  const canManage = can('customers.manage')
  const canCollect = can('customers.credit_manage') && Boolean(features.credit_sales)
  const refresh = { invalidate: [customerKeys.all] }

  const remove = async () => {
    if (!(await confirm({ title: `Delete ${c.name}?`, description: 'Only customers without sales, account or points history can be deleted.', confirmLabel: 'Delete', danger: true }))) return
    if (await run('delete', () => customerApi.remove(c.id), { ...refresh, success: 'Customer deleted' })) navigate('/customers')
  }

  return (
    <div className="space-y-6">
      <ButtonLink to="/customers" variant="link" leftIcon={<ArrowLeft className="size-4" />}>
        Customers
      </ButtonLink>
      <PageHeader
        title={c.name}
        description={[c.phone ? formatPhone(c.phone) : null, c.group?.name].filter(Boolean).join(' · ') || undefined}
        actions={
          canManage && (
            <>
              {c.is_active ? (
                <Button variant="secondary" onClick={() => run('off', () => customerApi.action(c.id, 'deactivate'), { ...refresh, success: `${c.name} deactivated` })}>
                  Deactivate
                </Button>
              ) : (
                <Button variant="outline" onClick={() => run('on', () => customerApi.action(c.id, 'activate'), { ...refresh, success: `${c.name} activated` })}>
                  Activate
                </Button>
              )}
              <Button variant="ghost" className="text-[#ff3e1d] hover:bg-[#ffe0db] hover:text-[#ff3e1d]" onClick={remove}>
                Delete
              </Button>
              <Button leftIcon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>
                Edit
              </Button>
            </>
          )
        }
      />
      {!c.is_active && <Alert tone="warning">This customer is inactive and cannot be chosen at the POS.</Alert>}

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          {features.credit_sales && <AccountCard customer={c} canCollect={canCollect} onAction={setModal} />}
          {features.loyalty && <LoyaltyCard customer={c} canAdjust={can('loyalty.adjust')} onAdjust={() => setModal('points')} />}
        </div>
        <Card>
          <CardHeader title="Details" />
          <CardBody>
            <DetailList
              items={[
                { label: 'Mobile', value: c.phone ? formatPhone(c.phone) : '—' },
                { label: 'E-mail', value: c.email ?? '—' },
                { label: 'Code', value: c.code ?? '—' },
                { label: 'Group', value: c.group ? `${c.group.name} (${c.group.price_tier} prices${isZero(c.group.discount_percent) ? '' : `, ${formatNumber(c.group.discount_percent)}% off`})` : '—' },
                { label: 'Senior / PWD', value: c.sc_pwd ? `${SC_PWD_LABELS[c.sc_pwd.type]} · ${c.sc_pwd.id_masked}` : '—' },
                { label: 'Birthdate', value: formatDate(c.birthdate) },
                { label: 'TIN', value: c.tin ?? '—' },
                { label: 'Address', value: c.address ?? '—' },
                { label: 'Notes', value: c.notes ?? '—' },
                { label: 'Customer since', value: formatDate(c.created_at) },
              ]}
            />
          </CardBody>
        </Card>
      </div>

      {editing && <CustomerFormModal customer={c} onClose={() => setEditing(false)} />}
      {modal === 'payment' && <PaymentModal customer={c} onClose={() => setModal(null)} />}
      {(modal === 'opening' || modal === 'adjust') && <BalanceModal customer={c} mode={modal} onClose={() => setModal(null)} />}
      {modal === 'points' && <PointsModal customer={c} onClose={() => setModal(null)} />}
    </div>
  )
}

/* ------------------------------------------------------------------ Account */

function AccountCard({ customer: c, canCollect, onAction }: { customer: Customer; canCollect: boolean; onAction: (m: 'payment' | 'opening' | 'adjust') => void }) {
  const [page, setPage] = useState(1)
  const ledger = useQuery({ queryKey: customerKeys.ledger(c.id, page), queryFn: () => customerApi.ledger(c.id, page), placeholderData: (prev) => prev })
  const empty = (ledger.data?.pagination.total ?? 0) === 0

  const columns: Column<LedgerEntry>[] = [
    { key: 'date', header: 'Date', render: (e) => <span className="whitespace-nowrap">{formatDateTime(e.created_at)}</span> },
    {
      key: 'type',
      header: 'Entry',
      render: (e) => (
        <div>
          <p className="text-ink">
            {LEDGER_LABELS[e.type] ?? e.type}
            {e.number && <span className="ml-1 whitespace-nowrap text-ink-subtle">{e.number}</span>}
          </p>
          <p className="max-w-64 truncate text-xs text-ink-subtle">{[e.payment_method, e.reference_no, e.notes].filter(Boolean).join(' · ')}</p>
        </div>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (e) => <span className={cn('whitespace-nowrap font-medium', negative(e.amount) ? 'text-[#56ca00]' : 'text-ink')}>{formatMoney(e.amount)}</span>,
    },
    { key: 'balance', header: 'Balance', align: 'right', render: (e) => <span className="whitespace-nowrap">{formatMoney(e.balance_after)}</span> },
    { key: 'by', header: 'By', className: 'hidden md:table-cell', render: (e) => e.user?.name ?? '—' },
  ]

  return (
    <Card>
      <CardHeader
        title="Account (utang)"
        description={c.allow_credit ? `Credit limit ${formatMoney(c.credit_limit)} · available ${formatMoney(c.available_credit)}` : 'Credit is not allowed for this customer.'}
        actions={
          canCollect && (
            <div className="flex flex-wrap gap-2">
              {empty && (
                <Button size="sm" variant="outline" onClick={() => onAction('opening')}>
                  Opening balance
                </Button>
              )}
              {!empty && (
                <Button size="sm" variant="outline" onClick={() => onAction('adjust')}>
                  Adjust
                </Button>
              )}
              <Button size="sm" leftIcon={<Banknote className="size-4" />} onClick={() => onAction('payment')} disabled={isZero(c.balance)}>
                Record payment
              </Button>
            </div>
          )
        }
      />
      <div className="flex items-baseline gap-2 px-6 pb-4">
        <span className="text-sm text-ink-muted">Owes</span>
        <span className={cn('text-[1.625rem] leading-tight font-medium', isZero(c.balance) ? 'text-ink' : 'text-[#ff3e1d]')}>{formatMoney(c.balance)}</span>
      </div>
      <DataTable
        columns={columns}
        rows={ledger.data?.items}
        rowKey={(e) => e.id}
        loading={ledger.isFetching}
        caption="Account statement"
        empty={<p className="px-5 py-8 text-center text-sm text-ink-subtle">No charges or payments yet. Credit sales from the POS will appear here.</p>}
      />
      <Pagination meta={ledger.data?.pagination} onPageChange={setPage} disabled={ledger.isFetching} className="border-t border-line-soft" />
    </Card>
  )
}

function useMoneyAction(customerId: number, onClose: () => void) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (fn: () => Promise<unknown>, message: string) => {
    setBusy(true)
    setFormError(null)
    try {
      await fn()
      await queryClient.invalidateQueries({ queryKey: customerKeys.all })
      toast.success(message)
      onClose()
    } catch (e) {
      const apiError = ApiError.from(e)
      if (apiError.isValidation && Object.keys(apiError.errors).length) setErrors(Object.fromEntries(Object.entries(apiError.errors).map(([k, v]) => [k, v[0] ?? ''])))
      else setFormError(apiError)
      setBusy(false)
    }
  }

  return { errors, setErrors, formError, busy, submit, customerId }
}

function PaymentModal({ customer, onClose }: { customer: Customer; onClose: () => void }) {
  const methods = useQuery({ queryKey: settingsKeys.paymentMethods, queryFn: settingsApi.paymentMethods })
  const active = (methods.data ?? []).filter((m) => m.is_active)
  const [amount, setAmount] = useState(customer.balance)
  const [methodId, setMethodId] = useState<number | ''>('')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const { errors, setErrors, formError, busy, submit } = useMoneyAction(customer.id, onClose)
  const method = active.find((m) => m.id === (methodId === '' ? active[0]?.id : methodId))

  const save = () => {
    const found: Record<string, string> = {}
    if (!/^\d+(\.\d{1,2})?$/.test(amount.trim()) || isZero(amount)) found.amount = 'Enter the amount received.'
    if (!method) found.payment_method_id = 'Choose how they paid.'
    if (method?.requires_reference && !reference.trim()) found.reference_no = `Enter the ${method.name} reference number.`
    setErrors(found)
    if (Object.keys(found).length) return
    void submit(
      () => customerApi.pay(customer.id, { amount: amount.trim(), payment_method_id: method!.id, reference_no: reference.trim() || null, notes: notes.trim() || null }),
      `Payment of ${formatMoney(amount.trim())} recorded.`,
    )
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!busy}
      title={`Payment from ${customer.name}`}
      description={`Owes ${formatMoney(customer.balance)}.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy}>
            Record payment
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={formError} />
        <FormField label="Amount received" required error={errors.amount}>
          <Input value={amount} inputMode="decimal" leftAddon="₱" onChange={(e) => setAmount(e.target.value)} data-autofocus />
        </FormField>
        <FormField label="Paid with" required error={errors.payment_method_id}>
          <Select value={String(method?.id ?? '')} onChange={(e) => setMethodId(Number(e.target.value))} disabled={methods.isPending}>
            {active.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </Select>
        </FormField>
        {method?.requires_reference && (
          <FormField label="Reference number" required error={errors.reference_no}>
            <Input value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} />
          </FormField>
        )}
        <FormField label="Notes" error={errors.notes}>
          <Input value={notes} maxLength={255} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  )
}

function BalanceModal({ customer, mode, onClose }: { customer: Customer; mode: 'opening' | 'adjust'; onClose: () => void }) {
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const { errors, setErrors, formError, busy, submit } = useMoneyAction(customer.id, onClose)
  const opening = mode === 'opening'

  const save = () => {
    const pattern = opening ? /^\d+(\.\d{1,2})?$/ : /^-?\d+(\.\d{1,2})?$/
    const found: Record<string, string> = {}
    if (!pattern.test(amount.trim()) || isZero(amount)) found.amount = opening ? 'Enter the amount owed.' : 'Enter an amount, e.g. 150 or -150.'
    if (!opening && notes.trim().length < 3) found.notes = 'Say why the balance changes.'
    setErrors(found)
    if (Object.keys(found).length) return
    void submit(
      () => (opening ? customerApi.openingBalance(customer.id, amount.trim(), notes.trim() || null) : customerApi.adjustBalance(customer.id, amount.trim(), notes.trim())),
      opening ? 'Opening balance recorded.' : 'Balance adjusted.',
    )
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!busy}
      title={opening ? 'Opening balance' : 'Adjust balance'}
      description={opening ? 'What the customer already owed before you started using AvantPOS (e.g. from your notebook).' : 'Positive adds to what they owe; negative reduces it. Use payments for money received.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={formError} />
        <FormField label="Amount" required error={errors.amount}>
          <Input value={amount} inputMode="decimal" leftAddon="₱" onChange={(e) => setAmount(e.target.value)} data-autofocus />
        </FormField>
        <FormField label={opening ? 'Notes' : 'Reason'} required={!opening} error={errors.notes}>
          <Input value={notes} maxLength={255} onChange={(e) => setNotes(e.target.value)} />
        </FormField>
      </div>
    </Modal>
  )
}

/* ------------------------------------------------------------------ Loyalty */

function LoyaltyCard({ customer: c, canAdjust, onAdjust }: { customer: Customer; canAdjust: boolean; onAdjust: () => void }) {
  const [page, setPage] = useState(1)
  const history = useQuery({ queryKey: customerKeys.loyalty(c.id, page), queryFn: () => customerApi.loyalty(c.id, page), placeholderData: (prev) => prev })

  const columns: Column<LoyaltyEntry>[] = [
    { key: 'date', header: 'Date', render: (e) => <span className="whitespace-nowrap">{formatDateTime(e.created_at)}</span> },
    {
      key: 'reason',
      header: 'Reason',
      render: (e) => (
        <div>
          <Badge>{e.reason}</Badge>
          {e.notes && <p className="mt-0.5 text-xs text-ink-subtle">{e.notes}</p>}
        </div>
      ),
    },
    { key: 'points', header: 'Points', align: 'right', render: (e) => <span className={cn('font-medium', negative(e.points) ? 'text-[#ff3e1d]' : 'text-[#56ca00]')}>{negative(e.points) ? '' : '+'}{formatNumber(e.points)}</span> },
    { key: 'balance', header: 'Balance', align: 'right', render: (e) => formatNumber(e.balance_after) },
    { key: 'by', header: 'By', className: 'hidden md:table-cell', render: (e) => e.user?.name ?? '—' },
  ]

  return (
    <Card>
      <CardHeader
        title="Loyalty points"
        actions={
          canAdjust && (
            <Button size="sm" variant="outline" leftIcon={<Gift className="size-4" />} onClick={onAdjust}>
              Adjust points
            </Button>
          )
        }
      />
      <div className="flex items-baseline gap-2 px-6 pb-4">
        <span className="text-[1.625rem] leading-tight font-medium text-ink">{formatNumber(c.loyalty_points)}</span>
        <span className="text-sm text-ink-muted">points</span>
      </div>
      <DataTable columns={columns} rows={history.data?.items} rowKey={(e) => e.id} loading={history.isFetching} caption="Points history" empty={<p className="px-5 py-8 text-center text-sm text-ink-subtle">No points yet. Points are earned at the POS.</p>} />
      <Pagination meta={history.data?.pagination} onPageChange={setPage} disabled={history.isFetching} className="border-t border-line-soft" />
    </Card>
  )
}

function PointsModal({ customer, onClose }: { customer: Customer; onClose: () => void }) {
  const [points, setPoints] = useState('')
  const [notes, setNotes] = useState('')
  const { errors, setErrors, formError, busy, submit } = useMoneyAction(customer.id, onClose)

  const save = () => {
    const found: Record<string, string> = {}
    if (!/^-?\d+(\.\d{1,2})?$/.test(points.trim()) || isZero(points)) found.points = 'Enter points, e.g. 50 or -50.'
    if (notes.trim().length < 3) found.notes = 'Say why.'
    setErrors(found)
    if (Object.keys(found).length) return
    void submit(() => customerApi.adjustPoints(customer.id, points.trim(), notes.trim()), 'Points updated.')
  }

  return (
    <Modal
      open
      onClose={onClose}
      dismissible={!busy}
      title={`Adjust points for ${customer.name}`}
      description={`Current balance: ${formatNumber(customer.loyalty_points)} points.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={save} loading={busy}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <ApiErrorAlert error={formError} />
        <FormField label="Points" required error={errors.points} hint="Negative to remove points.">
          <Input value={points} inputMode="decimal" onChange={(e) => setPoints(e.target.value)} data-autofocus />
        </FormField>
        <FormField label="Reason" required error={errors.notes}>
          <Input value={notes} maxLength={255} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Birthday bonus" />
        </FormField>
      </div>
    </Modal>
  )
}
