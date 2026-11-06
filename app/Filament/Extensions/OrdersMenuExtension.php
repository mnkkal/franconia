<?php

namespace App\Filament\Extensions;

use App\Filament\Pages\RedirectOrders;
use Lunar\Admin\Support\Extending\ResourceExtension;

class OrdersMenuExtension extends ResourceExtension
{
    public function extendPages(array $pages): array
    {
        return ['index' => RedirectOrders::route('/')];
    }
}
