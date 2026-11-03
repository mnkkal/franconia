<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Product;

class CheckDeployment extends Command
{
    protected $signature = 'franconia:check-deploy {--skip-scheduler : Skip only during initial setup, before cron has run}';

    protected $description = 'Check required production configuration without printing secrets';

    public function handle(): int
    {
        $checks = [
            'PHP 8.3+ 64-bit' => PHP_VERSION_ID >= 80300 && PHP_INT_SIZE === 8,
            'Production environment' => app()->environment('production'),
            'Debug disabled' => ! config('app.debug'),
            'Application encryption key' => (bool) config('app.key'),
            'Real HTTPS API origin' => $this->origin(config('app.url')),
            'Business postal address' => strlen(trim(config('store.business_address'))) > 10,
            'Grievance officer name' => strlen(trim(config('store.grievance_officer'))) > 2,
            'Payment key and secret' => (bool) preg_match('/^rzp_live_[a-zA-Z0-9]+$/', (string) config('services.razorpay.key_id'))
                && strlen((string) config('services.razorpay.key_secret')) > 12 && ! str_contains((string) config('services.razorpay.key_secret'), 'xxxx'),
            'Webhook signing secret' => strlen((string) config('services.razorpay.webhook_secret')) >= 16 && ! str_contains((string) config('services.razorpay.webhook_secret'), 'xxxx'),
            'Real SMTP settings' => config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host'))
                && ! in_array(config('mail.mailers.smtp.host'), ['127.0.0.1', 'localhost']) && filled(config('mail.mailers.smtp.username')) && filled(config('mail.mailers.smtp.password')),
            'Sender email configured' => filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) && ! str_contains(config('mail.from.address'), 'example'),
        ];
        foreach (config('cors.allowed_origins', []) as $origin) {
            $checks['Storefront origin '.$origin] = $this->origin($origin);
        }
        $checks['CORS allow-list present'] = count(config('cors.allowed_origins', [])) > 0;
        foreach (['bcmath', 'exif', 'intl', 'curl', 'mbstring', 'pdo_mysql', 'fileinfo', 'zip'] as $extension) {
            $checks['Extension '.$extension] = extension_loaded($extension);
        }
        try {
            DB::connection()->getPdo();
            $checks['Database reachable'] = true;
            foreach (['shop_orders', 'store_messages', 'newsletter_subscriptions', 'store_notifications'] as $table) {
                $checks['Table '.$table] = Schema::hasTable($table);
            }
            $checks['Stock reservation migration'] = Schema::hasColumn('shop_orders', 'inventory_state');
            $checks['Administrator exists'] = Staff::where('admin', true)->exists();
            $checks['Product photographs uploaded'] = Product::where('status', 'published')->whereDoesntHave('images')->count() === 0 && Product::where('status', 'published')->exists();
        } catch (\Throwable $e) {
            $checks['Database and migrations'] = false;
        }
        if (! $this->option('skip-scheduler')) {
            $last = Cache::get('franconia-maintenance-heartbeat');
            $checks['Cron ran store maintenance in the past 5 minutes'] = $last && now()->diffInSeconds($last, true) < 300;
        }
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? 'PASS ' : 'FAIL ').$name);
        }
        $this->warn('This checks configuration, not live payment delivery, SMTP delivery, backups or tax-policy accuracy. Complete staging acceptance checks before launch.');

        return in_array(false, array_values($checks), true) ? self::FAILURE : self::SUCCESS;
    }

    private function origin(?string $value): bool
    {
        $url = parse_url((string) $value);

        return $url && ($url['scheme'] ?? '') === 'https' && ! empty($url['host'])
            && ! preg_match('/localhost|127\.0\.0\.1|example\.|yourdomain/i', $url['host'])
            && empty($url['query']) && empty($url['fragment']) && empty($url['user']) && empty($url['pass'])
            && in_array($url['path'] ?? '', ['', '/'], true);
    }
}
