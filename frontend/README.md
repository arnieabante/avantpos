# AvantPOS — Frontend

React SPA for AvantPOS. It talks to the Laravel API only (`/api/v1`, `/api/admin/v1`).

| | |
|---|---|
| Stack | React 19, TypeScript, Vite 8, React Router 8 (data router), TanStack Query 5, Axios, Tailwind CSS 4, Public Sans (bundled font) |
| Tests | Vitest + Testing Library (jsdom) |
| Lint | oxlint |

## Setup

```bash
cd frontend
npm install
cp .env.example .env.local   # VITE_API_URL=http://localhost:8000
npm run dev                  # http://localhost:5173 (port is fixed: Sanctum/CORS allow it)
```

The API must be running (`php artisan serve` in `backend/`). Use **`localhost`**, not `127.0.0.1`,
for both, so the session and XSRF cookies are shared.

| Script | |
|---|---|
| `npm run dev` | dev server with HMR |
| `npm run build` | typecheck + production build to `dist/` |
| `npm test` / `npm run test:watch` | Vitest (through `scripts/vitest.mjs`, see below) |
| `npm run typecheck` / `npm run lint` | |

> **Windows:** `npm test` goes through `scripts/vitest.mjs`, which upper-cases the drive letter
> of the working directory. With `d:\...` (how VS Code opens this folder) Vitest otherwise loads
> twice and every test file fails with *"failed to find the current suite"*.

## Structure

```
src/
  app/            App (providers), router (lazy routes), error pages
  components/
    ui/           design system: Button, FormField/Input/Select/Checkbox, Card, StatCard, Badge, Alert,
                  Modal, ConfirmDialog (useConfirm), Dropdown, Toast (useToast), DataTable, Pagination,
                  FilterBar/SearchInput, Spinner/Skeleton, EmptyState, PageHeader
    layout/       AppLayout, Sidebar, Topbar (branch switcher, user menu), navigation.ts
  features/
    auth/         session (AuthProvider, useAuth, guards), auth API, login/register/password/verify/invite pages
    account/      My account (contact details, password, POS PIN)
    users/        team list, invitations, roles with permission matrix (Users & roles)
    catalog/      products (variants, selling units, scanner-friendly barcodes, branch prices),
                  categories, brands, units
    inventory/    stock levels & alerts, item detail (batches, ledger), movements, adjustments
                  (incl. opening stock), branch transfers, stock counts, valuation
    purchasing/   suppliers, purchase orders (approval, low-stock suggestions, printable A4), goods
                  received (against a PO or direct, input VAT split, reversal), supplier bills,
                  payments and statements
    customers/    customers (SC/PWD IDs encrypted + masked), groups, account statement &
                  payments ("utang"), loyalty points; loyalty settings live under settings/
    cash/         POS register shifts: start (terminal + counted float), cash in/out/payout,
                  blind close with bill/coin count, shift reports
    pos/          selling screen (scan / search / category tiles, cart priced by the server's
                  /pos/quote), discounts, Senior/PWD, customer & points, split payments,
                  supervisor PIN approvals, held sales, BIR sales invoice printing (58/80 mm);
                  offline/: catalog + sales queue in IndexedDB (lib/localStore), device pricing
                  with the server's rounding, upload to /pos/sync; public/sw.js keeps the app
                  loadable offline (production builds only)
    sales/        sales history & detail, reprint (logged), void (same open shift), returns /
                  refunds (per-line share, restock or write off), X and Z readings
    reports/      16+ reports (sales, profit, VAT, SC/PWD book, products, cashiers, stock, purchases,
                  expenses, payables aging) with date/branch filters, print, CSV/Excel; home sales overview
    expenses/     expense recording, approval, receipts (private files), categories
    billing/      the business's AvantPOS subscription: plans, invoices, online / bank-transfer payment
    support/      support tickets to AvantPOS
    dashboard/    Home
    admin/        platform admin: overview (MRR/ARR), businesses, billing, plans, support, activity,
                  settings, two-factor sign-in
    settings/     business profile, branches, terminals & drawers, taxes, payment methods,
                  discounts, receipt templates (Settings section, pages gated per permission)
  hooks/          useForm (maps Laravel 422 errors onto fields), useAction (one-click actions + toast)
  lib/
    api/          client (axios + CSRF + X-Branch-Id + session events), ApiError, envelope types
    format.ts     peso/date/phone formatting (display only)
  test/           setup, render helpers, fake backend for the real axios client
```

## Rules

- **No business logic or money math in React.** Amounts come from the API as decimal strings and
  are only formatted (`formatMoney`). Totals, discounts and taxes are computed server-side.
- **All API calls go through `lib/api/client.ts`** (`api.get/post/...` unwrap `{ data }`;
  failures are always `ApiError` with `status`, `code`, `errors`, `context`).
- **Server state lives in TanStack Query**, never copied into component state. Every query except
  `['session']` is cleared on sign-in, sign-out and branch switch, so data never leaks between
  users or branches.
- **Permissions in the UI are a convenience** (`useAuth().can()`, `<Can>`, `<RequirePermission>`,
  `navigation.ts`); the API enforces them.
- **Forms**: `useForm` + `FormField`; client checks are quick hints, the API is the validator.
- **New module**: add pages under `features/<module>/`, a lazy route in `app/router.tsx`,
  and a menu entry in `components/layout/navigation.ts` (with `permissions` / `feature`).

## Authentication

Sanctum SPA cookies, no tokens in JS storage:

1. Before the first POST/PUT/PATCH/DELETE the client fetches `GET /sanctum/csrf-cookie`.
2. Axios sends `X-XSRF-TOKEN` from the cookie (`withXSRFToken`, the API is cross-origin in production).
3. A `419` refreshes the cookie and retries once.
4. A `401` signs the user out locally. A `403` with `TENANT_SUSPENDED`, `TENANT_CANCELLED`,
   `TENANT_MISMATCH` or `USER_INACTIVE` shows the "account unavailable" screen, which can still
   sign out. `BRANCH_FORBIDDEN` reloads the session and falls back to an accessible branch.

The selected branch is remembered per user (`localStorage`, id only) and sent as `X-Branch-Id`;
the API validates it on every request.

E-mail links point at SPA routes: `/verify-email?url=<signed API URL>` (only our own verify
endpoint is accepted), `/reset-password?token=&email=`, `/accept-invitation?token=`.
Platform administrators sign in at `/admin/login` (separate `platform` guard).
