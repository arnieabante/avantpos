# AvantPOS — Production Deployment

How to put AvantPOS on a server, keep it running, upgrade it and recover it.
Commands assume **Ubuntu 24.04 LTS**; adapt package names for other systems.

## 1. Shape

| Host | Serves | From |
|---|---|---|
| `api.avantpos.ph` | Laravel API (`/api/v1`, `/api/admin/v1`, `/up`) | `backend/public` via PHP-FPM |
| `app.avantpos.ph` | React app (sign-in, every business, `/admin`) | `frontend/dist` (static files) |
| `*.avantpos.ph` | Same React app on business subdomains (`trdmotorparts.avantpos.ph`) | `frontend/dist` |

Plus on the API server: **MySQL 8**, a **queue worker** (e-mails), the **scheduler** (billing cycle, nightly
stock check). One server is enough to start; the database can move to its own server later.

## 2. Server requirements

| | Version / setting |
|---|---|
| PHP | 8.3+ with `bcmath intl mbstring pdo_mysql gd zip curl xml opcache` (`redis` optional) |
| MySQL | 8.0 or 8.4, InnoDB, `utf8mb4` |
| Web server | Nginx + PHP-FPM |
| Build tools | Composer 2, Node 22 LTS (only to build the frontend) |
| Process manager | Supervisor (queue worker) |
| TLS | Let's Encrypt **wildcard** certificate for `avantpos.ph` + `*.avantpos.ph` (DNS challenge) |

```bash
sudo apt install nginx mysql-server supervisor unzip git \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-bcmath php8.3-intl php8.3-mbstring php8.3-gd php8.3-zip php8.3-curl php8.3-xml
```

DNS: `A` records for `api`, `app` and `*` → the server.

## 3. Database

```sql
CREATE DATABASE avantpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'avantpos'@'localhost' IDENTIFIED BY '<long random password>';
GRANT ALL PRIVILEGES ON avantpos.* TO 'avantpos'@'localhost';
```

MySQL listens on `127.0.0.1` only. Set `innodb_buffer_pool_size` to about 50–70 % of the RAM of a
dedicated database server (25 % when it shares the server with PHP).

## 4. Backend (API)

```bash
sudo mkdir -p /var/www/avantpos && sudo chown deploy:www-data /var/www/avantpos
git clone <repository> /var/www/avantpos && cd /var/www/avantpos/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env    # then edit: see below
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force          # permissions, plans, PH addresses, first platform admin, launch businesses
php artisan storage:link             # public logos / product photos on the local disk
php artisan optimize                 # caches config, routes, events, views
sudo chown -R deploy:www-data storage bootstrap/cache && sudo chmod -R ug+rwX storage bootstrap/cache
```

**Launch businesses.** `db:seed` also creates the businesses listed in
`backend/database/seeders/data/businesses.php` (users, plan trial, branch, POS counter, categories, brands,
suppliers; no products). Edit the users' e-mail addresses to real ones first: production refuses the
`*.test` placeholders. With `BUSINESS_SEED_PASSWORD` empty, each user gets a random password printed once.
Running `db:seed` again skips businesses that already exist.

### Production `.env`

Everything not listed keeps its `.env.example` value.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.avantpos.ph
LOG_STACK=daily
LOG_LEVEL=warning

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=avantpos
DB_USERNAME=avantpos
DB_PASSWORD=<database password>

SESSION_DRIVER=database
SESSION_DOMAIN=.avantpos.ph
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database
CACHE_STORE=database          # or redis (install php8.3-redis + redis-server)

FRONTEND_URL=https://app.avantpos.ph
AVANTPOS_BASE_DOMAIN=avantpos.ph
AVANTPOS_APP_HOST=app.avantpos.ph
AVANTPOS_MEDIA_DISK=public
SANCTUM_STATEFUL_DOMAINS=app.avantpos.ph,*.avantpos.ph
CORS_ALLOWED_ORIGINS=https://app.avantpos.ph
CORS_ALLOWED_ORIGIN_PATTERNS=#^https://[a-z0-9-]+\.avantpos\.ph$#

MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS="no-reply@avantpos.ph"

PLATFORM_ADMIN_EMAIL=<your e-mail>
PLATFORM_ADMIN_PASSWORD=<long random password, change after first sign-in>

