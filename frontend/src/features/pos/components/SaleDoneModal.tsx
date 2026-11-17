import { CheckCircle2, Printer } from 'lucide-react'
import { Button, Modal } from '@/components/ui'
import { formatMoney } from '@/lib/format'
import { printReceipt } from '../receipt'
import type { PosSetup, Sale } from '../types'
import { Receipt, ReceiptPrintArea } from './Receipt'

/** After checkout: the change to give, the receipt preview and printing. Enter starts the next sale. */
export function SaleDoneModal({ sale, setup, reprint = false, onClose }: { sale: Sale; setup: PosSetup; reprint?: boolean; onClose: () => void }) {
  const print = () => printReceipt(setup.receipt.paper)

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={reprint ? `Invoice ${sale.invoice_no}` : 'Sale complete'}
      footer={
        <>
          <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={print}>
            Print receipt
          </Button>
          <Button onClick={onClose} data-autofocus>
            {reprint ? 'Close' : 'New sale'}
          </Button>
        </>
      }
    >
      {!reprint && (
        <div className="mb-4 flex items-center gap-3 rounded-md bg-[#e8fadf] p-4">
          <CheckCircle2 className="size-8 text-[#71dd37]" aria-hidden="true" />
          <div>
            <p className="text-sm text-ink-muted">Invoice {sale.invoice_no} · Change</p>
            <p className="text-3xl font-semibold text-ink tabular-nums" data-testid="change-due">
              {formatMoney(sale.totals.change)}
            </p>
          </div>
        </div>
      )}
      {!reprint && sale.origin === 'offline' && sale.id === 0 && (
        <p className="mb-3 text-sm text-[#8a5a00]">Saved on this device (offline). It uploads automatically when the connection is back.</p>
      )}
      <div className="max-h-[50vh] overflow-y-auto rounded-md border border-line bg-white p-3">
        <Receipt sale={sale} setup={setup} reprint={reprint} />
      </div>
      <ReceiptPrintArea>
        <Receipt sale={sale} setup={setup} reprint={reprint} />
      </ReceiptPrintArea>
    </Modal>
  )
}
