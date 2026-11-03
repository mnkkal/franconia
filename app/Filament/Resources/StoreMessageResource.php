<?php

namespace App\Filament\Resources;

use App\Models\StoreMessage;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StoreMessageResource extends StoreResource
{
    protected static ?string $model = StoreMessage::class;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('email')->searchable()->copyable(),
            TextColumn::make('message')->wrap(), TextColumn::make('handled')->badge()->formatStateUsing(fn ($state) => $state ? 'Handled' : 'New'),
            TextColumn::make('created_at')->dateTime(),
        ])->recordActions([
            Action::make('markHandled')->authorize(fn () => static::canViewAny())->requiresConfirmation()->action(fn (StoreMessage $record) => $record->update(['handled' => true])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => StoreMessageResource\Pages\ListStoreMessages::route('/')];
    }
}
