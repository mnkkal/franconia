<?php

namespace App\Filament\Extensions;

use App\Filament\Widgets\WebsiteOrders;
use App\Filament\Widgets\WebsiteOrderStats;
use Lunar\Admin\Support\Extending\BaseExtension;

class WebsiteDashboardExtension extends BaseExtension
{
    public function getWidgets(array $widgets): array
    {
        return [WebsiteOrderStats::class, WebsiteOrders::class];
    }
}
