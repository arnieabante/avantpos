# AvantPOS — Architecture

Approved in Phase 1, amended with the Phase 1 decisions (offline at launch, FIFO,
retail verticals, subdomains, mobile, Xendit). This file records **decisions and the
rules code must follow**; details live in the code it points to.

## 1. Shape

```
React SPA / PWA (app.avantpos.ph, {tenant}.avantpos.ph)
        │  HTTPS JSON (Sanctum cookie)          Native app (later, Capacitor): bearer token
        ▼                                         POS device: device token (terminal:sync)
Laravel API (api.avantpos.ph)  ──  MySQL 8 (shared DB, tenant_id)  ──  Redis (queues/cache, prod)
        │                                                          ──  S3-compatible storage
        └─ queue workers + scheduler
```

- Strict separation: React talks to Laravel only through `/api/v1` (tenant) and `/api/admin/v1` (platform).
- Business logic lives in `app/Services` and `app/Actions`; controllers stay thin.
- Money: `DECIMAL` in MySQL, `App\Support\Money\Money` (brick/math) in PHP. **Never floats.**

## 2. Multi-tenancy (shared database)

The tenant of a request is **always derived from the authenticated actor** (user or terminal),
never from a header, subdomain or body. Five layers:

| Layer | Where |
|---|---|
| Tenant resolution + suspended/cancelled/mismatch checks | `app/Http/Middleware/ResolveTenant.php` |
| Automatic scoping; **throws** when no tenant is set | `app/Tenancy/Scopes/TenantScope.php`, `app/Tenancy/Concerns/BelongsToTenant.php` |
| Permission + branch checks | spatie `permission:` middleware, `ResolveBranch`, services |
| Validation of every client-sent foreign id | `App\Rules\TenantExists::on('table')` |
| **Database**: `UNIQUE(tenant_id,id)` + composite FKs `(tenant_id, x_id) → x(tenant_id, id)` | `SchemaMacros` (`tenantKey()`, `tenantForeign()`) |

Rules:
- Every tenant table: `$table->tenantKey()` right after `$table->id()`; reference tenant tables with `$table->tenantForeign()`.
- Every tenant model: `use BelongsToTenant`. Exceptions are documented (`Role`, `AuditLog` → `forCurrentTenant()`).
- Bypassing the scope requires an explicit `->withoutTenantScope()` (greppable, reviewed).
- Queued jobs that touch tenant data: `TenantAware` trait + `RestoreTenantContext` middleware.
- `tests/Feature/Tenancy/DatabaseIsolationTest.php` fails the build if a composite FK, `UNIQUE(tenant_id,id)`
  or `BelongsToTenant` is missing, or a FLOAT column appears.

Subdomains (`arnelmotoshop.avantpos.ph`) are a **hint only**: a request whose user belongs to another
tenant is rejected (`TENANT_MISMATCH`, with the correct `login_url`).

## 3. Authentication & access

| Actor | Guard / mechanism | Notes |
|---|---|---|
| Tenant user (browser) | `web` session via Sanctum SPA cookie | CSRF via `XSRF-TOKEN` |
| Tenant user (native app) | Sanctum personal token (`/auth/token`, 30 days) | |
| POS device | Sanctum token on `Terminal`, ability `terminal:sync` | one device per terminal; cannot call user endpoints |
| Platform admin | separate `platform_admins` table + `platform` guard | optional TOTP 2FA (`App\Support\Security\Totp`) |

- One e-mail = one tenant (globally unique `users.email`).
- RBAC: spatie/laravel-permission **teams mode, team = tenant** (`team_foreign_key = tenant_id`).
  Permission catalogue + default roles: `config/avantpos/permissions.php` (single source of truth).
- Owner role is locked. Escalation guard: users can only grant permissions they hold (`RoleAssignmentGuard`).
- Supervisor override: manager PIN → single-use 2-minute token (`SupervisorOverrideService`), both users audited.

## 4. Inventory (FIFO, branch-aware)

- `stock_movements` — append-only ledger (source of truth). Never updated/deleted.
- `inventory_balances` — cached qty/value per branch+variant, row-locked and updated in the same transaction.
- `inventory_layers` — FIFO cost layers (**FEFO** for expiry-tracked items, e.g. pharmacy).
  `stock_movement_layers` records which layers each outbound movement consumed → exact COGS.
- Stock increases from purchasing **only** through goods receipts.
- Every product has ≥1 variant; stock is in the base unit; `product_units` = selling/buying units
  (sari-sari *tingi*, hardware box/meter) with own barcode/price.

## 5. POS, offline & BIR-ready numbering

- Offline selling is a launch requirement: PWA + IndexedDB; sales carry a client UUID (idempotent sync),
  `origin`, device `sold_at`, `sync_status/sync_issues`. Offline sales are accepted (cash was collected);
  price/stock discrepancies are **flagged for review**, not rejected.
