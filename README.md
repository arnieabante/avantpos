# AvantPOS

Multi-tenant, offline-capable Point of Sale SaaS for Philippine small and medium businesses
(sari-sari stores, hardware, motor shops, apparel, pharmacies).

| Folder | What | Stack |
|---|---|---|
| `backend/` | REST API (`/api/v1`, `/api/admin/v1`) | PHP 8.3+, Laravel 13, MySQL 8.4, Sanctum, spatie/laravel-permission, brick/math, Pest |
| `frontend/` | React SPA (PWA/offline in Phase 5) | React 19, TypeScript, Vite 8, React Router 8, TanStack Query, Axios, Tailwind 4, Vitest |
| `docs/` | Architecture and decisions | |

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design (tenancy, RBAC, inventory, offline, BIR)
and [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for production setup, backups, upgrades and monitoring.

---

## Local development (Windows)

### Prerequisites
- PHP 8.3+ with `intl`, `sodium`, `bcmath`, `pdo_mysql`, `mbstring`, `gd`, `zip` enabled (XAMPP `php.ini`)
- Composer 2
- **MySQL 8.4** (production parity). The local dev server lives in `D:\mysql84` on port **3307**:
  - start: `D:\mysql84\start-mysql84.bat`  ·  stop: `D:\mysql84\stop-mysql84.bat`
  - credentials: `D:\mysql84\local-credentials.txt` (local only, never commit)
  - databases: `avantpos` (dev) and `avantpos_test` (tests)

> XAMPP's bundled MariaDB 10.4 is **not** used: production runs MySQL 8 and the stock-locking,
> composite-key and generated-column behaviour must match.

### Backend setup
```bash
cd backend
composer install
cp .env.example .env        # then set DB_PASSWORD (see local-credentials.txt)
php artisan key:generate
php artisan migrate --seed  # schema + permissions, plans, PSGC addresses, platform admin, demo tenants
php artisan storage:link    # once: serves uploaded logos/product images from public/storage
php artisan serve           # http://localhost:8000
php artisan queue:work      # e-mails (verification, invitations, password reset)
```

Seeding prints the generated **platform admin** password once. Demo businesses (local only):

| Business | Owner | Manager / Cashier | Password | PIN |
|---|---|---|---|---|
| Arnel Motoshop (subdomain `arnelmotoshop`, VAT) | owner@motoshop.test | manager@ / cashier@motoshop.test | `Password123` | 2468 |
| Tindahan ni Aling Nena (non-VAT) | owner@sarisari.test | manager@ / cashier@sarisari.test | `Password123` | 2468 |

E-mails are written to `storage/logs/laravel.log` locally (`MAIL_MAILER=log`).

### Tests
```bash
cd backend
composer test               # Pest, against MySQL avantpos_test
composer lint               # Laravel Pint
```
The test database is migrated once and every test runs in a rolled-back transaction.
After **editing an existing migration**, rebuild it:
`php artisan migrate:fresh --env=testing --database=mysql` (with `DB_DATABASE=avantpos_test`).

### Frontend setup
```bash
cd frontend
npm install                 # Node 22.22+
cp .env.example .env.local  # VITE_API_URL=http://localhost:8000
npm run dev                 # http://localhost:5173
npm test                    # Vitest
npm run build               # typecheck + production build
```
Open **http://localhost:5173** (not 127.0.0.1) and sign in with a demo account above.
Platform admin: http://localhost:5173/admin/login. Details: [frontend/README.md](frontend/README.md).

---

## API conventions (summary)

- Success: `{ "data": ..., "meta"?: { "pagination": {...} } }`
- Error: `{ "message": "...", "code": "MACHINE_CODE", "errors"?: {...}, "context"?: {...} }`
- SPA auth: `GET /sanctum/csrf-cookie` → `POST /api/v1/auth/login` (cookie session, send `X-XSRF-TOKEN`)
- Native/mobile auth: `POST /api/v1/auth/token` → `Authorization: Bearer ...`
- POS devices: registered via `POST /api/v1/terminals/{id}/device` → device token (terminal endpoints only)
- Branch selection: `X-Branch-Id` header (validated against the user's branch access)
