# Hostinger Git deployment

## Deployment layout

The repository root is the Laravel application. Clone it to:

```text
/home/u974495265/domains/franconia.in/public_html/api
```

Keep the API subdomain's document root at:

```text
/home/u974495265/domains/franconia.in/public_html/api/public
```

The root `.htaccess` blocks HTTP access to everything except `public/`, protecting Laravel files from `https://franconia.in/api/...`. Keep `public/.htaccess`, which selects `index.php` and routes Laravel requests. Do not replace it with the storefront's static `.htaccess`.

## Initial setup over SSH

Connect with:

```bash
ssh -p 65002 u974495265@147.93.17.74
```

Select PHP 8.3+ for the API website in hPanel. Verify the CLI with `php -v`. If the default CLI is older and Hostinger provides it, use `/opt/alt/php83/usr/bin/php` and prepend `/opt/alt/php83/usr/bin` to PATH.

### Clone the backend

If `api/` already contains files, back up that entire directory outside `public_html` before cloning. Do not delete an existing backend's `.env`, media or database. For a new empty API setup:

```bash
cd /home/u974495265/domains/franconia.in/public_html
# The api destination must be absent or empty for git clone.
git clone https://github.com/mnkkal/franconia.git api
cd api
mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,testing,views} storage/logs storage/app/{private,public}
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
```

For a previously deployed backend, preserve/restore its `.env` and storage from your backup, then install dependencies in the checkout. Never use `git clean` against Laravel runtime directories.

### Configure the private environment

For a **new** installation only:

```bash
cp .env.production.example .env
chmod 600 .env
```

Edit `.env` privately on the server with the real MySQL, Razorpay and SMTP credentials. Domain and supplied business information are already filled in. Use:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.franconia.in
FRONTEND_URL=https://franconia.in,https://www.franconia.in
STORE_BACKUP_DIR=/home/u974495265/franconia-private/backups
STORE_MYSQLDUMP_BINARY=mysqldump
```

Generate `APP_KEY` only if this is a new environment: `php artisan key:generate --force`. Preserve the key from an existing installation. Keep `.env`, dumps and private keys out of Git.

Make `storage/` and `bootstrap/cache/` writable by PHP using Hostinger's appropriate owner/group permissions. Then:

```bash
php artisan optimize:clear
php artisan franconia:backup-database --directory=/home/u974495265/franconia-private/backups
php artisan migrate --force
php artisan db:seed --class=FranconiaBaseSeeder --force
```

For an **empty database only**, run `php artisan db:seed --force` and `php artisan lunar:create-admin`, then verify all real stock/content. For an existing catalogue, use `php artisan franconia:update-prices` instead of reseeding.

```bash
php artisan storage:link
php artisan filament:assets
php artisan config:cache
php artisan view:cache
```

Add this cron in hPanel, replacing the PHP binary if necessary:

```cron
* * * * * /opt/alt/php83/usr/bin/php /home/u974495265/domains/franconia.in/public_html/api/artisan schedule:run >> /dev/null 2>&1
```

Configure the Razorpay `payment.captured` webhook to `https://api.franconia.in/api/payment/webhook`, with a matching random signing secret in `.env`. Complete test-mode checkout/email/refund acceptance before using matching live credentials. Upload real product images and verify inventory. Run `php artisan franconia:check-deploy` after cron has run.

## Automatic deployment from GitHub

The workflow `.github/workflows/backend.yml` tests every push/PR. It only deploys `main`, after tests pass, when repository variable **HOSTINGER_DEPLOY_ENABLED** is `true`.

Repository secrets:

| Secret | Purpose |
|---|---|
| `HOSTINGER_SSH_KEY` | Private half of the dedicated deployment key |
| `HOSTINGER_KNOWN_HOSTS` | Trusted SSH host-key entry for `[147.93.17.74]:65002` |

A dedicated key has been generated. After cloning, authorize its public half from your own SSH session:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
cat /home/u974495265/domains/franconia.in/public_html/api/scripts/hostinger-deploy-key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

The same public key can also be added through Hostinger's SSH key manager if available. The private half stays in GitHub Secrets, not in the repository. Confirm the server host-key fingerprint through your trusted hosting connection before replacing a host-key secret.

After the public key is authorized, the checkout exists at the application path, and the private production environment/database are configured:

1. In GitHub → repository Settings → Secrets and variables → Actions → Variables, set `HOSTINGER_DEPLOY_ENABLED=true`.
2. Open Actions → Backend checks and Hostinger deploy → Run workflow on `main`, or push a subsequent change to `main`.
3. The deploy step connects to the fixed SSH account/path, obtains a deployment lock, fast-forwards to the exact tested commit, installs locked dependencies, backs up MySQL, runs migrations, synchronizes prices and rebuilds Laravel assets/caches.
4. It preserves untracked `.env`, media and runtime storage. It repairs base store settings with the idempotent base seeder; it never reseeds the catalogue or runs `migrate:fresh`.
5. It checks `/up` and ensures the main domain cannot serve `api/.env`. Review the Actions log before considering a release live.

If deployment fails after maintenance mode is enabled, inspect the failure, restore the prior release/database if needed and run `php artisan up` only once the application is healthy. Backups are retained outside `public_html`; configure retention and off-host/media backups separately.

Manual update after an already-configured checkout:

```bash
cd /home/u974495265/domains/franconia.in/public_html/api
git fetch origin main
bash scripts/deploy-hostinger.sh "$(git rev-parse origin/main)"
```

## Hostinger's own Git integration

You can alternatively connect the repository in hPanel → Advanced → Git. Choose `main` and the application directory ending **`public_html/api`**, not `api/public`. Laravel's web document root remains `api/public`.

Use one deployment mechanism. If GitHub Actions handles deployment, disable hPanel auto-deployment so it cannot change files while the tested deployment script is working. A Git checkout alone does not replace the Composer/migration/cache steps. Follow the initial setup above before enabling automatic updates.

Official guide: https://www.hostinger.com/support/1583302-how-to-deploy-a-git-repository-in-hostinger/


## Admin URLs and missing currency

The admin panel has no `/lunar` URL prefix. Login is `https://api.franconia.in/login`, dashboard is `/`, and products are `/products`. The storefront API remains under `/api/`.

If the dashboard/product page reports `Attempt to read property "code" on null`, initialize or repair the default currency with `php artisan db:seed --class=FranconiaBaseSeeder --force`, then run `php artisan optimize:clear`. This sets INR as the enabled default without resetting product stock or prices. Do not run the full catalogue seeder to fix this error.
