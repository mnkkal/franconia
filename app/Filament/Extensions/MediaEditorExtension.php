<?php

namespace App\Filament\Extensions;

use App\Services\ReplaceMediaImage;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Lunar\Admin\Support\Extending\RelationManagerExtension;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaEditorExtension extends RelationManagerExtension
{
    public function extendForm(Schema $schema): Schema
    {
        return $schema->components([
            ...$schema->getComponents(),
            FileUpload::make('replacement_image')
                ->label('Replace image')
                ->helperText('Upload a new photo to replace this image, or leave empty to keep it.')
                ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->maxSize(10240)->storeFiles(false)->imageEditor()
                ->imageEditorAspectRatios([null, '4:5', '1:1', '16:9'])
                ->columnSpanFull()->visibleOn('edit'),
        ]);
    }

    public function extendTable(Table $table): Table
    {
        foreach ($table->getRecordActions() as $action) {
            if ($action instanceof EditAction) {
                $action->using(function (array $data, Media $record): void {
                    $properties = array_merge($record->custom_properties ?? [], $data['custom_properties'] ?? []);
                    if ($upload = $data['replacement_image'] ?? null) {
                        app(ReplaceMediaImage::class)->replace($record, $upload, $properties);
                    } else {
                        $record->update(['custom_properties' => $properties]);
                    }
                });
            }
        }

        return $table;
    }
}
