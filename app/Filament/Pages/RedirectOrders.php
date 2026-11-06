<?php

namespace App\Filament\Pages;

use App\Filament\Resources\StoreOrderResource;
use Filament\Resources\Pages\ListRecords;
use Lunar\Admin\Filament\Resources\OrderResource;

class RedirectOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->redirect(StoreOrderResource::getUrl('index'));
    }
}
