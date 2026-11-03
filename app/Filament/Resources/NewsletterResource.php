<?php

namespace App\Filament\Resources;

use App\Models\NewsletterSubscription;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NewsletterResource extends StoreResource
{
    protected static ?string $model = NewsletterSubscription::class;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')->searchable(), TextColumn::make('confirmed_at')->dateTime(), TextColumn::make('unsubscribed_at')->dateTime(),
        ])->recordActions([
            Action::make('unsubscribe')->authorize(fn () => static::canViewAny())->requiresConfirmation()->action(fn (NewsletterSubscription $record) => $record->update(['unsubscribed_at' => now()])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => NewsletterResource\Pages\ListNewsletter::route('/')];
    }
}
