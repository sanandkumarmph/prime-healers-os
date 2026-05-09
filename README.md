# Prime Healers OS

Prime Healers OS is a Laravel-based rental and sales operations platform for equipment workflows. It covers customer onboarding, product master, asset tracking, rentals, sales, deliveries, pickups, invoices, payments, CSV imports, renewals, returns, reporting, organization settings, role-based access, and an internal Knowledge Hub.

## Project Overview

Core modules:
- Customers
- Products
- Assets
- Rentals
- Sales
- Deliveries and pickups
- Invoices and invoice items
- Payments
- CSV import
- Renewals and returns
- Reports and dashboards
- Users, roles, and permissions
- Organization settings
- Knowledge Hub

Key operational commands:
- `php artisan rentnexis:audit-data`
- `php artisan rentnexis:repair-uat-data`
- `php artisan rentnexis:reset-uat-data`
- `php artisan rentnexis:check-pdf-runtime`

## Local Setup

Prerequisites:
- PHP 8.2+
- Composer
- Node.js 20+ and npm
- MySQL for normal local usage, or SQLite for test-only usage

Install steps:

```powershell
composer install
copy .env.example .env
php artisan key:generate
npm install
php artisan migrate
npm run build
php artisan serve
```

Recommended local bootstrap in one go:

```powershell
composer setup
```

Notes:
- `composer setup` runs migrations and builds frontend assets.
- For local invoice PDF printing, you also need the PDF runtime setup documented below.

## UAT Setup

Typical UAT host requirements:
- PHP 8.2+
- Composer dependencies installed
- Node.js installed
- `node_modules` present on the server or built into the deployed release
- MySQL configured
- writable `storage/` and `bootstrap/cache/`
- public build files copied to the web root if the app and public web root are split

Typical UAT refresh flow:

```bash
cd /path/to/prime-healers-os
git fetch origin
git reset --hard origin/uat
composer install --no-interaction --prefer-dist --optimize-autoloader
npm install
npm run build
php artisan optimize:clear
php artisan migrate --force
php artisan rentnexis:check-pdf-runtime
php artisan view:cache
php artisan config:cache
php artisan route:cache
php artisan up
```

If your public web root is outside the Laravel app directory, copy `public/build` to the web root after `npm run build`.

## Production Deployment Checklist

Before deployment:
1. Confirm `.env` is correct for production DB, mail, queue, cache, and app URL.
2. Confirm `APP_DEBUG=false`.
3. Confirm storage permissions are correct.
4. Confirm private customer proof files are not exposed publicly.
5. Confirm invoice PDF runtime is installed and verified.
6. Confirm migrations have been rehearsed on staging/UAT.
7. Confirm regression tests pass.

Deploy checklist:
1. Put the app in maintenance mode if needed.
2. Pull the target branch/tag.
3. Run `composer install --no-dev --prefer-dist --optimize-autoloader`.
4. Run `npm install` or use prebuilt assets.
5. Run `npm run build`.
6. Run `php artisan migrate --force`.
7. Run `php artisan optimize:clear`.
8. Run `php artisan rentnexis:check-pdf-runtime`.
9. Run `php artisan view:cache`.
10. Run `php artisan config:cache`.
11. Run `php artisan route:cache`.
12. Bring the app back up.

After deployment:
1. Open dashboard.
2. Open rentals, sales, invoices, and organization settings.
3. Print a sample invoice.
4. Run `php artisan rentnexis:audit-data`.

## Required .env Variables

Minimum app/runtime variables:

```env
APP_NAME="Prime Healers OS"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=prime_healers_os
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
FILESYSTEM_DISK=local
```

Important deployment variables:

```env
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug
```

Adjust these for production:
- `APP_ENV=production`
- `APP_DEBUG=false`
- `LOG_LEVEL=warning` or stricter
- `QUEUE_CONNECTION` to your actual queue backend if background work is introduced

## PDF Runtime Setup

Invoice PDF rendering uses Browsershot and requires a working Node.js + Puppeteer + Chrome/Chromium runtime on the server.

Runtime check command:

```powershell
php artisan rentnexis:check-pdf-runtime
```

This command checks:
- configured Node binary
- whether the Node command executes
- configured `node_modules` path
- Puppeteer and Puppeteer Core package directories
- Browsershot PHP package availability
- configured browser path or common fallback browser paths
- `PDF_DISABLE_SANDBOX` config resolution

It does **not** generate a real customer invoice.

Required PDF environment variables:

```env
PDF_NODE_BINARY=node
PDF_NODE_MODULE_PATH=/absolute/path/to/node_modules
PDF_BROWSER_PATH=
PDF_DISABLE_SANDBOX=true
```

Windows local example:

```env
PDF_NODE_BINARY=node
PDF_NODE_MODULE_PATH=C:\Users\sanan\prime-healers-os\node_modules
PDF_BROWSER_PATH=C:\Program Files\Google\Chrome\Application\chrome.exe
PDF_DISABLE_SANDBOX=false
```

Alternative Windows browser paths:
- `C:\Program Files (x86)\Google\Chrome\Application\chrome.exe`
- `C:\Program Files\Microsoft\Edge\Application\msedge.exe`
- `C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe`

Linux / UAT example:

```env
PDF_NODE_BINARY=node
PDF_NODE_MODULE_PATH=/var/www/prime-healers-os/node_modules
PDF_BROWSER_PATH=/usr/bin/chromium-browser
PDF_DISABLE_SANDBOX=true
```

