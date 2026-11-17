import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { BadgePercent, HeartHandshake, ListRestart, Minus, PauseCircle, Plus, ScanBarcode, ShoppingCart, Trash2, Undo2, UserRound } from 'lucide-react'
import { ApiErrorAlert } from '@/components/ApiErrorAlert'
import { Alert, Badge, Button, FormField, IconButton, Input, Modal, PageLoader, Spinner, useConfirm, useToast } from '@/components/ui'
import { useAuth } from '@/features/auth/useAuth'
import { cashKeys } from '@/features/cash/api'
import { RefundModal } from '@/features/sales/components/RefundModal'
import { ReturnLookupModal } from '@/features/sales/components/ReturnLookupModal'
import { ApiError } from '@/lib/api/errors'
import { addQty, compareDecimal, multiplyToMoney, sumMoney, trimQty } from '@/lib/decimal'
import { formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'
import { uuid } from '@/lib/uuid'
import { posApi, posKeys } from '../api'
import {
  addItem,
  cartRequest,
  emptyCart,
  heldCartPayload,
  lineErrors,
  parseEntry,
  removeLine,
  restoreCart,
  setScPwd,
  unitOf,
  updateLine,
  type Cart,
  type CartLine,
} from '../cart'
import { cartStorage, useDebouncedValue } from '../hooks'
import { OfflineBar } from '../offline/OfflineBar'
import { offlineBlockers, offlineQuote } from '../offline/pricing'
import { buildOfflineSale } from '../offline/sale'
import { cachedRead, invoiceCounter, scanCatalog, searchCatalog } from '../offline/store'
import { isNetworkError, useOfflinePos } from '../offline/useOfflinePos'
import { paymentsPayload, type Tender } from '../payments'
import { APPROVAL_LABELS, type PosItem, type PosSetup, type Quote, type QuoteLine, type Sale } from '../types'
import { ApprovalModal } from './ApprovalModal'
import { CustomerModal } from './CustomerModal'
import { HeldCartsModal } from './HeldCartsModal'
import { LineModal } from './LineModal'
import { PaymentModal } from './PaymentModal'
import { SaleDiscountModal } from './SaleDiscountModal'
import { SaleDoneModal } from './SaleDoneModal'
import { ScPwdModal } from './ScPwdModal'

type Dialog =
  | { kind: 'line'; key: string }
  | { kind: 'customer' }
  | { kind: 'scpwd' }
  | { kind: 'discount' }
  | { kind: 'hold' }
  | { kind: 'held' }
  | { kind: 'pay' }
  | { kind: 'done'; sale: Sale }
  | { kind: 'return' }
  | { kind: 'refund'; sale: Sale }
  | null

const SHORTCUTS = 'F2 search · F3 customer · F4 Senior/PWD · F6 discount · F8 hold · F9 held sales · F10 return · F12 pay'

/** The selling screen: scan or pick items, adjust, take payment, print the invoice. */
export function SellScreen({ sessionId }: { sessionId: number }) {
  const { can, account, activeBranch } = useAuth()
  // Kept on the device so the selling screen also opens offline.
  const setup = useQuery({ queryKey: posKeys.setup, queryFn: () => cachedRead(`setup.${activeBranch?.id ?? 0}`, posApi.setup), staleTime: 5 * 60_000 })

  if (setup.isPending) return <PageLoader />
  if (setup.isError) return <ApiErrorAlert error={ApiError.from(setup.error)} />
  if (!setup.data.session || setup.data.session.id !== sessionId || !account) {
    return <Alert tone="warning">Your shift is not open in this branch.</Alert>
  }
  return <Register setup={setup.data} sessionId={sessionId} can={can} user={{ id: account.user.id, name: account.user.name }} />
}

function Register({ setup, sessionId, can, user }: { setup: PosSetup; sessionId: number; can: (p: string) => boolean; user: { id: number; name: string } }) {
  const userId = user.id
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const searchRef = useRef<HTMLInputElement>(null)
  const terminalId = setup.terminal?.id ?? null
  const offline = useOfflinePos({ branchId: setup.branch.id, userId, terminalId })
  const isOffline = !offline.online

  const [cart, setCart] = useState<Cart>(() => restoreCart(cartStorage.load(sessionId)))
  const [dialog, setDialog] = useState<Dialog>(null)
  const [entry, setEntry] = useState('')
  const [categoryId, setCategoryId] = useState<number | null>(null)
  const [scanning, setScanning] = useState(false)
  const [checkout, setCheckout] = useState<{ id: string; tenders: Tender[] | null; tokens: Record<string, string>; approvals: string[] | null }>({
    id: uuid(),
    tenders: null,
    tokens: {},
    approvals: null,
  })
  const [paying, setPaying] = useState(false)
  const [payError, setPayError] = useState<ApiError | null>(null)

  /* ------------------------------------------------------------ cart → server quote */
  const request = useMemo(() => cartRequest(cart), [cart])
  const debounced = useDebouncedValue(request, 250)
  const quote = useQuery({
    queryKey: posKeys.quote(debounced),
    queryFn: ({ signal }) => posApi.quote(debounced, signal),
    enabled: debounced.items.length > 0 && !isOffline,
    placeholderData: keepPreviousData,
    retry: false,
    staleTime: 15_000,
  })
  // Offline: priced on the device with the server's rules (plain sales only).
  const blockers = isOffline ? offlineBlockers(cart) : []
  const localQuote = useMemo(() => (isOffline && cart.lines.length ? offlineQuote(cart, setup.vat_rate) : undefined), [isOffline, cart, setup.vat_rate])
  const quoteFailedOffline = quote.isError && isNetworkError(quote.error)
  const { markServerDown } = offline
  useEffect(() => {
    if (quoteFailedOffline) markServerDown()
  }, [quoteFailedOffline, markServerDown])

  const fresh = isOffline
    ? cart.lines.length > 0 && blockers.length === 0
    : cart.lines.length > 0 && debounced === request && !quote.isPlaceholderData && quote.isSuccess && !quote.isFetching
  const quoteError = !isOffline && cart.lines.length > 0 && debounced === request && quote.isError ? ApiError.from(quote.error) : null
  const errors = quoteError?.isValidation ? lineErrors(quoteError.errors, cart) : { byLine: {}, general: [] as string[] }
  const q = cart.lines.length > 0 ? (isOffline ? localQuote : quote.data) : undefined

  // Keep the cart across reloads (without the Senior/PWD ID number).
  useEffect(() => {
    cartStorage.save(sessionId, cart.lines.length ? heldCartPayload(cart, null) : null)
  }, [cart, sessionId])

  /* ------------------------------------------------------------ items */
  const term = useDebouncedValue(entry.trim(), 300)
  const searchTerm = parseEntry(term).code
  const remoteItems = useQuery({
    queryKey: posKeys.items(searchTerm, searchTerm ? null : categoryId),
    queryFn: () => posApi.items(searchTerm, searchTerm ? null : categoryId),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    enabled: !isOffline,
  })
  const localItems = useMemo(
    () => (isOffline ? searchCatalog(offline.catalog?.items ?? [], searchTerm, searchTerm ? null : categoryId) : []),
    [isOffline, offline.catalog, searchTerm, categoryId],
  )
  const items = isOffline
    ? { isError: false as const, isPending: false as const, error: null, data: localItems as PosItem[] }
    : remoteItems

  const add = useCallback(
    (item: PosItem, unitId: number | null = null, qty = '1') => {
      setCart((c) => addItem(c, item, unitId, qty))
      setEntry('')
      searchRef.current?.focus()
    },
    [],
  )

  const scan = async () => {
    const { qty, code } = parseEntry(entry)
    if (!code) return
    const scanLocally = () => {
      const local = scanCatalog(offline.catalog?.items ?? [], code)
      const found = local ? [local] : searchCatalog(offline.catalog?.items ?? [], code, null)
      if (found.length === 1) add(found[0]!, local?.scanned_unit_id ?? null, qty)
      else if (found.length === 0) toast.error(`Nothing found for "${code}"`, offline.catalog ? undefined : 'The item list has not been saved on this device yet.')
      else toast.info(`${found.length} items match "${code}"`, 'Tap the one you want.')
    }
    if (isOffline) {
      scanLocally()
      return
    }
    setScanning(true)
    try {
      const item = await posApi.scan(code)
      add(item, item.scanned_unit_id ?? null, qty)
    } catch (e) {
      const error = ApiError.from(e)
      if (isNetworkError(e)) {
        offline.markServerDown()
        scanLocally()
      } else if (error.status === 404) {
        // Not a barcode: if the name search finds exactly one item, take it.
        const found = await posApi.items(code, null).catch(() => [])
        if (found.length === 1) add(found[0]!, null, qty)
        else if (found.length === 0) toast.error(`Nothing found for "${code}"`)
        else toast.info(`${found.length} items match "${code}"`, 'Tap the one you want.')
      } else {
        toast.error(error.message)
      }
    }
    setScanning(false)
  }

  /* ------------------------------------------------------------ actions */
  const scActive = cart.scPwd !== null
  const eligibleCount = cart.lines.filter((l) => l.item.sc_pwd_eligible).length
  const total = q?.totals.total ?? null

  const openPay = () => {
    if (!cart.lines.length) return
    if (blockers.length) {
      toast.error('Not available offline', `Remove ${blockers.join(', ')} to sell without a connection.`)
      return
    }
    if (!fresh || !q) {
      toast.info(quoteError ? 'Fix the problems on the sale first.' : 'Still calculating the total…')
      return
    }
    setPayError(null)
    setCheckout({ id: uuid(), tenders: null, tokens: {}, approvals: null })
    setDialog({ kind: 'pay' })
  }

  const submit = async (tenders: Tender[], tokens: Record<string, string>) => {
    if (!q) return
    const needed = [...q.approvals_missing]
    if (tenders.some((t) => t.method.type === 'credit') && !can('pos.credit_sale') && !needed.includes('pos.credit_sale')) needed.push('pos.credit_sale')
    const missing = needed.filter((p) => !tokens[p])
    if (missing.length) {
      setCheckout((c) => ({ ...c, tenders, tokens, approvals: needed }))
      return
    }
    setPaying(true)
    setPayError(null)
    const done = (sale: Sale) => {
      setCart(emptyCart())
      setCheckout({ id: uuid(), tenders: null, tokens: {}, approvals: null })
      setDialog({ kind: 'done', sale })
    }
    const sellOffline = async () => {
      const invoiceNo = await invoiceCounter.peek(terminalId ?? 0, setup.next_invoice_no)
      const sale = buildOfflineSale({
        clientUuid: checkout.id,
        invoiceNo,
        sessionId,
        cart,
        quote: offlineQuote(cart, setup.vat_rate),
        tenders,
        setup,
        cashier: user,
        pricingVersion: offline.catalog?.version ?? null,
      })
      await offline.enqueue(sale)
      await invoiceCounter.use(terminalId ?? 0, invoiceNo)
      done(sale.receipt)
    }
    try {
      if (isOffline) {
        await sellOffline()
        setPaying(false)
        return
      }
      // Offline sales go up first, so invoice numbers stay in order.
      if (offline.queue.some((s) => !s.error) && (await offline.sync()) === null) {
        await sellOffline()
        setPaying(false)
        return
      }
      const sale = await posApi.checkout({ ...request, client_uuid: checkout.id, payments: paymentsPayload(tenders), overrides: tokens })
      if (terminalId) await invoiceCounter.use(terminalId, sale.invoice_no)
      done(sale)
      void queryClient.invalidateQueries({ queryKey: ['pos', 'items'] })
      void queryClient.invalidateQueries({ queryKey: cashKeys.all })
    } catch (e) {
      const error = ApiError.from(e)
      if (isNetworkError(e)) {
        // The sale may or may not have reached the server: retrying with the same ID is safe.
        offline.markServerDown()
        setPayError(new ApiError('The connection dropped. Press Complete sale again to finish it offline.', { code: error.code }))
      } else if (error.code === 'APPROVAL_REQUIRED' || error.code === 'OVERRIDE_REQUIRED') {
        const perms = Array.isArray(error.context.permissions) ? (error.context.permissions as string[]) : needed
        setCheckout((c) => ({ ...c, tenders, tokens: {}, approvals: perms }))
      } else {
        setPayError(error)
      }
    }
    setPaying(false)
  }

  const clear = async () => {
    if (!cart.lines.length) return
    const ok = await confirm({ title: 'Cancel this sale?', description: 'All items are removed. Nothing is charged.', confirmLabel: 'Cancel sale', cancelLabel: 'Keep sale', danger: true })
    if (ok) setCart(emptyCart())
  }

  /* ------------------------------------------------------------ keyboard */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (dialog) return
      const map: Record<string, () => void> = {
        F2: () => searchRef.current?.focus(),
        F3: () => !isOffline && setup.features.customers && setDialog({ kind: 'customer' }),
        F4: () => !isOffline && setDialog({ kind: 'scpwd' }),
        F6: () => !isOffline && cart.lines.length && setDialog({ kind: 'discount' }),
        F8: () => !isOffline && can('pos.hold') && cart.lines.length && setDialog({ kind: 'hold' }),
        F9: () => !isOffline && can('pos.hold') && setDialog({ kind: 'held' }),
        F10: () => !isOffline && setDialog({ kind: 'return' }),
        F12: openPay,
      }
      const action = map[e.key]
      if (action) {
        e.preventDefault()
        action()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  const editing = dialog?.kind === 'line' ? cart.lines.find((l) => l.key === dialog.key) : undefined
  const topCategories = setup.categories.filter((c) => c.parent_id === null)

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px]">
      {/* ---------------------------------------------------------------- items */}
      <OfflineBar offline={offline} isOffline={isOffline} />
      <section aria-label="Items" className="min-w-0 space-y-3">
        <form
          onSubmit={(e) => {
            e.preventDefault()
            void scan()
          }}
        >
          <Input
            ref={searchRef}
            aria-label="Scan or search items"
            placeholder="Scan a barcode or type to search (3*code for 3 pcs)"
            value={entry}
            onChange={(e) => setEntry(e.target.value)}
            onKeyDown={(e) => e.key === 'Escape' && setEntry('')}
            leftAddon={scanning ? <Spinner className="size-4" /> : <ScanBarcode className="size-4" />}
            className="h-12 text-base"
            autoComplete="off"
            autoFocus
          />
        </form>

        {topCategories.length > 0 && (
          <div role="tablist" aria-label="Categories" className="flex gap-2 overflow-x-auto pb-1">
            {[{ id: null, name: 'All', color: null }, ...topCategories].map((c) => (
              <button
                key={c.id ?? 'all'}
                type="button"
                role="tab"
                aria-selected={categoryId === c.id}
                onClick={() => {
                  setCategoryId(c.id)
                  setEntry('')
                }}
                className={cn(
                  'shrink-0 rounded-full border px-3 py-1.5 text-sm whitespace-nowrap',
                  categoryId === c.id ? 'border-brand-500 bg-brand-500 text-white' : 'border-line bg-surface text-ink hover:border-brand-500',
                )}
                style={categoryId !== c.id && c.color ? { borderLeftColor: c.color, borderLeftWidth: 4 } : undefined}
              >
                {c.name}
              </button>
            ))}
          </div>
        )}

        {items.isError ? (
          <ApiErrorAlert error={ApiError.from(items.error)} />
        ) : items.isPending ? (
          <div className="flex justify-center p-8">
            <Spinner />
          </div>
        ) : items.data.length === 0 ? (
          <p className="rounded-md border border-dashed border-line p-6 text-center text-sm text-ink-subtle">No items found.</p>
        ) : (
          <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
            {items.data.map((item) => {
              const out = item.track_inventory && !item.allow_negative_stock && compareDecimal(item.qty_on_hand, '0') !== 1
              return (
                <li key={item.product_variant_id}>
                  <button
                    type="button"
                    onClick={() => add(item)}
                    disabled={out}
                    className="flex h-full w-full flex-col rounded-md border border-line bg-surface p-3 text-left shadow-xs transition hover:border-brand-500 disabled:cursor-not-allowed disabled:opacity-50"
                    style={item.color ? { borderTopColor: item.color, borderTopWidth: 3 } : undefined}
                  >
                    <span className="line-clamp-2 text-sm font-medium text-ink">{item.name}</span>
                    <span className="mt-auto flex items-end justify-between gap-2 pt-2">
                      <span className="font-semibold text-brand-600 tabular-nums">{formatMoney(item.price)}</span>
                      {item.track_inventory && (
                        <span className={cn('text-xs whitespace-nowrap', out ? 'text-[#ff3e1d]' : 'text-ink-subtle')}>
                          {out ? 'Out of stock' : `${trimQty(item.qty_on_hand)} left`}
                        </span>
                      )}
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        )}
        <p className="hidden text-xs text-ink-subtle lg:block">{SHORTCUTS}</p>
      </section>

      {/* ---------------------------------------------------------------- cart */}
      <section aria-label="Current sale" className="flex flex-col rounded-md border border-line bg-surface shadow-sm lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]">
        <div className="flex items-center gap-2 border-b border-line p-3">
          {setup.features.customers ? (
            <Button size="sm" variant={cart.customer ? 'outline' : 'ghost'} leftIcon={<UserRound className="size-4" />} disabled={isOffline} onClick={() => setDialog({ kind: 'customer' })}>
              {cart.customer ? cart.customer.name : 'Customer'}
            </Button>
          ) : (
            <span className="text-sm font-medium text-ink">Walk-in</span>
          )}
          {cart.redeemPoints && <Badge tone="info">{cart.redeemPoints} pts</Badge>}
          <div className="ml-auto flex gap-1">
            <IconButton label="Return / refund (F10)" disabled={isOffline} onClick={() => setDialog({ kind: 'return' })}>
              <Undo2 className="size-4" />
            </IconButton>
            {can('pos.hold') && (
              <IconButton label="Held sales (F9)" disabled={isOffline} onClick={() => setDialog({ kind: 'held' })}>
                <ListRestart className="size-4" />
              </IconButton>
            )}
            {can('pos.cancel_cart') && (
              <IconButton label="Cancel sale" onClick={clear} disabled={!cart.lines.length}>
                <Trash2 className="size-4" />
              </IconButton>
            )}
          </div>
        </div>

        {scActive && (
          <button type="button" onClick={() => setDialog({ kind: 'scpwd' })} className="flex items-center gap-2 border-b border-line bg-[#fff2d6] px-3 py-2 text-left text-sm text-[#8a5a00]">
            <HeartHandshake className="size-4 shrink-0" aria-hidden="true" />
            {cart.scPwd!.type === 'pwd' ? 'PWD' : 'Senior Citizen'}: {cart.scPwd!.name}
            {!cart.scPwd!.id_no && <span className="font-medium"> · enter the ID number</span>}
          </button>
        )}

        <div className="min-h-40 flex-1 overflow-y-auto">
          {cart.lines.length === 0 ? (
            <div className="flex h-full flex-col items-center justify-center gap-2 p-8 text-center text-sm text-ink-subtle">
              <ShoppingCart className="size-8" aria-hidden="true" />
              Scan or tap an item to start a sale.
            </div>
          ) : (
            <ul aria-label="Items on this sale" className="divide-y divide-line">
              {cart.lines.map((line, i) => (
                <CartRow
                  key={line.key}
                  line={line}
                  quoted={fresh ? q?.lines[i] : undefined}
                  error={errors.byLine[line.key]}
                  onOpen={() => setDialog({ kind: 'line', key: line.key })}
                  onStep={(delta) => {
                    const next = addQty(line.qty, delta)
                    if (next && compareDecimal(next, '0') === 1) setCart((c) => updateLine(c, line.key, { qty: trimQty(next) }))
                    else setCart((c) => removeLine(c, line.key))
                  }}
                />
              ))}
            </ul>
          )}
        </div>

        <div className="space-y-2 border-t border-line p-3">
          {quoteError && !quoteError.isValidation && <ApiErrorAlert error={quoteError} />}
          {errors.general.map((m) => (
            <p key={m} className="text-sm text-[#ff3e1d]">
              {m}
            </p>
          ))}
          {fresh && q && q.approvals_missing.length > 0 && (
            <p className="text-xs text-[#8a5a00]">Needs approval: {q.approvals_missing.map((p) => APPROVAL_LABELS[p] ?? p).join(', ')}</p>
          )}
          <Totals quote={q} vat={setup.business.vat_registered} stale={!fresh && cart.lines.length > 0} />

          <div className="grid grid-cols-3 gap-2">
            <Button size="sm" variant="secondary" leftIcon={<BadgePercent className="size-4" />} disabled={!cart.lines.length || isOffline} onClick={() => setDialog({ kind: 'discount' })}>
              {cart.discount ? 'Discount ✓' : 'Discount'}
            </Button>
            <Button size="sm" variant="secondary" leftIcon={<HeartHandshake className="size-4" />} disabled={isOffline} onClick={() => setDialog({ kind: 'scpwd' })}>
              SC/PWD
            </Button>
            {can('pos.hold') ? (
              <Button size="sm" variant="secondary" leftIcon={<PauseCircle className="size-4" />} disabled={!cart.lines.length || isOffline} onClick={() => setDialog({ kind: 'hold' })}>
                Hold
              </Button>
            ) : (
              <span />
            )}
          </div>
          <Button size="xl" fullWidth onClick={openPay} disabled={!cart.lines.length || !!quoteError} loading={cart.lines.length > 0 && !fresh && !quoteError}>
            Pay {total && cart.lines.length ? formatMoney(total) : ''}
          </Button>
        </div>
      </section>

      {/* ---------------------------------------------------------------- dialogs */}
      {editing && (
        <LineModal
          line={editing}
          presets={setup.discount_presets}
          scPwdActive={scActive}
          onClose={() => setDialog(null)}
          onRemove={() => {
            setCart((c) => removeLine(c, editing.key))
            setDialog(null)
          }}
          onSave={(patch) => {
            setCart((c) => updateLine(c, editing.key, patch))
            setDialog(null)
          }}
        />
      )}
      {dialog?.kind === 'customer' && (
        <CustomerModal
          customer={cart.customer}
          redeemPoints={cart.redeemPoints}
          loyalty={setup.loyalty}
          scPwdActive={scActive}
          onClose={() => setDialog(null)}
          onSave={(customer, redeemPoints) => {
            setCart((c) => ({ ...c, customer, redeemPoints }))
            setDialog(null)
          }}
        />
      )}
      {dialog?.kind === 'scpwd' && (
        <ScPwdModal
          details={cart.scPwd}
          suggestedName={cart.customer?.sc_pwd ? cart.customer.name : null}
          suggestedType={cart.customer?.sc_pwd?.type ?? null}
          eligibleCount={eligibleCount}
          onClose={() => setDialog(null)}
          onSave={(details) => {
            setCart((c) => setScPwd(c, details))
            setDialog(null)
          }}
        />
      )}
      {dialog?.kind === 'discount' && (
        <SaleDiscountModal
          discount={cart.discount}
          presets={setup.discount_presets}
          maxPercent={setup.max_discount_percent}
          onClose={() => setDialog(null)}
          onSave={(discount) => {
            setCart((c) => ({ ...c, discount }))
            setDialog(null)
          }}
        />
      )}
      {dialog?.kind === 'hold' && (
        <HoldModal
          cart={cart}
          total={fresh ? (total ?? null) : null}
          onClose={() => setDialog(null)}
          onHeld={() => {
            setCart(emptyCart())
            setDialog(null)
            toast.success('Sale on hold', 'Resume it from Held sales (F9).')
          }}
        />
      )}
      {dialog?.kind === 'held' && (
        <HeldCartsModal
          cartInUse={cart.lines.length > 0}
          onClose={() => setDialog(null)}
          onResume={(saved) => {
            setCart(restoreCart(saved))
            setDialog(null)
          }}
        />
      )}
      {dialog?.kind === 'pay' && q && (
        <PaymentModal
          total={q.totals.total}
          methods={isOffline ? setup.payment_methods.filter((m) => m.type !== 'credit') : setup.payment_methods}
          customer={q.customer}
          busy={paying}
          error={payError}
          onClose={() => setDialog(null)}
          onSubmit={(tenders) => void submit(tenders, checkout.tokens)}
        />
      )}
      {dialog?.kind === 'pay' && checkout.approvals && checkout.tenders && (
        <ApprovalModal
          permissions={checkout.approvals}
          approvers={setup.approvers}
          selfId={userId}
          onClose={() => setCheckout((c) => ({ ...c, approvals: null }))}
          onApproved={(tokens) => {
            const tenders = checkout.tenders!
            setCheckout((c) => ({ ...c, tokens, approvals: null }))
            void submit(tenders, tokens)
          }}
        />
      )}
      {dialog?.kind === 'return' && <ReturnLookupModal onClose={() => setDialog(null)} onPick={(sale) => setDialog({ kind: 'refund', sale })} />}
      {dialog?.kind === 'refund' && (
        <RefundModal
          sale={dialog.sale}
          setup={setup}
          onClose={() => setDialog(null)}
          onRefunded={({ refund }) => {
            setDialog(null)
            void queryClient.invalidateQueries({ queryKey: ['pos', 'items'] })
            void queryClient.invalidateQueries({ queryKey: cashKeys.all })
            const out = refund.payments.map((p) => `${formatMoney(p.amount)} by ${p.method ?? 'cash'}`).join(' and ')
            toast.success(`Refund ${refund.number} done`, out ? `Give back ${out}.` : 'Credited to the customer account.')
          }}
        />
      )}
      {dialog?.kind === 'done' && (
        <SaleDoneModal
          sale={dialog.sale}
          setup={setup}
          onClose={() => {
            setDialog(null)
            searchRef.current?.focus()
          }}
        />
      )}
    </div>
  )
}

/* ------------------------------------------------------------------ pieces */

function CartRow({ line, quoted, error, onOpen, onStep }: { line: CartLine; quoted: QuoteLine | undefined; error?: string; onOpen: () => void; onStep: (delta: '1' | '-1') => void }) {
  const unit = unitOf(line)
  const price = line.priceOverride ?? unit.price
  const estimate = multiplyToMoney(line.qty, price)
  const discounted = quoted && (compareDecimal(quoted.line_discount, '0') === 1 || compareDecimal(quoted.sc_pwd_discount, '0') === 1)

  return (
    <li className="flex items-start gap-2 p-3">
      <button type="button" onClick={onOpen} className="min-w-0 flex-1 text-left" aria-label={`Edit ${line.item.name}`}>
        <span className="block truncate text-sm font-medium text-ink">{line.item.name}</span>
        <span className="block text-xs text-ink-subtle">
          {trimQty(line.qty)} {unit.abbreviation} × {formatMoney(quoted?.unit_price ?? price)}
          {line.priceOverride && <Badge tone="warning" className="ml-1">Price changed</Badge>}
          {line.scPwd && <Badge tone="info" className="ml-1">SC/PWD</Badge>}
          {line.discount && !line.scPwd && <Badge tone="success" className="ml-1">Discount</Badge>}
        </span>
        {error && <span className="mt-1 block text-xs text-[#ff3e1d]">{error}</span>}
      </button>
      <div className="flex items-center">
        <IconButton label={`One less ${line.item.name}`} size="sm" onClick={() => onStep('-1')}>
          <Minus className="size-3.5" />
        </IconButton>
        <IconButton label={`One more ${line.item.name}`} size="sm" onClick={() => onStep('1')}>
          <Plus className="size-3.5" />
        </IconButton>
      </div>
      <div className="w-24 text-right">
        <span className={cn('block text-sm font-medium tabular-nums', quoted ? 'text-ink' : 'text-ink-subtle')}>{formatMoney(quoted?.line_total ?? estimate)}</span>
        {discounted && <span className="block text-xs text-ink-subtle line-through tabular-nums">{formatMoney(quoted.gross)}</span>}
      </div>
    </li>
  )
}

function Totals({ quote, vat, stale }: { quote: Quote | undefined; vat: boolean; stale: boolean }) {
  if (!quote) return null
  const t = quote.totals
  const discounts = sumMoney([t.line_discount, t.transaction_discount, t.loyalty_discount])
  const row = (label: string, value: string, negative = false) => (
    <div className="flex justify-between text-sm">
      <span>{label}</span>
      <span className="tabular-nums">
        {negative ? '−' : ''}
        {formatMoney(value)}
      </span>
    </div>
  )
  return (
    <div className={cn('space-y-1', stale && 'opacity-60')} aria-busy={stale}>
      {row('Subtotal', t.gross)}
      {compareDecimal(discounts, '0') === 1 && row('Discounts', discounts, true)}
      {compareDecimal(t.sc_pwd_discount, '0') === 1 && row('Senior/PWD discount', t.sc_pwd_discount, true)}
      {vat && row('VAT included', t.vat_amount)}
      <div className="flex justify-between pt-1 text-lg font-semibold text-ink">
        <span>Total</span>
        <span className="tabular-nums" data-testid="cart-total">
          {formatMoney(t.total)}
        </span>
      </div>
    </div>
  )
}

function HoldModal({ cart, total, onClose, onHeld }: { cart: Cart; total: string | null; onClose: () => void; onHeld: () => void }) {
  const [label, setLabel] = useState(cart.customer?.name ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [requestId] = useState(uuid)

  const hold = async () => {
    setBusy(true)
    setError(null)
    try {
      await posApi.hold({ client_uuid: requestId, label: label.trim() || null, customer_id: cart.customer?.id ?? null, cart: heldCartPayload(cart, total) })
      onHeld()
    } catch (e) {
      setError(ApiError.from(e))
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      dismissible={!busy}
      title="Hold this sale"
      description="Park it to serve the next customer. Any cashier at this branch can resume it."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={hold} loading={busy}>
            Hold sale
          </Button>
        </>
      }
    >
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault()
          void hold()
        }}
      >
        <ApiErrorAlert error={error} />
        <FormField label="Name or note" hint="To find it again, e.g. the customer's name.">
          <Input value={label} maxLength={80} onChange={(e) => setLabel(e.target.value)} data-autofocus />
        </FormField>
        {cart.scPwd && <p className="text-xs text-ink-subtle">The Senior/PWD ID number is not kept; enter it again when resuming.</p>}
      </form>
    </Modal>
  )
}
