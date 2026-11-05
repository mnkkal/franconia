<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\ListProducts;
use Lunar\Admin\Models\Staff;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\Currency;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class CatalogueSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_translated_fields_remain_plain_text_in_storefront_responses(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $variant = ProductVariant::where('sku', 'FR-NOB-50')->firstOrFail();
        $variant->product->update(['attribute_data' => collect([
            'name' => new TranslatedText(['en' => 'NOBLE']),
            'description' => new TranslatedText(['en' => '<p>Updated in admin</p>']),
        ])]);
        $variant->update(['attribute_data' => collect(['size' => new TranslatedText(['en' => '50ML'])])]);
        $collection = Collection::whereHas('urls', fn ($q) => $q->where('slug', 'best-sellers'))->firstOrFail();
        $collection->update(['attribute_data' => collect([
            'name' => new TranslatedText(['en' => 'Best Sellers']),
            'description' => new TranslatedText(['en' => '<p>Our favourites</p>']),
        ])]);

        foreach (['/api/products', '/api/products/noble'] as $url) {
            $prefix = $url === '/api/products' ? 'data.0' : 'data';
            $this->getJson($url)->assertOk()
                ->assertJsonPath($prefix.'.name', 'NOBLE')
                ->assertJsonPath($prefix.'.description', 'Updated in admin')
                ->assertJsonPath($prefix.'.variants.0.label', '50ML');
        }
        $this->getJson('/api/collections')->assertOk()
            ->assertJsonFragment(['name' => 'Best Sellers', 'description' => 'Our favourites']);
        $this->getJson('/api/collections/best-sellers')->assertOk()
            ->assertJsonPath('data.name', 'Best Sellers')->assertJsonPath('data.description', 'Our favourites')
            ->assertJsonPath('data.products.0.name', 'NOBLE');
    }

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
