<?php

namespace App\Support;

use Lunar\Models\ProductVariant;

class VariantSize
{
    public const HANDLES = ['size', 'bottle-size', 'franconia-bottle-size'];

    public static function label(ProductVariant $variant): string
    {
        $value = $variant->values->first(fn ($value) => in_array($value->option?->handle, self::HANDLES, true));
        $label = $value?->translate('name') ?? $variant->translateAttribute('size') ?? ByobPricing::sizeForSku($variant->sku);
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $label));

        return in_array($normalized, ['20ML', '50ML'], true) ? $normalized : ((string) $label ?: $variant->sku);
    }
}
