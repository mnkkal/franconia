<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class ElixirReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_preserves_stock_and_prices_and_allows_checkout(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $large = ProductVariant::where('sku', 'FR-ELI-50')->firstOrFail();
        $small = ProductVariant::where('sku', 'FR-ELI-20')->firstOrFail();
        $large->update(['stock' => 130]);
        $small->update(['stock' => 190]);
        $price = $large->prices()->first()->getRawOriginal('price');
        $this->getJson('/api/products/elixir')->assertJsonPath('data.variants.0.stock', 0);
        $this->artisan('franconia:release-elixir')->assertSuccessful();
        $this->artisan('franconia:release-elixir')->assertSuccessful();
        $this->getJson('/api/products/elixir')->assertOk()->assertJsonPath('data.variants.0.stock', 130)->assertJsonPath('data.variants.1.stock', 190);
        $this->assertSame($price, $large->prices()->first()->getRawOriginal('price'));
        $this->assertFalse($large->product->tags()->where('value', 'COMING SOON')->exists());
        Http::preventStrayRequests();
        Http::fake(['api.razorpay.com/v1/orders' => fn ($request) => Http::response(['id' => 'order_elixir_fixture', 'amount' => $request['amount'], 'currency' => 'INR'])]);
        $this->postJson('/api/checkout', [
            'items' => [['sku' => 'FR-ELI-50', 'qty' => 1]],
            'name' => 'Test Buyer', 'email' => 'buyer@example.com', 'phone' => '9876543210',
            'address' => '123 Test Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001',
        ])->assertCreated();
        $this->assertEquals(129, $large->fresh()->stock);
        $this->assertEquals(190, $small->fresh()->stock);
        $small->update(['stock' => 0]);
        $this->getJson('/api/products/elixir')->assertJsonPath('data.variants.1.stock', 0);
    }
}