BILLING_GATEWAY=xendit         # or paymongo, or manual (bank transfer only)
XENDIT_SECRET_KEY=<from Xendit dashboard>
XENDIT_CALLBACK_TOKEN=<from Xendit dashboard>
# PAYMONGO_SECRET_KEY / PAYMONGO_WEBHOOK_SECRET when using PayMongo
```

Production behaviour built into the app: HTTPS-only URLs, stricter password rules (mixed case, numbers,
not in known breaches), `migrate:fresh` / `db:wipe` refused, demo seeders refused, no error details in
responses, security headers on every API response (HSTS on HTTPS).

**Files.** Logos and product photos go to `storage/app/public` (served through `public/storage`);
expense receipts go to `storage/app/private` (never public, downloaded through the API with a permission
check). Both must be in the backups. To use S3-compatible storage instead, install
`league/flysystem-aws-s3-v3`, fill the `AWS_*` values and set `AVANTPOS_MEDIA_DISK=s3` /
`AVANTPOS_DOCUMENTS_DISK=s3` (use a private bucket for documents).

## 5. Frontend (React app)

Build anywhere with Node 22, then copy `dist/` to the server:

```bash
cd frontend
cat > .env.production <<'EOF'
VITE_API_URL=https://api.avantpos.ph
VITE_BASE_DOMAIN=avantpos.ph
EOF
npm ci
npm run build            # type check + production build to dist/
rsync -a --delete dist/ deploy@server:/var/www/avantpos-web/
```

## 6. Nginx

```nginx
# API
server {
    listen 443 ssl http2;
    server_name api.avantpos.ph;
    root /var/www/avantpos/backend/public;
    ssl_certificate     /etc/letsencrypt/live/avantpos.ph/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/avantpos.ph/privkey.pem;

    client_max_body_size 10m;            # receipts and photos
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ /\.(?!well-known) { deny all; }
}

# React app: app.avantpos.ph and every business subdomain
server {
    listen 443 ssl http2;
    server_name app.avantpos.ph *.avantpos.ph;
    root /var/www/avantpos-web;
    ssl_certificate     /etc/letsencrypt/live/avantpos.ph/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/avantpos.ph/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://api.avantpos.ph; font-src 'self'; connect-src 'self' https://api.avantpos.ph; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;

    location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }
    location = /sw.js { add_header Cache-Control "no-cache"; try_files $uri =404; }
    location / { add_header Cache-Control "no-cache"; try_files $uri /index.html; }
}

