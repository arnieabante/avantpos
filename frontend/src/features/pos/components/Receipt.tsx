import { useEffect, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { trimQty } from '@/lib/decimal'
import { PAPER_WIDTH, TAX_MARK, formatTin } from '../receipt'
import type { PosSetup, Sale } from '../types'

const isZero = (v: string | null | undefined) => !v || /^-?0*(\.0*)?$/.test(v)
const peso = (v: string | null | undefined) => formatMoney(v ?? '0').replace('₱', '')

function Row({ label, value, strong }: { label: ReactNode; value: ReactNode; strong?: boolean }) {
  return (
    <div className={strong ? 'flex justify-between gap-2 font-bold' : 'flex justify-between gap-2'}>
      <span>{label}</span>
      <span className="text-right tabular-nums">{value}</span>
    </div>
  )
}

const Rule = () => <div className="my-1 border-t border-dashed border-black" />

/**
 * The sales invoice as printed on thermal paper, with the details the BIR
 * requires: registered name, address, VAT/non-VAT TIN, MIN / serial / PTU,
 * invoice number, VATable / VAT / VAT-exempt / zero-rated amounts, and the
 * Senior Citizen / PWD beneficiary details. Sections can be switched off in
 * Settings → Receipt templates (except the legally required ones).
 */
export function Receipt({ sale, setup, reprint = false }: { sale: Sale; setup: PosSetup; reprint?: boolean }) {
  const { business, branch, receipt } = setup
  // The machine that issued the invoice (a reprint may come from another terminal).
  const terminal = sale.terminal && sale.terminal.min !== undefined ? sale.terminal : setup.terminal
  const layout = receipt.layout
  const show = (key: string) => layout[key] !== false
  const vat = business.vat_registered
  const tin = formatTin(business.tin, branch.tin_branch_code)
  const statutory = sale.discounts.find((d) => d.beneficiary)
  const t = sale.totals

  if (receipt.paper === 'a4') {
    return <A4Invoice sale={sale} setup={setup} reprint={reprint} />
  }

  return (
    <div
      className="mx-auto bg-white font-mono text-[11px] leading-snug text-black"
      style={{ width: PAPER_WIDTH[receipt.paper] }}
      data-testid="receipt"
    >
      <div className="text-center">
        {receipt.show_logo && business.logo_url && <img src={business.logo_url} alt="" className="mx-auto mb-1 max-h-12" />}
        <p className="font-bold uppercase">{business.name}</p>
        {business.registered_name && business.registered_name !== business.name && <p>{business.registered_name}</p>}
        {business.business_style && <p>{business.business_style}</p>}
        {show('show_business_address') && (branch.address ?? business.address) && <p>{branch.address ?? business.address}</p>}
        {tin && <p>{vat ? 'VAT REG TIN' : 'NON-VAT REG TIN'}: {tin}</p>}
        {terminal?.min && <p>MIN: {terminal.min}</p>}
        {terminal?.serial_no && <p>SN: {terminal.serial_no}</p>}
        {terminal?.ptu_no && (
          <p>
            PTU No: {terminal.ptu_no}
            {terminal.ptu_issued_at ? ` · ${formatDate(terminal.ptu_issued_at)}` : ''}
          </p>
        )}
        {receipt.header_text && <p className="mt-1 whitespace-pre-line">{receipt.header_text}</p>}
      </div>

      <Rule />
      <p className="text-center font-bold">{reprint ? 'SALES INVOICE — REPRINT' : 'SALES INVOICE'}</p>
      <Row label="Invoice No." value={sale.invoice_no} />
      <Row label="Date" value={formatDateTime(sale.sold_at)} />
      {show('show_branch') && <Row label="Branch" value={branch.name} />}
      <Row label="Terminal" value={sale.terminal?.code ?? terminal?.code ?? ''} />
      {show('show_cashier') && <Row label="Cashier" value={sale.cashier?.name ?? ''} />}
      <Rule />

      {sale.items.map((i) => (
        <div key={i.id} className="mb-0.5">
          <p>{i.name}</p>
          {show('show_item_sku') && <p className="text-[10px]">{i.sku}</p>}
          <Row
            label={`  ${trimQty(i.qty)}${i.unit ? ` ${i.unit}` : ''} x ${peso(i.unit_price)}`}
            value={`${peso(i.line_total)} ${vat ? TAX_MARK[i.tax_class] : ''}`.trimEnd()}
          />
          {!isZero(i.line_discount) && <Row label="  Less: discount" value={`-${peso(i.line_discount)}`} />}
          {!isZero(i.sc_pwd_discount) && <Row label="  Less: 20% SC/PWD" value={`-${peso(i.sc_pwd_discount)}`} />}
        </div>
      ))}
      <Rule />

      <Row label={`${sale.items.length} item${sale.items.length === 1 ? '' : 's'}`} value="" />
      {!isZero(t.gross) && t.gross !== t.total && <Row label="Subtotal" value={peso(t.gross)} />}
      {!isZero(t.line_discount) && <Row label="Item discounts" value={`-${peso(t.line_discount)}`} />}
      {!isZero(t.sc_pwd_discount) && <Row label="SC/PWD discount" value={`-${peso(t.sc_pwd_discount)}`} />}
      {!isZero(t.transaction_discount) && <Row label="Sale discount" value={`-${peso(t.transaction_discount)}`} />}
      {!isZero(t.loyalty_discount) && <Row label={`Points used (${sale.points.redeemed})`} value={`-${peso(t.loyalty_discount)}`} />}
      <Row label="TOTAL AMOUNT DUE" value={formatMoney(t.total)} strong />

      {show('show_payment_details') && (
        <>
          {sale.payments.map((p, idx) => (
            <div key={idx}>
              <Row label={p.method ?? 'Payment'} value={peso(p.tendered ?? p.amount)} />
              {p.reference_no && <Row label="  Ref" value={p.reference_no} />}
            </div>
          ))}
          <Row label="CHANGE" value={peso(t.change)} strong />
        </>
      )}

      {(show('show_tax_breakdown') || vat) && (
        <>
          <Rule />
          {vat ? (
            <>
              <Row label="VATable Sales" value={peso(t.vatable_sales)} />
              <Row label="VAT Amount (12%)" value={peso(t.vat_amount)} />
              <Row label="VAT-Exempt Sales" value={peso(t.vat_exempt_sales)} />
              <Row label="Zero-Rated Sales" value={peso(t.zero_rated_sales)} />
            </>
          ) : (
            <Row label="Non-VAT Sales" value={peso(t.total)} />
          )}
        </>
      )}

      {statutory?.beneficiary && (
        <>
          <Rule />
          <p className="font-bold">{statutory.type === 'pwd' ? 'PWD' : 'SENIOR CITIZEN'} DISCOUNT</p>
          <Row label="Name" value={statutory.beneficiary.name} />
          <Row label={statutory.type === 'pwd' ? 'PWD ID No.' : 'OSCA/SC ID No.'} value={statutory.beneficiary.id_masked ?? ''} />
          {statutory.beneficiary.tin && <Row label="TIN" value={statutory.beneficiary.tin} />}
          <p className="mt-4 border-t border-black pt-0.5 text-center">Signature</p>
        </>
      )}

      <Rule />
      {show('show_customer') && sale.customer ? (
        <>
          <Row label="Sold to" value={sale.customer.name} />
          {sale.customer.tin && <Row label="TIN" value={sale.customer.tin} />}
          {sale.customer.address && <p>Address: {sale.customer.address}</p>}
        </>
      ) : (
        <>
          <p>Sold to: ______________________</p>
          <p>TIN: __________________________</p>
          <p>Address: ______________________</p>
        </>
      )}
      {!isZero(sale.points.earned) && <p className="mt-1">Points earned: {sale.points.earned}</p>}

      <Rule />
      {receipt.footer_text && <p className="text-center whitespace-pre-line">{receipt.footer_text}</p>}
      {!vat && <p className="text-center font-bold">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p>}
      <p className="text-center">Thank you!</p>
    </div>
  )
}

/**
 * The same sales invoice on A4 paper (templates set to "A4 invoice"): a full
 * table layout with the BIR details in the header and the VAT breakdown.
 */
function A4Invoice({ sale, setup, reprint }: { sale: Sale; setup: PosSetup; reprint: boolean }) {
  const { business, branch, receipt } = setup
  const terminal = sale.terminal && sale.terminal.min !== undefined ? sale.terminal : setup.terminal
  const vat = business.vat_registered
  const tin = formatTin(business.tin, branch.tin_branch_code)
  const statutory = sale.discounts.find((d) => d.beneficiary)
  const t = sale.totals
  const line = (label: string, value: string, strong = false) => (
    <div className={strong ? 'flex justify-between border-t border-black pt-1 text-base font-bold' : 'flex justify-between'}>
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  )

  return (
    <div className="mx-auto bg-white p-2 font-sans text-[10pt] leading-snug text-black" style={{ width: '186mm' }} data-testid="receipt">
      <div className="flex items-start justify-between gap-6 border-b-2 border-black pb-3">
        <div className="flex gap-3">
          {receipt.show_logo && business.logo_url && <img src={business.logo_url} alt="" className="max-h-16" />}
          <div>
            <p className="text-lg font-bold uppercase">{business.name}</p>
            {business.registered_name && business.registered_name !== business.name && <p>{business.registered_name}</p>}
            {business.business_style && <p>{business.business_style}</p>}
            {(branch.address ?? business.address) && <p>{branch.address ?? business.address}</p>}
            {tin && <p>{vat ? 'VAT REG TIN' : 'NON-VAT REG TIN'}: {tin}</p>}
          </div>
        </div>
        <div className="text-right">
          <p className="text-xl font-bold">{reprint ? 'SALES INVOICE (REPRINT)' : 'SALES INVOICE'}</p>
          <p>No. {sale.invoice_no}</p>
          <p>{formatDateTime(sale.sold_at)}</p>
          {terminal?.min && <p className="text-[9pt]">MIN {terminal.min}{terminal.serial_no ? ` · SN ${terminal.serial_no}` : ''}</p>}
          {terminal?.ptu_no && <p className="text-[9pt]">PTU {terminal.ptu_no}</p>}
        </div>
      </div>

      <div className="mt-3 grid grid-cols-2 gap-6">
        <div>
          <p className="text-[8pt] font-bold uppercase">Sold to</p>
          <p>{sale.customer?.name ?? '______________________________'}</p>
          <p>TIN: {sale.customer?.tin ?? '__________________'}</p>
          <p>Address: {sale.customer?.address ?? '______________________________'}</p>
        </div>
        <div className="text-right">
          <p>Branch: {branch.name}</p>
          <p>Cashier: {sale.cashier?.name ?? ''}</p>
        </div>
      </div>

      <table className="mt-4 w-full border-collapse">
        <thead>
          <tr className="border-y border-black text-left">
            <th className="py-1 pr-2">Item</th>
            <th className="py-1 pr-2 text-right">Qty</th>
            <th className="py-1 pr-2 text-right">Unit price</th>
            <th className="py-1 pr-2 text-right">Discount</th>
            <th className="py-1 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          {sale.items.map((i) => {
            const less = [i.line_discount, i.allocated_discount, i.sc_pwd_discount].filter((x) => !isZero(x))
            return (
              <tr key={i.id} className="border-b border-gray-300">
                <td className="py-1 pr-2">
                  {i.name} <span className="text-[8pt]">{vat ? TAX_MARK[i.tax_class] : ''}</span>
                </td>
                <td className="py-1 pr-2 text-right">
                  {trimQty(i.qty)} {i.unit ?? ''}
                </td>
                <td className="py-1 pr-2 text-right">{peso(i.unit_price)}</td>
                <td className="py-1 pr-2 text-right">{less.length ? less.map((x) => `-${peso(x)}`).join(' ') : ''}</td>
                <td className="py-1 text-right">{peso(i.line_total)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>

      <div className="mt-3 grid grid-cols-2 gap-8">
        <div className="space-y-0.5">
          {vat ? (
            <>
              {line('VATable sales', peso(t.vatable_sales))}
              {line('VAT (12%)', peso(t.vat_amount))}
              {line('VAT-exempt sales', peso(t.vat_exempt_sales))}
              {line('Zero-rated sales', peso(t.zero_rated_sales))}
            </>
          ) : (
            line('Non-VAT sales', peso(t.total))
          )}
          {statutory?.beneficiary && (
            <div className="mt-2 border border-black p-2">
              <p className="font-bold">{statutory.type === 'pwd' ? 'PWD' : 'SENIOR CITIZEN'} DISCOUNT</p>
              <p>Name: {statutory.beneficiary.name}</p>
              <p>ID No.: {statutory.beneficiary.id_masked}</p>
              {statutory.beneficiary.tin && <p>TIN: {statutory.beneficiary.tin}</p>}
              <p className="mt-6 border-t border-black pt-0.5 text-center">Signature</p>
            </div>
          )}
        </div>
        <div className="space-y-0.5">
          {line('Subtotal', peso(t.gross))}
          {!isZero(t.line_discount) && line('Item discounts', `-${peso(t.line_discount)}`)}
          {!isZero(t.sc_pwd_discount) && line('SC/PWD discount', `-${peso(t.sc_pwd_discount)}`)}
          {!isZero(t.transaction_discount) && line('Sale discount', `-${peso(t.transaction_discount)}`)}
          {!isZero(t.loyalty_discount) && line('Points used', `-${peso(t.loyalty_discount)}`)}
          {line('TOTAL AMOUNT DUE', formatMoney(t.total), true)}
          {sale.payments.map((p, i) => (
            <div key={i}>{line(`${p.method ?? 'Payment'}${p.reference_no ? ` (${p.reference_no})` : ''}`, peso(p.tendered ?? p.amount))}</div>
          ))}
          {line('Change', peso(t.change))}
        </div>
      </div>

      {receipt.footer_text && <p className="mt-4 text-center whitespace-pre-line">{receipt.footer_text}</p>}
      {!vat && <p className="mt-2 text-center font-bold">THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX.</p>}
    </div>
  )
}

/** Renders the receipt into the print-only area (see printReceipt). */
export function ReceiptPrintArea({ children }: { children: ReactNode }) {
  const [host] = useState(() => {
    const el = document.createElement('div')
    el.id = 'receipt-print'
    return el
  })
  useEffect(() => {
    document.body.appendChild(host)
    return () => host.remove()
  }, [host])
  return createPortal(children, host)
}
