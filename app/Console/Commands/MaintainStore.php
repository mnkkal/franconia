<?php

namespace App\Console\Commands;

use App\Mail\OrderUpdate;
use App\Models\ShopOrder;
use App\Services\OrderLifecycle;
use App\Services\PaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MaintainStore extends Command
{
    protected $signature = 'franconia:maintain';

    protected $description = 'Reconcile payments/refunds, release expired stock, and deliver order email outbox';

    public function handle(PaymentGateway $gateway, OrderLifecycle $lifecycle): int
    {
        return Cache::lock('franconia-maintain', 600)->get(function () use ($gateway, $lifecycle) {
            ShopOrder::whereNull('paid_at')->where(function ($q) {
                $q->where('inventory_state', 'reserved')->orWhere(function ($q) {
                    $q->whereIn('status', ['pending', 'expired', 'cancelled'])->where('created_at', '>=', now()->subDays(3));
                });
            })->orderBy('id')->chunkById(50, function ($orders) use ($gateway, $lifecycle) {
                foreach ($orders as $order) {
                    try {
                        if ($order->razorpay_order_id) {
                            foreach ($gateway->payments($order->razorpay_order_id) as $payment) {
                                if (($payment['status'] ?? '') === 'captured') {
                                    $order = $lifecycle->captured($order, $payment);
                                    break;
                                }
                            }
                        }
                        if (! $order->paid_at && $order->reservation_expires_at?->isPast()) {
                            $lifecycle->release($order);
                        }
                    } catch (\Throwable $e) {
                        // Do not release stock when gateway state is unknown; retry next run.
                        report($e);
                        $this->error('Could not reconcile '.$order->reference);
                    }
                }
            });
            ShopOrder::where('status', 'refund_pending')->orderBy('id')->chunkById(50, function ($orders) use ($lifecycle) {
                foreach ($orders as $order) {
                    try {
                        $lifecycle->syncRefund($order);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
            DB::table('store_notifications')->whereNull('sent_at')->orderBy('id')->limit(100)->get()->each(function ($notification) {
                try {
                    if (app()->environment('production') && config('mail.default') !== 'smtp') {
                        throw new \RuntimeException('Production SMTP must be configured before acknowledging order emails.');
                    }
                    $order = ShopOrder::with('items')->findOrFail($notification->shop_order_id);
                    DB::table('store_notifications')->where('id', $notification->id)->increment('attempts');
                    Mail::to($order->email)->send(new OrderUpdate($order, $notification->event));
                    if ($notification->event === 'payment_review') {
                        Mail::to(config('store.support_email'))->send(new OrderUpdate($order, $notification->event));
                    }
                    DB::table('store_notifications')->where('id', $notification->id)->update(['sent_at' => now(), 'updated_at' => now()]);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
            $this->info('Store maintenance completed.');
            Cache::put('franconia-maintenance-heartbeat', now(), now()->addDay());

            return self::SUCCESS;
        }) ?? self::SUCCESS;
    }
}
