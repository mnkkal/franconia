<?php

namespace Tests\Feature;

use App\Filament\Resources\StoreOrderResource;
use App\Filament\Resources\StoreOrderResource\Pages\ListStoreOrders;
use App\Filament\Widgets\WebsiteOrders;
use App\Filament\Widgets\WebsiteOrderStats;
use App\Mail\OrderUpdate;
use App\Models\ShopOrder;
use App\Services\OrderLifecycle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function payload(array $items = [['sku' => 'FR-NOB-20', 'qty' => 2]]): array
    {
        return ['items' => $items, 'name' => 'Test Buyer', 'email' => 'buyer@example.com', 'phone' => '9876543210',
            'address' => '123 Test Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'];
    }

    private function checkout(array $items = [['sku' => 'FR-NOB-20', 'qty' => 2]]): ShopOrder
    {
        Http::fake(['api.razorpay.com/v1/orders' => fn ($request) => Http::response([
            'id' => 'order_'.uniqid(), 'amount' => $request['amount'], 'currency' => 'INR',
        ])]);
        $response = $this->postJson('/api/checkout', $this->payload($items))->assertCreated();

        return ShopOrder::where('reference', $response->json('order_id'))->firstOrFail();
    }

    private function payment(ShopOrder $order, array $extra = []): array
    {
        return array_replace(['id' => 'pay_fixture', 'order_id' => $order->razorpay_order_id, 'amount' => $order->total, 'currency' => 'INR', 'status' => 'captured'], $extra);
    }

    private function webhook(array $payment)
    {
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $payment]]]);

        return $this->call('POST', '/api/payment/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'webhook-secret'),
        ], $body);
    }

    public function test_checkout_reserves_exact_stock_and_charges_mixed_bundle_total(): void
    {
        $before = ProductVariant::where('sku', 'FR-NOB-20')->value('stock');
        $order = $this->checkout([['sku' => 'FR-NOB-20', 'qty' => 1], ['sku' => 'FR-BLA-20', 'qty' => 2], ['sku' => 'FR-NOB-50', 'qty' => 2]]);
        $this->assertSame(509800, (int) $order->total);
        $this->assertSame('reserved', $order->inventory_state);
        $this->assertEquals($before - 1, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        $this->assertEquals($order->subtotal, $order->items->sum('line_total'));
    }

    public function test_orders_menu_dashboard_and_old_orders_link_use_the_same_website_orders(): void
    {
        $order = $this->checkout();
        app(OrderLifecycle::class)->captured($order, $this->payment($order));
        $staff = Staff::create(['first_name' => 'Menu', 'last_name' => 'Admin', 'email' => 'menu@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));
        $items = collect(Filament::getCurrentPanel()->buildNavigation())->flatMap(fn ($group) => $group->getItems());
        $orders = $items->filter(fn ($item) => $item->getLabel() === 'Orders');
        $this->assertCount(1, $orders);
        $this->assertSame(StoreOrderResource::getUrl('index'), $orders->first()->getUrl());
        $this->assertFalse($items->contains(fn ($item) => $item->getLabel() === 'Storefront orders'));
        $this->get('/orders')->assertRedirect(StoreOrderResource::getUrl('index'));
        $this->get('/')->assertOk();
        Livewire::test(WebsiteOrders::class)->assertCanSeeTableRecords([$order]);
        Livewire::test(WebsiteOrderStats::class)->assertSee('Paid sales');
    }

    public function test_single_20ml_launch_order_is_free_shipping_visible_in_admin_and_emails_after_capture(): void
    {
        $order = $this->checkout([['sku' => 'FR-NOB-20', 'qty' => 1]]);
        $this->assertEquals(0, $order->shipping);
        $this->assertEquals(79900, $order->total);
        $staff = Staff::create(['first_name' => 'Launch', 'last_name' => 'Admin', 'email' => 'launch@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));
        Livewire::test(ListStoreOrders::class)->assertCanSeeTableRecords([$order]);
        $this->webhook($this->payment($order))->assertOk();
        Http::fake(['api.razorpay.com/v1/orders/*/payments' => Http::response(['items' => []])]);
        $this->artisan('franconia:maintain')->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertSent(OrderUpdate::class, fn ($mail) => $mail->hasTo($order->email));
    }

    public function test_duplicate_skus_cannot_bypass_stock_check_and_failed_gateway_releases_stock(): void
    {
        ProductVariant::where('sku', 'FR-NOB-20')->update(['stock' => 2]);
        $this->postJson('/api/checkout', $this->payload([['sku' => 'FR-NOB-20', 'qty' => 2], ['sku' => 'FR-NOB-20', 'qty' => 1]]))->assertUnprocessable();
        Http::fake(['api.razorpay.com/*' => Http::response(['error' => 'upstream failed'], 500)]);
        $this->postJson('/api/checkout', $this->payload())->assertStatus(502);
        $this->assertEquals(2, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        $this->assertSame('released', ShopOrder::first()->inventory_state);
    }

    public function test_second_checkout_cannot_sell_reserved_stock(): void
    {
        ProductVariant::where('sku', 'FR-NOB-20')->update(['stock' => 2]);
        $this->checkout();
        $this->postJson('/api/checkout', $this->payload())->assertUnprocessable();
        $this->assertEquals(0, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
    }

    public function test_signed_webhook_is_idempotent_and_wrong_amount_is_rejected(): void
    {
        $order = $this->checkout();
        $remaining = ProductVariant::where('sku', 'FR-NOB-20')->value('stock');
        $this->webhook($this->payment($order, ['amount' => 1]))->assertUnprocessable();
        $this->assertNull($order->fresh()->paid_at);
        $this->webhook($this->payment($order))->assertOk();
        $this->webhook($this->payment($order))->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertEquals($remaining, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        $this->assertEquals(1, DB::table('store_notifications')->where('event', 'paid')->count());
    }

    public function test_callback_does_not_confirm_an_authorized_payment(): void
    {
        $order = $this->checkout();
        Http::fake(['api.razorpay.com/v1/payments/pay_fixture' => Http::response($this->payment($order, ['status' => 'authorized']))]);
        $signature = hash_hmac('sha256', $order->razorpay_order_id.'|pay_fixture', 'test-secret');
        $this->postJson('/api/payment/verify', ['order_id' => $order->reference, 'razorpay_order_id' => $order->razorpay_order_id,
            'razorpay_payment_id' => 'pay_fixture', 'razorpay_signature' => $signature])->assertStatus(202)->assertJsonPath('verified', false);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_expiry_restores_once_and_late_capture_without_stock_needs_review(): void
    {
        $order = $this->checkout();
        $lifecycle = app(OrderLifecycle::class);
        $lifecycle->release($order);
        $lifecycle->release($order);
        $this->assertEquals(65, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        ProductVariant::where('sku', 'FR-NOB-20')->update(['stock' => 0]);
        $this->webhook($this->payment($order))->assertOk();
        $this->assertSame('payment_review', $order->fresh()->status);
        $this->assertEquals(0, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
    }

    public function test_maintenance_reconciles_capture_when_browser_and_webhook_are_missing(): void
    {
        $order = $this->checkout();
        $order->update(['reservation_expires_at' => now()->subMinute()]);
        Http::fake(['api.razorpay.com/v1/orders/*/payments' => Http::response(['items' => [$this->payment($order)]])]);
        $this->artisan('franconia:maintain')->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->status);
        Mail::assertSent(OrderUpdate::class, 1);
        $this->assertNotNull(DB::table('store_notifications')->value('sent_at'));
    }

    public function test_maintenance_releases_expired_unpaid_order(): void
    {
        $order = $this->checkout();
        $order->update(['reservation_expires_at' => now()->subMinute()]);
        Http::fake(['api.razorpay.com/v1/orders/*/payments' => Http::response(['items' => []])]);
        $this->artisan('franconia:maintain')->assertSuccessful();
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertEquals(65, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
    }

    public function test_refund_is_idempotent_and_restores_stock_only_once(): void
    {
        $order = $this->checkout();
        $lifecycle = app(OrderLifecycle::class);
        $order = $lifecycle->captured($order, $this->payment($order));
        $refund = ['id' => 'rfnd_fixture', 'payment_id' => 'pay_fixture', 'amount' => $order->total, 'status' => 'processed'];
        Http::fake(['api.razorpay.com/v1/payments/pay_fixture/refund' => Http::response($refund), 'api.razorpay.com/v1/refunds/rfnd_fixture' => Http::response($refund)]);
        $lifecycle->requestRefund($order);
        $lifecycle->syncRefund($order->fresh());
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertEquals(65, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request->hasHeader('X-Refund-Idempotency'));
    }

    public function test_receipt_is_signed_and_fulfilment_queues_email(): void
    {
        $order = $this->checkout();
        $lifecycle = app(OrderLifecycle::class);
        $order = $lifecycle->captured($order, $this->payment($order));
        $lifecycle->fulfil($order, 'shipped', 'TEST-123', 'https://courier.example.com/TEST-123');
        $url = URL::temporarySignedRoute('orders.receipt', now()->addHour(), ['reference' => $order->reference]);
        $this->get($url)->assertOk()->assertSee('Order receipt')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/orders/'.$order->reference.'/receipt')->assertForbidden();
        $this->assertEquals(1, DB::table('store_notifications')->where('event', 'shipped')->count());
        $this->assertStringContainsString('Track your parcel', (new OrderUpdate($order->fresh(), 'shipped'))->render());
    }

    public function test_only_administrator_staff_can_access_storefront_orders(): void
    {
        $staff = Staff::create(['first_name' => 'Test', 'last_name' => 'Staff', 'email' => 'staff@example.com', 'password' => bcrypt('test-password'), 'admin' => false]);
        $this->actingAs($staff, 'staff');
        $this->get(StoreOrderResource::getUrl('index', panel: 'lunar'))->assertForbidden();
        $staff->update(['admin' => true]);
        $this->get(StoreOrderResource::getUrl('index', panel: 'lunar'))->assertOk()->assertSee('Orders');
    }

    public function test_price_update_preserves_stock_and_existing_orders(): void
    {
        $order = $this->checkout();
        $this->artisan('franconia:update-prices')->assertSuccessful();
        $this->assertEquals(63, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        $this->assertEquals(149900, $order->fresh()->total);
        $this->getJson('/api/products/noble')->assertOk()->assertJsonPath('data.variants.1.price', 799);
    }

    public function test_cancelled_order_capture_needs_review_and_tracking_requires_matching_email(): void
    {
        $order = $this->checkout();
        app(OrderLifecycle::class)->release($order, 'cancelled');
        $this->webhook($this->payment($order))->assertOk();
        $this->assertSame('payment_review', $order->fresh()->status);
        $this->assertEquals(65, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        $this->postJson('/api/orders/track', ['reference' => $order->reference, 'email' => 'wrong@example.com'])->assertNotFound();
        $this->postJson('/api/orders/track', ['reference' => $order->reference, 'email' => 'buyer@example.com'])->assertOk()->assertJsonPath('status', 'payment_review');
    }

    public function test_failed_refund_can_be_retried_with_a_new_idempotency_key(): void
    {
        $order = $this->checkout();
        $lifecycle = app(OrderLifecycle::class);
        $order = $lifecycle->captured($order, $this->payment($order));
        Http::fake(['api.razorpay.com/v1/payments/pay_fixture/refund' => Http::sequence()
            ->push(['id' => 'rfnd_failed', 'payment_id' => 'pay_fixture', 'amount' => $order->total, 'status' => 'failed'])
            ->push(['id' => 'rfnd_success', 'payment_id' => 'pay_fixture', 'amount' => $order->total, 'status' => 'processed'])]);
        $lifecycle->requestRefund($order);
        $this->assertSame('refund_failed', $order->fresh()->status);
        $this->assertEquals(63, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
        $lifecycle->requestRefund($order->fresh());
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(2, $order->fresh()->refund_attempt);
        $this->assertEquals(65, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
    }

    public function test_refunding_shipped_goods_does_not_restock_unreturned_products(): void
    {
        $order = $this->checkout();
        $lifecycle = app(OrderLifecycle::class);
        $order = $lifecycle->captured($order, $this->payment($order));
        $lifecycle->fulfil($order, 'shipped', 'TEST', 'https://courier.example.com/TEST');
        Http::fake(['api.razorpay.com/v1/payments/pay_fixture/refund' => Http::response([
            'id' => 'rfnd_fixture', 'payment_id' => 'pay_fixture', 'amount' => $order->total, 'status' => 'processed',
        ])]);
        $lifecycle->requestRefund($order->fresh(), false, 'Damaged in transit');
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertEquals(63, ProductVariant::where('sku', 'FR-NOB-20')->value('stock'));
    }
}
