# Franconia backend

Laravel 12 + Lunar 1.5 / Filament 4 API and admin for https://franconia.in.

This repository contains only the backend. The storefront is deployed separately.

| Setting | Value |
|---|---|
| API | https://api.franconia.in |
| SSH | `ssh -p 65002 u974495265@147.93.17.74` |
| Application directory | `/home/u974495265/domains/franconia.in/public_html/api` |
| API document root | `/home/u974495265/domains/franconia.in/public_html/api/public` |
| Admin | https://api.franconia.in |

See [HOSTINGER.md](HOSTINGER.md) for initial setup and automatic deployment.

## Requirements

PHP 8.3+ (64-bit), Composer 2, MySQL/MariaDB with InnoDB, cron, and the extensions required by `composer check-platform-reqs`. Test with `php artisan test --compact`; PHPUnit forces an isolated SQLite memory database and fake payment credentials. Payment tests fake network/email.

## Store operations

- `php artisan franconia:update-prices`: update the ten known single-bottle prices without reseeding or resetting inventory.
- `php artisan franconia:maintain`: reconcile captured payments, release expired reservations, retry refunds and send order emails. Cron runs it via `schedule:run` every minute.
- `php artisan franconia:check-deploy`: check production configuration, database, images, administrator and cron heartbeat.
- `php artisan franconia:backup-database --directory=/home/u974495265/franconia-private/backups`: create a private MySQL dump before migrations. The MySQL dump executable must be available.

The root-level administrator screens include Storefront orders, messages, review moderation and newsletter subscriptions. Prices in `config/byob.json` are authoritative for BYOB SKUs. Public business details are in `config/business.json` with optional environment overrides. Private credentials belong only in the server `.env`.

Never run `migrate:fresh` or reseed an existing shop. Seeders are for a genuinely empty database and reset catalogue stock/content. Back up the database and uploaded media before updating a live shop.
