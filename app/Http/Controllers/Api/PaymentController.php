<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Services\OrderLifecycle;
use App\Services\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function verify(Request $request, PaymentGateway $gateway, OrderLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'size:64'],
            'order_id' => ['required', 'string', 'max:100'],
        ]);
        $order = ShopOrder::where('reference', $data['order_id'])->where('razorpay_order_id', $data['razorpay_order_id'])->firstOrFail();
        $secret = (string) config('services.razorpay.key_secret');
        $expected = hash_hmac('sha256', $order->razorpay_order_id.'|'.$data['razorpay_payment_id'], $secret);
        if (! $secret || ! hash_equals($expected, $data['razorpay_signature'])) {
            return response()->json(['message' => 'Invalid payment signature'], 422);
        }
        try {
            $payment = $gateway->payment($data['razorpay_payment_id']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Payment verification pending. Save your order reference and check order tracking.'], 503);
        }
        if (($payment['status'] ?? '') === 'authorized' && ($payment['order_id'] ?? '') === $order->razorpay_order_id) {
            return response()->json(['verified' => false, 'order_id' => $order->reference, 'status' => 'awaiting_capture'], 202);
        }
        $order = $lifecycle->captured($order, $payment);

        return response()->json(['verified' => true, 'order_id' => $order->reference, 'status' => $order->status]);
    }

    public function webhook(Request $request, OrderLifecycle $lifecycle): JsonResponse
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        if (! $secret || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Bad webhook signature'], 401);
        }
        if ($request->input('event') === 'payment.captured') {
            $payment = $request->input('payload.payment.entity');
            if (! is_array($payment) || ! is_string($payment['order_id'] ?? null) || ! $payment['order_id']) {
                return response()->json(['message' => 'Invalid payment payload'], 422);
            }
            $order = ShopOrder::where('razorpay_order_id', $payment['order_id'])->first();
            if ($order) {
                $lifecycle->captured($order, $payment);
            }
        }

        return response()->json(['ok' => true]);
    }
}
