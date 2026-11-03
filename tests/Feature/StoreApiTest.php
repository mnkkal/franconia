<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ShopOrder;
use App\Services\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Headless storefront API contracts. Uses the seeded catalogue
 * (FranconiaBaseSeeder + FranconiaCatalogSeeder) — no RefreshDatabase
 * so Lunar's large migration set isn't rebuilt per run.
 */
class StoreApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        foreach (range(1, 4) as $i) {
            Review::create(['product_slug' => 'noble', 'product_name' => 'NOBLE', 'author' => 'Test reviewer '.$i, 'rating' => 5, 'title' => 'Test', 'body' => 'Test review fixture.', 'active' => true]);
        }
    }

    public function test_products_index_returns_five_fragrances(): void
    {
        $res = $this->getJson('/api/products');
        $res->assertOk();
        $slugs = collect($res->json('data'))->pluck('slug')->sort()->values()->all();
        // Elixir (Coming Soon) stays published but out-of-stock.
        $this->assertEquals(['blaze', 'elixir', 'noble', 'pearls', 'petals'], $slugs);
    }

    public function test_product_show_includes_variants_and_badges(): void
    {
        $res = $this->getJson('/api/products/noble');
        $res->assertOk()
            ->assertJsonPath('data.name', 'NOBLE')
            ->assertJsonCount(2, 'data.variants');
        $this->assertContains('Bestseller', $res->json('data.badges'));
    }

    public function test_collections_index_and_show(): void
    {
        $this->getJson('/api/collections')->assertOk()->assertJsonCount(4, 'data');
        $res = $this->getJson('/api/collections/gifting');
        $res->assertOk()->assertJsonPath('data.slug', 'gifting');
        $this->assertNotEmpty($res->json('data.products'));
    }

    public function test_cart_lifecycle(): void
    {
        $create = $this->postJson('/api/cart', [
            'items' => [['sku' => 'FR-NOB-50', 'qty' => 2]],
        ]);
        $create->assertCreated()->assertJsonPath('data.count', 2);
        $cartId = $create->json('data.id');
        $lineId = $create->json('data.lines.0.id');

        $this->getJson("/api/cart/{$cartId}")->assertOk();

        $this->patchJson("/api/cart/{$cartId}", [
            'lines' => [['id' => $lineId, 'qty' => 1]],
        ])->assertOk()->assertJsonPath('data.count', 1);

        $this->deleteJson("/api/cart/{$cartId}/items/{$lineId}")->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_checkout_rejects_unknown_sku(): void
    {
        $res = $this->postJson('/api/checkout', [
            'items' => [['sku' => 'NOPE', 'qty' => 1]],
            'name' => 'Test', 'email' => 't@example.com', 'phone' => '9876543210',
            'address' => 'Test address', 'pincode' => '411001', 'city' => 'Pune', 'state' => 'Maharashtra',
        ]);
        $res->assertStatus(422)->assertJsonPath('errors.items.0', 'Unknown SKU: NOPE');
    }

    public function test_checkout_rejects_coming_soon_stock(): void
    {
        $res = $this->postJson('/api/checkout', [
            'items' => [['sku' => 'FR-ELI-50', 'qty' => 1]],
            'name' => 'Test', 'email' => 't@example.com', 'phone' => '9876543210',
            'address' => 'Test address', 'pincode' => '411001', 'city' => 'Pune', 'state' => 'Maharashtra',
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('Out of stock', $res->json('errors.items.0'));
    }

    public function test_payment_verify_rejects_bad_signature(): void
    {
        $order = ShopOrder::create([
            'reference' => 'FR-TEST1', 'status' => 'pending',
            'name' => 'Test', 'email' => 't@example.com', 'phone' => '9876543210',
            'address' => 'Test address', 'pincode' => '411001', 'city' => 'Pune', 'state' => 'Maharashtra',
            'subtotal' => 99900, 'shipping' => 0, 'tax' => 17982, 'total' => 99900,
            'razorpay_order_id' => 'order_test1',
        ]);

        $this->postJson('/api/payment/verify', [
            'razorpay_order_id' => 'order_test1',
            'razorpay_payment_id' => 'pay_x',
            'razorpay_signature' => 'invalid',
            'order_id' => 'FR-TEST1',
        ])->assertStatus(422);

        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('payment')->once()->with('pay_x')->andReturn(['id' => 'pay_x', 'order_id' => 'order_test1', 'amount' => 99900, 'currency' => 'INR', 'status' => 'captured']);
        });
        // Captured gateway payment plus the matching signature marks paid.
        $sig = hash_hmac('sha256', 'order_test1|pay_x', (string) config('services.razorpay.key_secret'));
        $this->postJson('/api/payment/verify', [
            'razorpay_order_id' => 'order_test1',
            'razorpay_payment_id' => 'pay_x',
            'razorpay_signature' => $sig,
            'order_id' => 'FR-WRONG-REFERENCE',
        ])->assertNotFound();
        $this->postJson('/api/payment/verify', [
            'razorpay_order_id' => 'order_test1',
            'razorpay_payment_id' => 'pay_x',
            'razorpay_signature' => $sig,
            'order_id' => 'FR-TEST1',
        ])->assertOk()->assertJsonPath('verified', true);

        $this->assertEquals('paid', $order->refresh()->status);

        $this->postJson('/api/orders/track', ['reference' => 'FR-TEST1', 'email' => 't@example.com'])->assertOk()
            ->assertJsonPath('status', 'paid');

        $order->items()->delete();
        $order->delete();
    }

    public function test_reviews_index(): void
    {
        $this->getJson('/api/reviews')->assertOk()->assertJsonCount(4, 'data');
    }
}
