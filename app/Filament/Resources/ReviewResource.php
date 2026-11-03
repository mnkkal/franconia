<?php

namespace App\Filament\Resources;

use App\Models\Review;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReviewResource extends StoreResource
{
    protected static ?string $model = Review::class;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('product_slug'), TextColumn::make('author'), TextColumn::make('rating'),
            TextColumn::make('title')->wrap(), TextColumn::make('body')->wrap(), TextColumn::make('active')->badge(),
        ])->recordActions([
            Action::make('approve')->authorize(fn () => static::canViewAny())->requiresConfirmation()->action(fn (Review $record) => $record->update(['active' => true])),
            Action::make('hide')->authorize(fn () => static::canViewAny())->requiresConfirmation()->action(fn (Review $record) => $record->update(['active' => false])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ReviewResource\Pages\ListReviews::route('/')];
    }
}
