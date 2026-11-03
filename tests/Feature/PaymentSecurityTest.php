<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    public function test_webhook_rejects_missing_secret_even_without_signature(): void
    {
        config(['services.razorpay.webhook_secret' => '']);
        $this->postJson('/api/payment/webhook', ['event' => 'payment.captured'])->assertUnauthorized();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-secret']);
        $this->postJson('/api/payment/webhook', ['event' => 'payment.captured'], [
            'X-Razorpay-Signature' => 'invalid',
        ])->assertUnauthorized();
    }
}
