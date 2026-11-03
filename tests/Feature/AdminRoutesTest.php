<?php

namespace Tests\Feature;

use Database\Seeders\FranconiaBaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Currency;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class AdminRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_routes_have_no_lunar_prefix_and_require_staff_authentication(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/')->assertRedirect('/login');
        $this->get('/products')->assertRedirect('/login');
        $this->get('/lunar/products')->assertNotFound();
        $this->getJson('/api/products')->assertOk();
    }

    public function test_administrator_can_open_the_unprefixed_product_list_and_editor(): void
    {
        $staff = Staff::create(['first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff');
        $this->withoutExceptionHandling();
        $this->get('/products')->assertOk();
        $product = Product::firstOrFail();
        $this->get('/products/'.$product->id.'/edit')->assertOk();
    }

    public function test_base_setup_repairs_missing_default_currency_without_resetting_inventory(): void
    {
        $stock = ProductVariant::orderBy('id')->pluck('stock', 'id')->all();
        $prices = Price::orderBy('id')->get()->map(fn ($price) => $price->getRawOriginal('price'))->all();
        Currency::query()->update(['default' => false, 'enabled' => false]);
        $this->seed(FranconiaBaseSeeder::class);
        $currency = Currency::getDefault();
        $this->assertSame('INR', $currency->code);
        $this->assertTrue($currency->enabled);
        $this->assertSame($stock, ProductVariant::orderBy('id')->pluck('stock', 'id')->all());
        $this->assertSame($prices, Price::orderBy('id')->get()->map(fn ($price) => $price->getRawOriginal('price'))->all());
    }
}
