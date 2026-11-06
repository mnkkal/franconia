<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StoreOrderResource;
use App\Models\ShopOrder;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class WebsiteOrders extends TableWidget
{
    public static function canView(): bool
    {
        return StoreOrderResource::canViewAny();
    }

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return StoreOrderResource::table($table)->query(ShopOrder::query())->heading('Latest orders')->paginated([5, 10, 25])->defaultPaginationPageOption(5);
    }
}
