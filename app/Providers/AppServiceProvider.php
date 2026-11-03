<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Support\Facades\LunarPanel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        LunarPanel::panel(fn ($panel) => $panel->default()->path('lunar')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources'))->register();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('store-checkout', fn ($request) => [
            Limit::perMinute(5)->by('checkout:'.$request->ip()),
            Limit::perHour(10)->by('checkout-email:'.hash('sha256', strtolower((string) $request->input('email')))),
        ]);
    }
}
