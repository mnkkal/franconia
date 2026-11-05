<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReplaceMediaImage
{
    public function replace(Media $original, UploadedFile $upload, array $properties): Media
    {
        // Finish storing the new original and thumbnails before removing anything.
        // A new media ID also gives browsers a fresh URL rather than cached pixels.
        $replacement = $original->model->addMedia($upload)
            ->preservingOriginal()
            ->usingName($original->name)
            ->withCustomProperties($properties)
            ->storingConversionsOnDisk($original->conversions_disk ?: $original->disk)
            ->toMediaCollection($original->collection_name, $original->disk);

        try {
            DB::transaction(function () use ($original, $replacement): void {
                $replacement->update(['order_column' => $original->order_column]);
                DB::table(config('lunar.database.table_prefix').'media_product_variant')
                    ->where('media_id', $original->id)->update(['media_id' => $replacement->id]);
            });
        } catch (\Throwable $exception) {
            $replacement->delete();
            throw $exception;
        }

        $original->delete();

        return $replacement;
    }
}
