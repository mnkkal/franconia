<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StoreOrderResource;
use App\Models\ShopOrder;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WebsiteOrderStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return StoreOrderResource::canViewAny();
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Orders', ShopOrder::count()),
            Stat::make('Paid sales', '₹'.number_format(ShopOrder::whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])->sum('total') / 100, 2)),
            Stat::make('Awaiting payment', ShopOrder::where('status', 'pending')->count()),
        ];
    }
}
