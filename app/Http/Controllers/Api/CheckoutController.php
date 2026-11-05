<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Services\OrderLifecycle;
use App\Services\PaymentGateway;
use App\Support\ByobPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lunar\Models\Currency;
use Lunar\Models\ProductVariant;

class CheckoutController extends Controller
{
    public function store(Request $request, PaymentGateway $gateway, OrderLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.sku' => ['required', 'string', 'max:80'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:10'],
            'items.*.variant_id' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['required', 'regex:/^(?:\+91[ -]?)?[6-9][0-9]{9}$/'],
            'address' => ['required', 'string', 'min:5', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'pincode' => ['required', 'regex:/^[1-9][0-9]{5}$/'],
            'coupon' => ['prohibited'],
        ]);
        if (! config('services.razorpay.key_id') || ! config('services.razorpay.key_secret')) {
            return response()->json(['message' => 'Payments are temporarily unavailable. Please contact us.'], 503);
        }
        $items = collect($data['items'])->groupBy('sku')->map(fn ($items, $sku) => ['sku' => $sku, 'qty' => (int) $items->sum('qty')])->sortBy('sku')->values();
        if ($items->contains(fn ($item) => $item['qty'] > 10)) {
            throw ValidationException::withMessages(['items' => 'Maximum ten bottles of each fragrance/size per order.']);
        }
        $order = DB::transaction(function () use ($data, $items) {
            $currency = Currency::where('code', 'INR')->firstOrFail();
            $variants = ProductVariant::whereIn('sku', $items->pluck('sku'))->orderBy('sku')->lockForUpdate()->get()->keyBy('sku');
            $lines = [];
            foreach ($items as $item) {
                $variant = $variants[$item['sku']] ?? null;
                if (! $variant || $variant->product?->status !== 'published') {
                    throw ValidationException::withMessages(['items' => "Unknown SKU: {$item['sku']}"]);
                }
                if ($variant->stock < $item['qty'] || $variant->purchasable === 'out-of-stock') {
                    throw ValidationException::withMessages(['items' => "Out of stock: {$item['sku']}"]);
                }
                $price = $variant->basePrices()->where('currency_id', $currency->id)->first();
                if (! $price) {
                    throw ValidationException::withMessages(['items' => 'This product is unavailable.']);
                }
                $minor = (int) $price->getRawOriginal('price');
                $size = ByobPricing::sizeForSku($variant->sku);
                $lines[] = ['variant' => $variant, 'size' => $size, 'qty' => $item['qty'], 'unit_price' => $minor, 'line_total' => $minor * $item['qty']];
            }
            $lines = ByobPricing::apply($lines);
            $subtotal = array_sum(array_column($lines, 'line_total'));
            $shipping = $subtotal >= config('store.shipping_threshold') ? 0 : config('store.shipping_fee');
            $order = ShopOrder::create([
                'reference' => 'FR-'.Str::upper((string) Str::ulid()), 'status' => 'pending',
                'name' => $data['name'], 'email' => Str::lower($data['email']), 'phone' => $data['phone'],
                'address' => $data['address'], 'city' => $data['city'], 'state' => $data['state'], 'pincode' => $data['pincode'],
                'subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $subtotal + $shipping,
                'tax' => (int) round($subtotal - $subtotal / (1 + config('store.gst_rate'))), 'currency' => 'INR',
                'inventory_state' => 'reserved', 'reservation_expires_at' => now()->addMinutes(config('store.reservation_minutes')),
            ]);
            foreach ($lines as $line) {
                $variant = $line['variant'];
                $variant->decrement('stock', $line['qty']);
                $order->items()->create([
                    'sku' => $variant->sku, 'variant_id' => $variant->sku,
                    'product_name' => $variant->product?->translateAttribute('name') ?? $variant->sku,
                    'variant_label' => $variant->translateAttribute('size'),
                    'qty' => $line['qty'], 'unit_price' => $line['unit_price'], 'line_total' => $line['line_total'],
                ]);
            }

            return $order;
        }, 3);
        try {
            $remote = $gateway->createOrder($order);
            if (! is_string($remote['id'] ?? null) || (int) ($remote['amount'] ?? 0) !== (int) $order->total || ($remote['currency'] ?? '') !== 'INR') {
                throw new \RuntimeException('Unexpected gateway order response.');
            }
            $order->forceFill(['razorpay_order_id' => $remote['id']])->save();
        } catch (\Throwable $e) {
            report($e);
            $lifecycle->release($order, 'failed');

            return response()->json(['message' => 'Could not create payment order. Please try again later.'], 502);
        }

        return response()->json([
            'order_id' => $order->reference, 'razorpay_order_id' => $order->razorpay_order_id,
            'amount' => $order->total, 'key_id' => config('services.razorpay.key_id'),
        ], 201);
    }
}
