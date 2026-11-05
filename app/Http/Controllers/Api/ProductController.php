<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Support\ByobPricing;
use App\Support\VariantSize;
use Illuminate\Http\JsonResponse;
use Lunar\Models\Currency;
use Lunar\Models\Product;

/**
 * Headless catalogue read from Lunar models.
 * Prices resolved in INR (minor units → rupees for the storefront).
 */
class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->where('status', 'published')
            ->with(['defaultUrl', 'tags', 'collections.defaultUrl', 'variants.basePrices.currency', 'variants.values.option', 'images'])
            ->get()
            ->map(fn ($p) => $this->serialize($p));

        return response()->json(['data' => $products, 'byob' => ByobPricing::tiers()]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('status', 'published')
            ->whereHas('urls', fn ($q) => $q->where('slug', $slug))
            ->with(['defaultUrl', 'tags', 'collections.defaultUrl', 'variants.basePrices.currency', 'variants.values.option', 'images'])
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($product, true)]);
    }

    private function serialize(Product $p, bool $detail = false): array
    {
        $inr = Currency::where('code', 'INR')->first();
        $slug = $p->defaultUrl?->slug;
        $variants = $p->variants->map(function ($v) use ($inr) {
            $price = $v->basePrices->firstWhere('currency_id', $inr?->id)
                ?? $v->basePrices->first();
            // Lunar casts price columns to Price datatypes — use raw minor units.
            $minor = $price ? (int) $price->getRawOriginal('price') : 0;
            $compare = $price ? (int) $price->getRawOriginal('compare_price') : 0;
            $tier = ByobPricing::tiers()[ByobPricing::sizeForSku($v->sku)] ?? null;
            if ($tier) {
                $minor = $tier['totals'][1];
                $compare = $tier['mrp'];
            }

            return [
                'id' => $v->sku ?: (string) $v->id,
                'sku' => $v->sku,
                'label' => VariantSize::label($v),
                'price' => $minor ? $minor / 100 : 0,
                'mrp' => $compare ? $compare / 100 : ($minor ? $minor / 100 : 0),
                'stock' => $v->purchasable === 'out-of-stock' ? 0 : (int) $v->stock,
            ];
        })->values();

        $data = [
            'slug' => $slug,
            'name' => $p->translateAttribute('name'),
            'description' => strip_tags((string) ($p->translateAttribute('description') ?? '')),
            'badges' => $p->tags->pluck('value')->map(fn ($tag) => [
                'NEW LAUNCH' => 'New Launch', 'ON SALE' => 'On Sale', 'BESTSELLER' => 'Bestseller', 'COMING SOON' => 'Coming Soon',
            ][strtoupper($tag)] ?? $tag)->values(),
            'collections' => $p->collections->map(fn ($c) => $c->defaultUrl?->slug)->filter()->values(),
            'variants' => $variants,
            'images' => $p->images->sortBy('order_column')
                ->sortByDesc(fn ($image) => (bool) $image->getCustomProperty('primary'))
                ->map(fn ($image) => $image->getUrl())->values(),
            'reviews_count' => Review::where('product_slug', $slug)->where('active', true)->count(),
            'rating' => (float) (Review::where('product_slug', $slug)->where('active', true)->avg('rating') ?? 0),
        ];

        if ($detail) {
            $data['id'] = $p->id;
            $data['status'] = $p->status;
        }

        return $data;
    }
}