- Invoice series are **per terminal**, gapless and non-resettable (`DocumentNumberService`, row-locked, rolled back
  with the transaction; `advanceTo()` after offline sync).
- X/Z readings with non-resettable accumulated grand total (`register_readings`).
- Completed financial records are never deleted: voids/refunds are status changes + reversal documents.
- AvantPOS ships **BIR-ready data structures and reports**; it does not claim BIR accreditation.
  Formal CAS/POS accreditation and Permit-to-Use per machine are tenant/vendor processes (`docs/DEPLOYMENT.md` §15).

## 5a. Integrity guarantees (Phase 7)

Checked by `backend/tests/Feature/Integrity/` on every run, and in production where noted:

- **Tenant isolation**: every route taking a record id refuses another business's record; lists and reports
  never show it; ids in request bodies are rejected (`TenantExists`, batched: one query per list field).
- **Permissions**: every tenant route checks a permission except a reviewed list; every permission name used
  in routes, backend and frontend exists; a cashier is refused on every route needing more.
- **Money**: a full POS day reconciles sale lines, payments, shift drawer, X/Z readings, reports and the
  gapless invoice series to the centavo.
- **Stock**: `InventoryVerifier` / `php artisan inventory:verify` (nightly in production) checks ledger sums,
  running balances, FIFO layers and stock value.
- **Performance**: list pages and cart pricing run a fixed number of queries whatever the data size.
- **Security**: security headers, login and PIN throttling, no secrets in responses, mass assignment, upload
  types, CSV formula injection, separate admin guard. Deployment: `docs/DEPLOYMENT.md`.

## 6. Billing

Plans/limits/features: `subscription_plans` + `plan_features` (seed values in `config/avantpos/plans.php`,
managed by the SaaS admin afterwards). Enforcement: `App\Services\Billing\PlanGate`.
Xendit / PayMongo are used **only** for AvantPOS → tenant subscription billing.

- `BillingService`: checkout (upgrade with credit for unused time; downgrades and cancellations at period end),
  payments (`markPaid`, idempotent), daily lifecycle `php artisan billing:run` (scheduled 01:00 Manila):
  renewal invoice + reminder → past due for the plan's grace days → **expired = read-only**
  (`EnsureSubscriptionWritable`; billing, auth and support stay usable). **No free plan**: new businesses get a
  trial of Professional (`avantpos.app.trial`); a cancelled subscription ends read-only (data kept) at period end.
- Payment providers behind `Billing\Gateways\PaymentGateway` (Xendit invoices, PayMongo checkout sessions,
  manual bank transfer verified by an admin). Keys only in env (`BILLING_GATEWAY`, `XENDIT_*`, `PAYMONGO_*`).
  Webhooks: `POST /api/v1/billing/webhooks/{gateway}`, verified (token / HMAC) and stored once per event (`webhook_events`).
- Platform admin: dashboard (MRR = monthly value of paying subscriptions, ARR = MRR × 12), tenants (plan, due date,
  overdue / due-soon filters, suspend, adjust subscription, **Enable access** = active and paid until a date, with a
  reason), plans, payments, activity log, settings, support tickets, **admin team** (super admins add AvantPOS staff,
  set roles super_admin / billing / support, reset passwords and two-factor; never themselves, always one super admin
  left; deactivated admins are signed out on their next request); TOTP two-factor sign-in.
  Recovery from the server: `php artisan avantpos:admin <email> [--role=] [--reset-2fa]`.

## 7. API conventions

- Success `{ data, meta? }`, errors `{ message, code, errors?, context? }` (see `bootstrap/app.php`).
- Explicit action endpoints for state changes (`POST /sales/{id}/void`), never generic PATCH of status.
- `X-Branch-Id` for branch context; `Idempotency-Key` / client UUIDs on money-moving endpoints.

## 8. Frontend (React SPA)

Details and folder layout: `frontend/README.md`. Rules code must follow:

- React only formats money (`formatMoney` on decimal strings); it never computes totals, taxes or discounts.
- One HTTP client (`frontend/src/lib/api/client.ts`): Sanctum cookies + lazy CSRF cookie, one retry on 419,
  `X-Branch-Id` on tenant calls, every failure normalised to `ApiError { status, code, errors, context }`.
  401 / tenant-blocking 403 codes are broadcast as session events (separately for tenant and admin scope).
- Server state lives in TanStack Query. Everything except `['session']` is dropped on sign-in, sign-out and
  branch switch, so cached data never crosses users or branches.
- UI permission/feature checks (`can()`, `<Can>`, `navigation.ts`) are cosmetic; the API is the authority.
- `POST /api/v1/auth/logout` deliberately runs **without** tenant resolution, so a user of a suspended business
  or on the wrong subdomain can always end the session (Phase 3 change).
