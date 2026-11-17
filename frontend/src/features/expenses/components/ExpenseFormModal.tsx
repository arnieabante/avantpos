import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Checkbox, FormField, Input, Modal, Select, Spinner, Textarea } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { todayIn } from '@/features/reports/dates'
import { ApiError } from '@/lib/api/errors'
import { expenseKeys, expensesApi, type Expense } from '../api'

/** Record an expense (or change one that is not decided yet). */
export function ExpenseFormModal({ expense, onSaved, onClose }: { expense: Expense | null; onSaved: (e: Expense) => void; onClose: () => void }) {
  const { activeBranch } = useAuth()
  const options = useQuery({ queryKey: expenseKeys.options, queryFn: expensesApi.options, staleTime: 60_000 })
  const [v, setV] = useState({
    branch_id: expense?.branch?.id ?? activeBranch?.id ?? 0,
    expense_category_id: expense?.category?.id ?? 0,
    payment_method_id: expense?.payment_method?.id ?? null,
    expense_date: expense?.expense_date ?? todayIn(),
    amount: expense?.amount ?? '',
    vendor: expense?.vendor ?? '',
    reference_no: expense?.reference_no ?? '',
    description: expense?.description ?? '',
  })
  const [fromDrawer, setFromDrawer] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState<'draft' | 'submit' | null>(null)
  const set = (patch: Partial<typeof v>) => setV((x) => ({ ...x, ...patch }))

  const save = async (submit: boolean) => {
    const found: Record<string, string> = {}
    if (!v.expense_category_id) found.expense_category_id = 'Choose a category.'
    if (!/^\d+(\.\d{1,2})?$/.test(v.amount.trim()) || /^0*(\.0*)?$/.test(v.amount.trim())) found.amount = 'Enter the amount, e.g. 1250.00.'
    if (!v.expense_date) found.expense_date = 'Enter the date.'
    setErrors(found)
    if (Object.keys(found).length) return
    setBusy(submit ? 'submit' : 'draft')
    setError(null)
    const body = {
      ...v,
      amount: v.amount.trim(),
      vendor: v.vendor.trim() || null,
      reference_no: v.reference_no.trim() || null,
      description: v.description.trim() || null,
    }
    try {
      const saved = expense ? await expensesApi.update(expense.id, body) : await expensesApi.create({ ...body, submit, paid_from_drawer: submit && fromDrawer })
      onSaved(saved)
    } catch (e) {
      const err = ApiError.from(e)
      if (err.isValidation) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else setError(err)
      setBusy(null)
    }
  }

  const o = options.data
  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      dismissible={!busy}
      title={expense ? `Edit ${expense.number}` : 'Record an expense'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={!!busy}>
            Cancel
          </Button>
          {expense ? (
            <Button onClick={() => save(false)} loading={busy === 'draft'}>
              Save
            </Button>
          ) : (
            <>
              <Button variant="outline" onClick={() => save(false)} loading={busy === 'draft'} disabled={!!busy}>
                Save as draft
              </Button>
              <Button onClick={() => save(true)} loading={busy === 'submit'} disabled={!!busy}>
                {o?.can_approve ? 'Save' : 'Submit for approval'}
              </Button>
            </>
          )}
        </>
      }
    >
      {!o ? (
        <div className="flex justify-center p-6">{options.isError ? <ApiErrorAlert error={ApiError.from(options.error)} /> : <Spinner />}</div>
      ) : (
        <div className="space-y-4">
          <ApiErrorAlert error={error} />
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Category" required error={errors.expense_category_id}>
              <Select value={v.expense_category_id || ''} onChange={(e) => set({ expense_category_id: Number(e.target.value) })} data-autofocus>
                <option value="">Choose…</option>
                {o.categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Amount" required error={errors.amount}>
              <Input value={v.amount} inputMode="decimal" leftAddon="₱" onChange={(e) => set({ amount: e.target.value.replace(/[^\d.]/g, '') })} />
            </FormField>
            <FormField label="Date" required error={errors.expense_date}>
              <Input type="date" value={v.expense_date} max={todayIn()} onChange={(e) => set({ expense_date: e.target.value })} />
            </FormField>
            {o.branches.length > 1 && (
              <FormField label="Branch" required error={errors.branch_id}>
                <Select value={v.branch_id} onChange={(e) => set({ branch_id: Number(e.target.value) })}>
                  {o.branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </Select>
              </FormField>
            )}
            <FormField label="Paid to" hint="e.g. Meralco, landlord, supplier" error={errors.vendor}>
              <Input value={v.vendor} maxLength={150} onChange={(e) => set({ vendor: e.target.value })} />
            </FormField>
            <FormField label="Paid by" error={errors.payment_method_id}>
              <Select value={v.payment_method_id ?? ''} onChange={(e) => set({ payment_method_id: e.target.value ? Number(e.target.value) : null })}>
                <option value="">Not specified</option>
                {o.payment_methods.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Receipt / bill no." error={errors.reference_no}>
              <Input value={v.reference_no} maxLength={100} onChange={(e) => set({ reference_no: e.target.value })} />
            </FormField>
          </div>
          <FormField label="Notes" error={errors.description}>
            <Textarea rows={2} maxLength={1000} value={v.description} onChange={(e) => set({ description: e.target.value })} />
          </FormField>
          {!expense && (
            <Checkbox
              label="Paid from the cash drawer of my open shift"
              description="Records a payout in your shift so the cash count stays right."
              checked={fromDrawer}
              onChange={(e) => setFromDrawer(e.target.checked)}
            />
          )}
          {errors.paid_from_drawer && <p className="text-sm text-[#ff3e1d]">{errors.paid_from_drawer}</p>}
        </div>
      )}
    </Modal>
  )
}