Common Linux browser paths checked automatically when `PDF_BROWSER_PATH` is blank:
- `/usr/bin/google-chrome`
- `/usr/bin/google-chrome-stable`
- `/usr/bin/chromium`
- `/usr/bin/chromium-browser`
- `/snap/bin/chromium`

Chrome / Chromium installation notes:

```bash
sudo apt-get update
sudo apt-get install -y chromium-browser
```

If `chromium-browser` is unavailable on the target OS image, install Google Chrome or the distro-specific Chromium package and set `PDF_BROWSER_PATH` explicitly.

Node modules must include:
- `puppeteer`
- `puppeteer-core`

## Storage and Private Proof File Setup

Customer ID proof files are intended to live on the private `local` disk:
- upload path: `storage/app/private/customer-id-proofs` or equivalent local-disk path
- access path: only through the application route with permission checks

Recommended steps:
1. Keep `FILESYSTEM_DISK=local` for sensitive uploads.
2. Do not serve customer proof files directly from public web storage.
3. Run `php artisan storage:link` only for public assets like logos and build outputs.

Current compatibility note:
- legacy customer proof files may still be served from a temporary public-disk fallback path
- the app logs a warning when this happens
- migrate those files to the private local disk as part of cleanup

## Import Process

Import workflow:
1. Download the correct template from `Data Import`
2. Fill and upload the CSV
3. Review mapping if applicable
4. Review the preview carefully
5. Execute only after preview validation passes

Import rules:
- repeated imports should update existing rentals/sales instead of creating duplicates when the natural key matches
- invoice creation defaults to manual-flow parity unless `invoice_status=not_generated`
- invalid rows are surfaced during preview

Operational guidance:
- only superadmin/data-import-authorized users should access import routes
- always keep a backup before large UAT or production imports
- review preview warnings, review rows, and skipped rows before execute

## Audit, Repair, and Reset Commands

Audit current data:

```powershell
php artisan rentnexis:audit-data
```

Repair UAT-safe issues in dry-run mode:

```powershell
php artisan rentnexis:repair-uat-data
```

Apply UAT-safe repairs:

```powershell
php artisan rentnexis:repair-uat-data --apply
```

Preview UAT reset without deleting:

```powershell
php artisan rentnexis:reset-uat-data
```

Apply UAT reset:

```powershell
php artisan rentnexis:reset-uat-data --apply
```

Important:
- `reset-uat-data` is meant for transactional/UAT data only
- do not run `--apply` casually
- always review dry-run output first

## Testing Commands

Regression suite:

```powershell
vendor\bin\phpunit --do-not-cache-result tests\Feature\Regression
```

Feature tests:

```powershell
vendor\bin\pest tests\Feature
```

Unit tests:

```powershell
vendor\bin\pest tests\Unit
```

Standard Laravel test command:

```powershell
php artisan test
```

Note:
- the project is configured to use SQLite in-memory for tests via [phpunit.xml](/C:/Users/sanan/prime-healers-os/phpunit.xml)
- if `php artisan test` behaves unexpectedly after caching, run the cache clear commands below first

## Cache Commands

Clear caches:

```powershell
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Rebuild caches:

```powershell
php artisan view:cache
php artisan config:cache
php artisan route:cache
```

Recommended after deploy:

```powershell
php artisan optimize:clear
php artisan view:cache
php artisan config:cache
php artisan route:cache
```

## Troubleshooting

### Company page or settings page returns 404
- check the logged-in user’s `organization_id`
- if there is only one organization, the self-heal logic should relink the user automatically
- if multiple organizations exist and the user points to a missing org, fix the user’s org mapping explicitly

### Invoice PDF fails
1. Run `php artisan rentnexis:check-pdf-runtime`
2. Confirm `PDF_NODE_BINARY`
3. Confirm `PDF_NODE_MODULE_PATH`
4. Confirm Chrome/Chromium exists
5. Confirm `PDF_BROWSER_PATH` if set
6. Check Laravel logs

### `php artisan test` behaves differently after caching
- clear config and route cache first:

```powershell
php artisan config:clear
php artisan route:clear
```

### Pending badges or layout counts look stale
- counts are now cached briefly for layout performance
- wait up to 120 seconds or clear cache during debugging

### Import access unexpectedly blocked
- verify the user is superadmin or otherwise allowed by import access middleware and controller checks

## Rollback Checklist

If a UAT or production deploy must be rolled back:
1. Put the app in maintenance mode if required.
2. Reset the codebase to the last known good tag or commit.
3. Reinstall dependencies if needed.
4. Restore the previous built assets.
5. Run:

```bash
php artisan optimize:clear
php artisan view:cache
php artisan config:cache
php artisan route:cache
```

6. Verify:
   - login
   - dashboard
   - rentals
   - invoices
   - organization settings
   - invoice PDF
7. If a migration caused the issue, restore from backup or run the rehearsed rollback plan.

## CI Notes

This repository includes GitHub Actions CI that:
- installs PHP and Composer dependencies
- installs Node dependencies
- builds frontend assets
- prepares a non-secret `.env`
- clears caches
- runs regression, feature, and unit tests

CI is intentionally test-only:
- it does not deploy
- it does not use production secrets
- it relies on SQLite in-memory testing
