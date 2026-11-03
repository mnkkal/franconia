<?php

namespace App\Services;

use App\Models\ShopOrder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymentGateway
{
    private function client(): PendingRequest
    {
        $id = config('services.razorpay.key_id');
        $secret = config('services.razorpay.key_secret');
        if (! $id || ! $secret) {
            throw new RuntimeException('Payment gateway is not configured.');
        }

        return Http::baseUrl('https://api.razorpay.com/v1')
            ->withBasicAuth($id, $secret)->acceptJson()->asJson()->connectTimeout(10)->timeout(25);
    }

    public function createOrder(ShopOrder $order): array
    {
        // Do not retry order creation automatically: Orders API has no idempotency key.
        return $this->client()->post('/orders', [
            'receipt' => $order->reference, 'amount' => $order->total, 'currency' => $order->currency,
        ])->throw()->json();
    }

    public function payment(string $id): array
    {
        return $this->client()->get('/payments/'.rawurlencode($id))->throw()->json();
    }

    public function payments(string $orderId): array
    {
        return $this->client()->get('/orders/'.rawurlencode($orderId).'/payments')->throw()->json('items') ?? [];
    }

    public function refund(ShopOrder $order): array
    {
        return $this->client()->withHeaders(['X-Refund-Idempotency' => 'refund-'.$order->reference.'-'.$order->refund_attempt])
            ->post('/payments/'.rawurlencode($order->razorpay_payment_id).'/refund', [
                'amount' => $order->total, 'speed' => 'normal', 'receipt' => $order->reference,
            ])->throw()->json();
    }

    public function refundStatus(string $id): array
    {
        return $this->client()->get('/refunds/'.rawurlencode($id))->throw()->json();
    }
}
