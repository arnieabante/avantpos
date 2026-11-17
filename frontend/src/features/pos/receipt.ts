import type { ReceiptSettings, TaxClass } from './types'

/** "123456789" + "00001" → "123-456-789-00001"; other formats are shown as entered. */
export function formatTin(tin: string | null | undefined, branchCode?: string | null): string | null {
  if (!tin) return null
  const digits = tin.replace(/\D/g, '')
  if (digits.length === 9 || digits.length === 12 || digits.length === 14) {
    const base = `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6, 9)}`
    const branch = digits.length > 9 ? digits.slice(9) : (branchCode ?? '')
    return branch ? `${base}-${branch.padStart(5, '0')}` : base
  }
  return tin
}

/** Letter printed after each line amount: V = VATable, E = VAT-exempt, Z = zero-rated. */
export const TAX_MARK: Record<TaxClass, string> = { vatable: 'V', vat_exempt: 'E', zero_rated: 'Z', non_vat: '' }

/** Printable width of the paper roll (the printer keeps a small margin). */
export const PAPER_WIDTH: Record<ReceiptSettings['paper'], string> = { '58mm': '48mm', '80mm': '72mm', a4: '120mm' }

/**
 * Print only the receipt (see #receipt-print in index.css), sized to the
 * paper roll. The browser's print dialog lets the cashier pick the printer;
 * kiosk-mode browsers print straight to the default thermal printer.
 */
export function printReceipt(paper: ReceiptSettings['paper']): void {
  const page = document.createElement('style')
  page.textContent = paper === 'a4' ? '@page { size: A4; margin: 12mm; }' : `@page { size: ${paper} auto; margin: 2mm; }`
  document.head.appendChild(page)
  document.body.classList.add('printing-receipt')

  const done = () => {
    document.body.classList.remove('printing-receipt')
    page.remove()
    window.removeEventListener('afterprint', done)
  }
  window.addEventListener('afterprint', done)
  window.print()
  // Some browsers do not fire afterprint (or print() returns immediately).
  window.setTimeout(done, 1000)
}
