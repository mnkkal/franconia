<?php

namespace App\Services;

use App\Support\ByobPricing;
use App\Support\VariantSize;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;

class SyncSizeOptions
{
    /** Add missing Lunar option links without recreating or updating variants. */
    public function sync(Product $product): array
    {
        $variants = $product->variants()->get()->filter(fn ($variant) => ByobPricing::sizeForSku((string) $variant->sku));
        if ($variants->isEmpty()) {
            return [];
        }
        $option = $product->productOptions()->whereIn('handle', VariantSize::HANDLES)->first()
            ?? ProductOption::firstOrCreate(['handle' => 'franconia-bottle-size', 'shared' => true], [
                'name' => ['en' => 'Size'], 'label' => ['en' => 'Size'],
            ]);
        if (! $product->productOptions()->whereKey($option->id)->exists()) {
            $product->productOptions()->attach($option->id, ['position' => $product->productOptions()->count() + 1]);
        }
        $conflicts = [];
        foreach ($variants as $variant) {
            $size = ByobPricing::sizeForSku($variant->sku);
            $existing = $variant->values()->where('product_option_id', $option->id)->first();
            if ($existing) {
                if (strtoupper(preg_replace('/\s+/', '', (string) $existing->translate('name'))) !== $size) {
                    $conflicts[] = $variant->sku;
                }

                continue;
            }
            $value = $option->values()->get()->first(fn ($value) => strtoupper(preg_replace('/\s+/', '', (string) $value->translate('name'))) === $size)
                ?? $option->values()->create(['name' => ['en' => $size], 'position' => $size === '50ML' ? 1 : 2]);
            $variant->values()->syncWithoutDetaching([$value->id]);
        }

        return $conflicts;
    }
}
