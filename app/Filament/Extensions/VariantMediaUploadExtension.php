<?php

namespace App\Filament\Extensions;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Table;
use Lunar\Admin\Support\Extending\RelationPageExtension;

class VariantMediaUploadExtension extends RelationPageExtension
{
    public function extendTable(Table $table): Table
    {
        $page = $this->caller;

        return $table->headerActions([
            ...$table->getHeaderActions(),
            Action::make('uploadVariantPhoto')->label('Upload variant photo')
                ->schema([FileUpload::make('photo')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)->required()->storeFiles(false)->imageEditor()])
                ->action(function (array $data) use ($page): void {
                    $variant = $page->getRecord();
                    $media = $variant->product->addMedia($data['photo'])->preservingOriginal()
                        ->withCustomProperties(['variant_only' => true])->toMediaCollection('images', 'public');
                    $variant->images()->attach($media->id, ['primary' => $variant->images()->count() === 0, 'position' => $variant->images()->count() + 1]);
                }),
        ]);
    }
}
