<?php

namespace App\Providers;

use App\Filament\Extensions\MediaEditorExtension;
use App\Filament\Extensions\VariantMediaUploadExtension;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Filament\Resources\ProductVariantResource\Pages\ManageVariantMedia;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Admin\Support\RelationManagers\MediaRelationManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        LunarPanel::extensions([ManageVariantMedia::class => VariantMediaUploadExtension::class]);
        LunarPanel::extensions([MediaRelationManager::class => MediaEditorExtension::class]);
        LunarPanel::panel(fn ($panel) => $panel->default()->path('')
            ->brandName('Franconia Fine Fragrances')
            ->brandLogo(fn () => asset('branding/franconia-logo.png'))
            ->darkModeBrandLogo(fn () => asset('branding/franconia-logo-dark.png'))
            ->brandLogoHeight('3.25rem')
            ->favicon(fn () => asset('branding/favicon.png'))
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
