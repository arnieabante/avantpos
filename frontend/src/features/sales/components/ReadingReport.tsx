import type { ReactNode } from 'react'
import { Printer } from 'lucide-react'
import { Button, Modal } from '@/components/ui'
import { ReceiptPrintArea } from '@/features/pos/components/Receipt'
import { printReceipt } from '@/features/pos/receipt'
import type { RegisterReading } from '@/features/pos/types'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'

const Row = ({ label, value, strong }: { label: ReactNode; value: ReactNode; strong?: boolean }) => (
  <div className={strong ? 'flex justify-between gap-3 font-bold' : 'flex justify-between gap-3'}>
    <span>{label}</span>
    <span className="text-right tabular-nums">{value}</span>
  </div>
)
const Rule = () => <div className="my-1.5 border-t border-dashed border-current opacity-60" />
const peso = (v: string) => formatMoney(v)

/** An X or Z reading laid out like the printed register report. */
export function ReadingReport({ reading: r, businessName }: { reading: RegisterReading; businessName?: string }) {
  return (
    <div className="mx-auto max-w-sm font-mono text-xs leading-relaxed text-ink" data-testid="reading">
      <div className="text-center">
        {businessName && <p className="font-bold uppercase">{businessName}</p>}
        <p className="font-bold">
          {r.type === 'Z' ? 'Z-READING (END OF DAY)' : 'X-READING'} #{r.reading_no}
        </p>
        <p>
          {r.terminal?.name} ({r.terminal?.code})
        </p>
        {r.terminal?.min && <p>MIN: {r.terminal.min}</p>}
        {r.terminal?.serial_no && <p>SN: {r.terminal.serial_no}</p>}
        {r.terminal?.ptu_no && <p>PTU No: {r.terminal.ptu_no}</p>}
      </div>
      <Rule />
      <Row label="Business date" value={formatDate(r.business_date)} />
      <Row label="Generated" value={formatDateTime(r.generated_at)} />
      <Row label="By" value={r.generated_by?.name ?? '—'} />
      <Row label="Beginning invoice" value={r.beginning_invoice_no ?? '—'} />
      <Row label="Ending invoice" value={r.ending_invoice_no ?? '—'} />
      <Rule />
      <Row label="Gross sales" value={peso(r.gross_sales)} />
      <Row label="Less: SC discount" value={peso(r.sc_discount)} />
      <Row label="Less: PWD discount" value={peso(r.pwd_discount)} />
      <Row label="Less: other discounts" value={peso(r.other_discount)} />
      {r.sc_pwd_vat && r.sc_pwd_vat !== '0.00' && <Row label="Less: VAT on SC/PWD sales" value={peso(r.sc_pwd_vat)} />}
      <Row label={`Less: voids (${r.void_count})`} value={peso(r.void_amount)} />
      <Row label={`Less: returns (${r.refund_count})`} value={peso(r.refund_amount)} />
      <Row label="NET SALES" value={peso(r.net_sales)} strong />
      <Rule />
      <Row label="VATable sales" value={peso(r.vatable_sales)} />
      <Row label="VAT amount" value={peso(r.vat_amount)} />
      <Row label="VAT-exempt sales" value={peso(r.vat_exempt_sales)} />
      <Row label="Zero-rated sales" value={peso(r.zero_rated_sales)} />
      <Row label="Transactions" value={r.transaction_count} />
      <Rule />
      <p className="font-bold">Payments</p>
      {r.payments.length === 0 && <p>None</p>}
      {r.payments.map((p) => (
        <Row key={p.method} label={p.method} value={p.refunded !== '0.00' ? `${peso(p.received)} − ${peso(p.refunded)}` : peso(p.received)} />
      ))}
      <Rule />
      <Row label="Previous grand total" value={peso(r.accumulated_grand_total_before)} />
      <Row label={r.type === 'Z' ? 'NEW GRAND TOTAL' : 'Running grand total'} value={peso(r.accumulated_grand_total_after)} strong />
    </div>
  )
}

export function ReadingModal({ reading, businessName, onClose }: { reading: RegisterReading; businessName: string; onClose: () => void }) {
  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={`${reading.type} reading #${reading.reading_no}`}
      footer={
        <>
          <Button variant="outline" leftIcon={<Printer className="size-4" />} onClick={() => printReceipt('80mm')}>
            Print
          </Button>
          <Button onClick={onClose}>Close</Button>
        </>
      }
    >
      <ReadingReport reading={reading} businessName={businessName} />
      <ReceiptPrintArea>
        <div className="bg-white text-black" style={{ width: '72mm' }}>
          <ReadingReport reading={reading} businessName={businessName} />
        </div>
      </ReceiptPrintArea>
    </Modal>
  )
}

