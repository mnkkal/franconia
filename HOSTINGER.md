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
php artisan franconia:sync-catalogue
```

The catalogue sync adds the five website products and their 20 ml/50 ml variants without overwriting existing products, prices or stock. New variants start at zero stock; enter actual quantities in admin. For a new installation create staff with `php artisan lunar:create-admin`. Do not run the full catalogue seeder against an existing shop.

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
4. It preserves untracked `.env`, media and runtime storage. It repairs base settings and adds missing storefront products through `franconia:sync-catalogue`; it never resets existing catalogue stock/content or runs `migrate:fresh`.
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


## Franconia branding and website catalogue

The admin logo, dark-mode logo and favicon use the supplied Franconia artwork in `public/branding/`. Run `php artisan franconia:sync-catalogue` after pulling updates to add NOBLE, BLAZE, PETALS, PEARLS and ELIXIR from `config/catalogue.json`, each with 20 ml/50 ml variants and BYOB single-bottle prices. Existing edits, prices, archived records and stock are preserved; new stock starts at zero. Enter actual stock and upload product photos in admin. ELIXIR remains coming soon/out of stock.

## Uploaded images return 404

The storefront reads live media URLs from `/api/products`; no frontend rebuild is needed for image uploads. Uploaded images live in `storage/app/public`, normally exposed by `public/storage`. Ensure `APP_URL=https://api.franconia.in` and `MEDIA_DISK=public` in the private production `.env`.

After pulling this fix:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan storage:link
php artisan media-library:regenerate --only-missing --force
php artisan config:cache
```

Do not delete `storage/app/public` or an existing real `public/storage` directory. If the link already exists, continue with the remaining commands. The application now has a `/storage/{id}/{file}` fallback for hosts without a working storage symlink. It only serves registered public raster images and uses the original if a thumbnail is not generated yet. Missing original files still return 404: check that the upload exists under `storage/app/public/<media-id>/` and restore it from backup or re-upload in that case.

New thumbnails run during upload by default, without a permanent queue worker. If your `.env` explicitly sets `QUEUE_CONVERSIONS_BY_DEFAULT=true`, change it to `false` for this shared-hosting setup, then clear/rebuild config cache. Regeneration requires the GD or Imagick PHP image extension; inspect command errors if thumbnails still fail.

Images are attached to the product being edited. The media display name does not move an image to another product. Upload under the intended product's Media page.

## Replacing an existing product image

Open Products → product → Media → Edit. The optional **Replace image** field accepts JPG, PNG, WebP or GIF up to 10 MB and includes a crop editor. Leave it empty for a name/primary-only edit. Save changes to store the replacement, regenerate thumbnails and preserve the product, primary flag, gallery order and variant links. The new file receives a fresh media URL to avoid stale browser images. The storefront lists the primary image first, then gallery order. This uses an application extension, so no vendor files need editing.

## Storefront sizes missing from the admin Variants page

Legacy imports created 20 ml/50 ml variant records but did not attach Lunar product options and option values. Lunar's Variants widget builds its list from those option links. After updating code, run `php artisan franconia:sync-catalogue` to add the missing Size mappings across all five fragrances. The command reuses existing variant IDs, SKUs, prices and stock, preserves archived variants and extra option links, and can be rerun. Conflicting existing Size selections are reported for review without overwriting them.

Refresh Products → product → Variants: Size should contain 50ML and 20ML with the same existing SKUs. Enter actual stock in the variant rows and save variants. The storefront API reads these same records and uses the Size option labels, with legacy attributes as a fallback. Refresh the storefront to see stock changes. No frontend rebuild or full catalogue reseeding is required.

## Launch offer and variant photos

After pulling this release, clear configuration cache, run `php artisan franconia:launch`, then rebuild configuration cache. This adds all five products to New Launches without changing stock. Launch shipping is zero for all orders. Upload the matching frontend export as well so displayed totals and shipping copy match checkout.

Under Products → product → Variants, open the 20ML variant and its Media page, then choose **Upload variant photo**. Upload as many photos as needed and choose the primary image there. These photos belong to that variant and are excluded from the default 50ML product gallery. Existing product photos remain the 50ML fallback. The storefront receives variant image arrays and changes its gallery when the size changes.

## Website order and email acceptance

Website checkout writes `ShopOrder` records, visible under **Store operations → Storefront orders** (`/store-orders`), separate from Lunar's native Orders resource. Pending checkout orders appear before payment. Only captured, matching payments become paid. Successful capture and fulfilment updates enter the durable customer email outbox. `franconia:maintain` delivers the outbox and retries failures; the minute scheduler invokes it.

Verify production with `php artisan franconia:check-deploy`. Configure SMTP in the private `.env`, the existing every-minute cron, and the Razorpay captured-payment webhook at `https://api.franconia.in/api/payment/webhook`. After configuration, complete a Razorpay test-mode order using an address you control and verify the record, payment status, receipt and inbox delivery. Mocked integration tests cover this path, but they do not establish production SMTP deliverability. Do not paste payment or SMTP secrets into chat.
