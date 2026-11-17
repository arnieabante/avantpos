import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Button, Modal, Spinner } from '@/components/ui'
import { ReceiptPrintArea } from '@/features/pos/components/Receipt'
import { printReceipt } from '@/features/pos/receipt'
import { ApiError } from '@/lib/api/errors'
import { subtractMoney } from '@/lib/decimal'
import { formatDate, formatMoney } from '@/lib/format'
import { billingApi, billingKeys, type BillingInvoice } from '../api'

const KIND: Record<BillingInvoice['kind'], string> = { new: 'New subscription', renewal: 'Renewal', upgrade: 'Upgrade' }

function InvoiceDocument({ i }: { i: BillingInvoice }) {
  return (
    <div className="bg-white font-sans text-[10pt] text-black" data-testid="billing-invoice">
      <div className="flex justify-between border-b-2 border-black pb-3">
        <div>
          <p className="text-lg font-bold">AvantPOS</p>
          <p>Subscription invoice</p>
        </div>
        <div className="text-right">
          <p className="text-xl font-bold">INVOICE</p>
          <p>No. {i.number}</p>
          <p>Issued {formatDate(i.issued_at)}</p>
          <p className="font-semibold uppercase">{i.status === 'paid' ? `Paid ${formatDate(i.paid_at)}` : i.status === 'open' ? `Due ${formatDate(i.due_at)}` : i.status}</p>
        </div>
      </div>
      {i.bill_to && (
        <div className="mt-3">
          <p className="text-[8pt] font-bold uppercase">Bill to</p>
          <p className="font-semibold">{i.bill_to.name}</p>
          {i.bill_to.tin && <p>TIN {i.bill_to.tin}</p>}
          {i.bill_to.address && <p>{i.bill_to.address}</p>}
          {i.bill_to.email && <p>{i.bill_to.email}</p>}
        </div>
      )}
      <table className="mt-4 w-full border-collapse">
        <thead>
          <tr className="border-y border-black text-left">
            <th className="py-1">Description</th>
            <th className="py-1">Period</th>
            <th className="py-1 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-b border-gray-300">
            <td className="py-1.5">
              AvantPOS {i.plan?.name} plan, {i.billing_cycle === 'annual' ? 'yearly' : 'monthly'} ({KIND[i.kind]})
            </td>
            <td className="py-1.5">
              {formatDate(i.period_start)} – {formatDate(i.period_end)}
            </td>
            <td className="py-1.5 text-right">{formatMoney(i.subtotal)}</td>
          </tr>
          {i.credit !== '0.00' && (
            <tr className="border-b border-gray-300">
              <td className="py-1.5" colSpan={2}>
                Less: unused time of the previous plan
              </td>
              <td className="py-1.5 text-right">−{formatMoney(i.credit)}</td>
            </tr>
          )}
        </tbody>
      </table>
      <div className="mt-3 ml-auto w-64 space-y-0.5">
        {i.vat_registered && (
          <>
            <div className="flex justify-between">
              <span>VATable amount</span>
              <span>{formatMoney(subtractMoney(i.total, i.vat_amount))}</span>
            </div>
            <div className="flex justify-between">
              <span>VAT (12%)</span>
              <span>{formatMoney(i.vat_amount)}</span>
            </div>
          </>
        )}
        <div className="flex justify-between border-t border-black pt-1 text-base font-bold">
          <span>Total</span>
          <span>{formatMoney(i.total)}</span>
        </div>
      </div>
      {i.payments.filter((p) => p.status === 'paid').map((p, n) => (
        <p key={n} className="mt-3 text-[9pt]">
          Paid {formatDate(p.paid_at)} via {p.channel ?? p.gateway}
          {p.reference ? ` (ref. ${p.reference})` : ''}.
        </p>
      ))}
    </div>
  )
}

/** A subscription invoice, printable on A4. */
export function InvoiceModal({ invoiceId, onClose }: { invoiceId: number; onClose: () => void }) {
  const q = useQuery({ queryKey: billingKeys.invoice(invoiceId), queryFn: () => billingApi.invoice(invoiceId) })
  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={q.data ? `Invoice ${q.data.number}` : 'Invoice'}
      footer={
        <>
          <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={() => printReceipt('a4')} disabled={!q.data}>
            Print
          </Button>
          <Button onClick={onClose}>Close</Button>
        </>
      }
    >
      {q.isError ? (
        <ApiErrorAlert error={ApiError.from(q.error)} />
      ) : !q.data ? (
        <div className="flex justify-center p-6">
          <Spinner />
        </div>
      ) : (
        <>
          <InvoiceDocument i={q.data} />
          <ReceiptPrintArea>
            <InvoiceDocument i={q.data} />
          </ReceiptPrintArea>
        </>
      )}
    </Modal>
  )
}
