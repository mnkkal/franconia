<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ByobPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lunar\Actions\Carts\AddOrUpdatePurchasable;
use Lunar\Actions\Carts\UpdateCartLine;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\ProductVariant;

/**
 * Thin server-side carts backed by Lunar (guest carts).
 * The static storefront keeps its own localStorage cart and only needs
 * these endpoints for live stock/price validation — checkout is authoritative.
 */
class CartController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:10'],
            'coupon' => ['nullable', 'string'],
        ]);

        $variants = [];
        foreach ($data['items'] as $item) {
            $variant = ProductVariant::where('sku', $item['sku'])->first();
            if (! $variant) {
                return response()->json(['message' => "Unknown SKU: {$item['sku']}"], 422);
            }
            $variants[] = [$variant, (int) $item['qty']];
        }

        $cart = Cart::create([
            'currency_id' => Currency::where('code', 'INR')->firstOrFail()->id,
            'channel_id' => Channel::where('default', true)->firstOrFail()->id,
            'coupon_code' => $data['coupon'] ?? null,
        ]);

        foreach ($variants as [$variant, $qty]) {
            app(AddOrUpdatePurchasable::class)->execute($cart, $variant, $qty);
        }

        return response()->json(['data' => $this->serialize($cart->refresh())], 201);
    }

    public function show(Cart $cart): JsonResponse
    {
        return response()->json(['data' => $this->serialize($cart)]);
    }

    public function update(Request $request, Cart $cart): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        foreach ($data['lines'] as $line) {
            $cartLine = $cart->lines()->findOrFail($line['id']);
            if ((int) $line['qty'] === 0) {
                $cartLine->delete();

                continue;
            }
            app(UpdateCartLine::class)->execute($cartLine->id, (int) $line['qty']);
        }

        return response()->json(['data' => $this->serialize($cart->refresh())]);
    }

    public function destroyItem(Cart $cart, int $item): JsonResponse
    {
        $cart->lines()->findOrFail($item)->delete();

        return response()->json(['data' => $this->serialize($cart->refresh())]);
    }

    private function serialize(Cart $cart): array
    {
        $cart->loadMissing(['lines.purchasable.basePrices.currency']);
        $lines = $cart->lines->map(function ($line) {
            $price = $line->purchasable?->basePrices->first();
            $minor = $price ? (int) $price->getRawOriginal('price') : 0;

            return [
                'id' => $line->id,
                'sku' => $line->purchasable?->sku,
                'qty' => $line->quantity,
                'unit_price' => $minor ? $minor / 100 : 0,
                'size' => ByobPricing::sizeForSku($line->purchasable?->sku ?? ''),
                'line_total' => $minor * $line->quantity,
            ];
        })->values();

        $priced = collect(ByobPricing::apply($lines->all()))->map(function ($line) {
            $line['line_total'] /= 100;
            // Average unit price may be fractional paise; line_total is authoritative.
            $line['unit_price'] = $line['line_total'] / $line['qty'];

            return $line;
        });

        return [
            'id' => $cart->id,
            'currency' => 'INR',
            'lines' => $priced,
            'subtotal' => $priced->sum('line_total'),
            'count' => $lines->sum('qty'),
        ];
    }
}
