<?php

namespace App\Filament\Resources;

use App\Models\ShopOrder;
use App\Services\OrderLifecycle;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StoreOrderResource extends StoreResource
{
    protected static ?string $model = ShopOrder::class;

    protected static ?string $navigationLabel = 'Orders';

    protected static ?string $modelLabel = 'order';

    public static function getNavigationGroup(): ?string
    {
        return 'Sales';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('reference')->searchable()->copyable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('total')->formatStateUsing(fn ($state) => '₹'.number_format($state / 100, 2)),
            TextColumn::make('refund_status')->badge(),
            TextColumn::make('created_at')->dateTime(),
        ])->filters([
            SelectFilter::make('status')->options(array_combine($states = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'expired', 'cancelled', 'failed', 'payment_review', 'refund_pending', 'refund_failed', 'refunded'], $states)),
        ])->recordActions([
            Action::make('details')->authorize(fn () => static::canViewAny())->modalHeading('Order details')
                ->modalContent(fn (ShopOrder $record) => view('orders.staff-details', ['order' => $record->load('items')]))->modalSubmitAction(false),
            Action::make('fulfil')->authorize(fn () => static::canViewAny())->visible(fn (ShopOrder $record) => in_array($record->status, ['paid', 'processing', 'shipped']))
                ->schema([
                    Select::make('status')->options(['processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered'])->required(),
                    TextInput::make('tracking_number')->maxLength(120),
                    TextInput::make('tracking_link')->url()->maxLength(500),
                ])->action(fn (ShopOrder $record, array $data) => app(OrderLifecycle::class)->fulfil($record, $data['status'], $data['tracking_number'] ?: null, $data['tracking_link'] ?: null)),
            Action::make('refund')->label('Full refund')->color('danger')->authorize(fn () => static::canViewAny())
                ->visible(fn (ShopOrder $record) => in_array($record->status, ['paid', 'processing', 'shipped', 'delivered', 'payment_review', 'refund_failed']))
                ->schema([
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                    Toggle::make('restock')->label('Returned products have been inspected and can be restocked')
                        ->helperText('Leave off for damaged or unreturned shipped products. Unshipped stock is restored automatically.')
                        ->visible(fn (ShopOrder $record) => in_array($record->status, ['shipped', 'delivered'])),
                ])->requiresConfirmation()->modalDescription('Refund the full captured amount including shipping to the original payment method. Stock is restored only after Razorpay confirms the refund.')
                ->action(fn (ShopOrder $record, array $data) => app(OrderLifecycle::class)->requestRefund($record, (bool) ($data['restock'] ?? false), $data['reason'])),
            Action::make('cancel')->authorize(fn () => static::canViewAny())->visible(fn (ShopOrder $record) => $record->status === 'pending')
                ->requiresConfirmation()->action(fn (ShopOrder $record) => app(OrderLifecycle::class)->release($record, 'cancelled')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => StoreOrderResource\Pages\ListStoreOrders::route('/')];
    }
}
