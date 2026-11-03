<?php

namespace App\Services;

use App\Models\ShopOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lunar\Models\ProductVariant;

class OrderLifecycle
{
    public function captured(ShopOrder $order, array $payment): ShopOrder
    {
        if (($payment['status'] ?? '') !== 'captured'
            || ($payment['order_id'] ?? '') !== $order->razorpay_order_id
            || ($payment['currency'] ?? '') !== $order->currency
            || (int) ($payment['amount'] ?? 0) !== (int) $order->total
            || ! is_string($payment['id'] ?? null) || $payment['id'] === '') {
            throw ValidationException::withMessages(['payment' => 'Payment does not match this order or has not been captured.']);
        }

        return DB::transaction(function () use ($order, $payment) {
            $locked = ShopOrder::lockForUpdate()->findOrFail($order->id);
            if ($locked->paid_at) {
                if ($locked->razorpay_payment_id !== $payment['id']) {
                    throw ValidationException::withMessages(['payment' => 'Order already has a different payment.']);
                }

                return $locked;
            }
            $available = $locked->status !== 'cancelled';
            if ($locked->inventory_state !== 'reserved') {
                // Capture can arrive after expiry. Reacquire only if all stock is available.
                $variants = ProductVariant::whereIn('sku', $locked->items->pluck('sku'))->orderBy('sku')->lockForUpdate()->get()->keyBy('sku');
                foreach ($locked->items as $item) {
                    if (! isset($variants[$item->sku]) || $variants[$item->sku]->stock < $item->qty) {
                        $available = false;
                    }
                }
                if ($available) {
                    foreach ($locked->items as $item) {
                        $variants[$item->sku]->decrement('stock', $item->qty);
                    }
                }
            }
            $locked->forceFill([
                'status' => $available ? 'paid' : 'payment_review',
                'inventory_state' => $available ? 'consumed' : 'released',
                'paid_at' => now(), 'razorpay_payment_id' => $payment['id'], 'reservation_expires_at' => null,
            ])->save();
            $this->notify($locked, $available ? 'paid' : 'payment_review');

            return $locked;
        }, 3);
    }

    public function release(ShopOrder $order, string $status = 'expired'): ShopOrder
    {
        return DB::transaction(function () use ($order, $status) {
            $locked = ShopOrder::lockForUpdate()->findOrFail($order->id);
            if ($locked->paid_at) {
                return $locked;
            }
            if ($locked->inventory_state === 'reserved') {
                $variants = ProductVariant::whereIn('sku', $locked->items->pluck('sku'))->orderBy('sku')->lockForUpdate()->get()->keyBy('sku');
                foreach ($locked->items as $item) {
                    if (isset($variants[$item->sku])) {
                        $variants[$item->sku]->increment('stock', $item->qty);
                    }
                }
                $locked->inventory_state = 'released';
            }
            $locked->status = $status;
            $locked->reservation_expires_at = null;
            $locked->save();

            return $locked;
        }, 3);
    }

    public function fulfil(ShopOrder $order, string $status, ?string $number = null, ?string $link = null): void
    {
        DB::transaction(function () use ($order, $status, $number, $link) {
            $locked = ShopOrder::lockForUpdate()->findOrFail($order->id);
            $next = ['paid' => ['processing', 'shipped'], 'processing' => ['shipped'], 'shipped' => ['delivered']];
            if (! in_array($status, $next[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This order cannot move to that status.']);
            }
            if ($status === 'shipped' && (! $number || ! $link || ! filter_var($link, FILTER_VALIDATE_URL) || parse_url($link, PHP_URL_SCHEME) !== 'https')) {
                throw ValidationException::withMessages(['tracking' => 'Enter a tracking number and HTTPS courier link.']);
            }
            $locked->forceFill(['status' => $status, 'tracking_number' => $number ?? $locked->tracking_number, 'tracking_link' => $link ?? $locked->tracking_link])->save();
            $this->notify($locked, $status);
        });
    }

    public function requestRefund(ShopOrder $order, bool $restock = false, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $restock, $reason) {
            $locked = ShopOrder::lockForUpdate()->findOrFail($order->id);
            if (! $locked->paid_at || ! in_array($locked->status, ['paid', 'processing', 'shipped', 'delivered', 'payment_review', 'refund_pending', 'refund_failed'], true)) {
                throw ValidationException::withMessages(['refund' => 'This order cannot be refunded.']);
            }
            if (! in_array($locked->status, ['refund_pending', 'refund_failed'])) {
                $locked->refund_restock = in_array($locked->status, ['paid', 'processing']) || $restock;
                $locked->refund_reason = $reason;
            }
            if ($locked->status !== 'refund_pending') {
                $locked->refund_attempt++;
                $locked->refund_id = null;
                $locked->refund_status = null;
            }
            $locked->forceFill(['status' => 'refund_pending'])->save();
        });
        // Durable intent first; reconciliation retries with the same gateway idempotency key.
        $this->syncRefund($order->fresh());
    }

    public function syncRefund(ShopOrder $order): void
    {
        $gateway = app(PaymentGateway::class);
        $refund = $order->refund_id ? $gateway->refundStatus($order->refund_id) : $gateway->refund($order);
        if (($refund['payment_id'] ?? '') !== $order->razorpay_payment_id || (int) ($refund['amount'] ?? 0) !== (int) $order->total) {
            throw ValidationException::withMessages(['refund' => 'Gateway refund does not match this order.']);
        }
        DB::transaction(function () use ($order, $refund) {
            $locked = ShopOrder::lockForUpdate()->findOrFail($order->id);
            if ($locked->refund_attempt !== $order->refund_attempt) {
                return;
            }
            $locked->refund_id = $refund['id'];
            $locked->refund_status = $refund['status'];
            if ($refund['status'] === 'failed') {
                $locked->status = 'refund_failed';
            }
            if ($refund['status'] === 'processed' && ! $locked->refunded_at) {
                // Only unshipped, consumed inventory is safe to restore automatically.
                if ($locked->inventory_state === 'consumed' && $locked->refund_restock) {
                    $variants = ProductVariant::whereIn('sku', $locked->items->pluck('sku'))->orderBy('sku')->lockForUpdate()->get()->keyBy('sku');
                    foreach ($locked->items as $item) {
                        if (isset($variants[$item->sku])) {
                            $variants[$item->sku]->increment('stock', $item->qty);
                        }
                    }
                }
                $locked->forceFill(['status' => 'refunded', 'inventory_state' => $locked->refund_restock ? 'released' : $locked->inventory_state, 'refunded_at' => now()]);
                $this->notify($locked, 'refunded');
            }
            $locked->save();
        });
    }

    public function notify(ShopOrder $order, string $event): void
    {
        DB::table('store_notifications')->insertOrIgnore([
            'shop_order_id' => $order->id, 'event' => $event, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
