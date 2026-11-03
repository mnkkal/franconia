<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\ListProducts;
use Lunar\Admin\Models\Staff;
use Lunar\FieldTypes\Text;
use Lunar\Models\Collection;
use Lunar\Models\Currency;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class CatalogueSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_store_imports_all_five_website_products_and_correct_variants(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->assertSame(5, Product::count());
        $this->assertSame(10, ProductVariant::count());
        $this->assertSame(4, Collection::count());
        $this->assertSame(0, (int) ProductVariant::sum('stock'));
        $this->getJson('/api/products')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/products/noble')->assertOk()
            ->assertJsonPath('data.name', 'NOBLE')->assertJsonPath('data.variants.0.price', 1599)->assertJsonPath('data.variants.1.price', 799);
        $this->assertSame('out-of-stock', ProductVariant::where('sku', 'FR-ELI-50')->value('purchasable'));
        $this->assertTrue(Currency::getDefault()->enabled);
    }

    public function test_rerunning_import_preserves_existing_product_edits_prices_and_stock(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $variant = ProductVariant::where('sku', 'FR-NOB-20')->firstOrFail();
        $variant->update(['stock' => 17]);
        $variant->product->update(['attribute_data' => collect(['name' => new Text('Edited Noble'), 'description' => new Text('Staff description')]), 'status' => 'draft']);
        $variant->prices()->first()->update(['price' => 88800]);
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->assertSame(5, Product::count());
        $this->assertSame(10, ProductVariant::count());
        $this->assertEquals(17, $variant->fresh()->stock);
        $this->assertSame('Edited Noble', $variant->product->fresh()->translateAttribute('name'));
        $this->assertSame('draft', $variant->product->fresh()->status);
        $this->assertEquals(88800, $variant->prices()->first()->getRawOriginal('price'));
    }

    public function test_archived_products_are_not_recreated_or_restored(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        ProductVariant::where('sku', 'FR-NOB-20')->firstOrFail()->product->delete();
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->assertSame(5, Product::withTrashed()->count());
        $this->assertSame(4, Product::count());
    }

    public function test_admin_uses_franconia_branding_and_lists_the_imported_products(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->get('/login')->assertOk()->assertSee('branding/franconia-logo.png', false)->assertSee('Franconia Fine Fragrances');
        $staff = Staff::create(['first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff')->get('/products')->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('lunar'));
        Livewire::test(ListProducts::class)
            ->call('loadTable')->assertCanSeeTableRecords(Product::all());
    }
}