server { listen 80; server_name avantpos.ph *.avantpos.ph; return 301 https://$host$request_uri; }
```

The service worker (offline POS) only works over HTTPS, and only on the host the app was opened on.

## 7. Background processes

**Queue worker** (verification, password, invitation and billing e-mails) —
`/etc/supervisor/conf.d/avantpos-worker.conf`:

```ini
[program:avantpos-worker]
command=php /var/www/avantpos/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=deploy
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/avantpos/backend/storage/logs/worker.log
```

**Scheduler** — `crontab -e` for `deploy`:

```cron
* * * * * cd /var/www/avantpos/backend && php artisan schedule:run >> /dev/null 2>&1
```

| Scheduled (Asia/Manila) | What |
|---|---|
| 01:00 `billing:run` | renewal invoices and reminders, trials ending, past due → read-only, scheduled plan changes |
| 02:30 `inventory:verify` | read-only stock ledger check; writes an `error` log entry if anything disagrees |

## 8. Payment gateway webhooks

| Gateway | Webhook URL to register | Secret |
|---|---|---|
| Xendit (Invoices) | `https://api.avantpos.ph/api/v1/billing/webhooks/xendit` | `XENDIT_CALLBACK_TOKEN` |
| PayMongo (Checkout) | `https://api.avantpos.ph/api/v1/billing/webhooks/paymongo` | `PAYMONGO_WEBHOOK_SECRET` |

Make one small real payment after go-live. Bank details for manual transfers are set in
**Admin → Settings**.

## 9. First sign-in

1. Open `https://app.avantpos.ph/admin/login` with `PLATFORM_ADMIN_EMAIL` / `PLATFORM_ADMIN_PASSWORD`.
2. Change the password and **turn on two-factor sign-in** (Admin → Settings).
3. Review plans and prices (Admin → Plans), bank details and support contacts (Admin → Settings).
4. Remove `PLATFORM_ADMIN_PASSWORD` from `.env`, then `php artisan config:cache`.
5. Add your staff under **Admin → Admin team** with the least role they need (billing, support).

Locked out (forgotten password or lost authenticator)? On the server:
`php artisan avantpos:admin you@example.com --reset-2fa` prints a new password and turns off two-factor
for that account (it also creates the account as a super admin if it does not exist).

## 10. Backups and restore

Nightly, kept **off the server** for at least 30 days (keep monthly copies longer):

```bash
#!/bin/sh  # /usr/local/bin/avantpos-backup
set -e
STAMP=$(date +%F)
mysqldump --single-transaction --routines --no-tablespaces -u avantpos -p"$DB_PASSWORD" avantpos | gzip > /backups/avantpos-$STAMP.sql.gz
tar -czf /backups/avantpos-files-$STAMP.tgz -C /var/www/avantpos/backend storage/app
# then copy /backups to off-site storage (rclone / s3 sync) and delete local copies older than 7 days
```

Restore drill (do it once before go-live, then every few months, on a spare server):

```bash
gunzip < avantpos-YYYY-MM-DD.sql.gz | mysql -u avantpos -p avantpos
tar -xzf avantpos-files-YYYY-MM-DD.tgz -C /var/www/avantpos/backend
php artisan optimize && php artisan inventory:verify
```

## 11. Upgrades

```bash
cd /var/www/avantpos
php backend/artisan down --retry=60          # optional for releases with long migrations
git pull
cd backend && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
# frontend: build and rsync dist/ (section 5)
```

Migrations only add tables, columns and indexes, or change data in a reversible way; financial records are
never deleted. Take a backup right before migrating. To roll back a bad release: redeploy the previous
tag; if a migration changed data, restore the pre-release backup.

## 12. Monitoring

| Check | How |
|---|---|
| API up | `GET https://api.avantpos.ph/up` → 200 (uptime monitor every minute) |
| Errors | `storage/logs/laravel-*.log` (`LOG_LEVEL=warning`); ship to your log service if you have one |
| Queue | `php artisan queue:failed` (should be empty); `supervisorctl status` |
| Stock ledger | nightly `inventory:verify`; alert on its `error` log line |
| Disk / DB size | the usual server monitoring |

## 13. Security checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, HTTPS everywhere, `SESSION_SECURE_COOKIE=true`
- [ ] Firewall: only 22, 80, 443 open; MySQL on `127.0.0.1`; SSH keys only
- [ ] `.env` readable by the deploy user and PHP only (`chmod 640`)
- [ ] Platform admins use two-factor sign-in; least-privilege admin roles (super admin / billing / support)
- [ ] Gateway keys only in `.env`; webhooks verified (token / HMAC)
- [ ] Backups off-site and a restore tested
- [ ] `composer audit` and `npm audit` before each release (both clean at Phase 7)
- [ ] OS security updates (`unattended-upgrades`)

## 14. Performance settings

- **OPcache**: `opcache.enable=1`, `opcache.memory_consumption=256`, `opcache.validate_timestamps=0`
  (reload PHP-FPM after each deploy), `realpath_cache_size=4096K`.
- **PHP-FPM**: `pm = dynamic`, `pm.max_children` ≈ (RAM for PHP) ÷ 60 MB.
- **Laravel**: `php artisan optimize` on every deploy (cached config/routes/events/views).
- **Redis** (optional, when traffic grows): `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- The app keeps list pages at a fixed number of queries whatever the data size (tested), and date-range
  reports use dedicated indexes.

## 15. BIR notes

AvantPOS ships **BIR-ready** data and reports (per-terminal gapless invoice series, X/Z readings with a
non-resettable grand total, VAT / VAT-exempt / zero-rated split, Senior/PWD sales book, reprint and void
logs, no deletion of financial records). It **does not hold a BIR accreditation**. Each business remains
responsible for:

1. registering its point-of-sale machines with its BIR Revenue District Office and obtaining a
   **Permit to Use (PTU)** per terminal (enter MIN, serial number and PTU under Settings → Terminals so
   they print on receipts);
2. any **CAS/POS accreditation** process that applies to its setup, with AvantPOS (the vendor) providing
   system documentation;
3. running a **Z reading** at the end of each business day and keeping the reports;
4. keeping records for the period the BIR requires (AvantPOS never deletes sales, refunds or readings).

## 16. Running the test suites (CI or before a release)

```bash
cd backend && php artisan test            # MySQL test database "avantpos_test" (phpunit.xml)
vendor/bin/pint --test                    # code style
php artisan inventory:verify              # against a copy of production data, optional
cd ../frontend && npm test && npm run typecheck && npm run lint && npm run build
```

Phase 7 integrity suites live in `backend/tests/Feature/Integrity/`: tenant isolation sweep over every
route, permission sweep, API contract, security, POS day reconciliation, inventory consistency,
query-count guards.
