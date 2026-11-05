<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\ProductResource\Widgets\ProductOptionsWidget;
use Lunar\Admin\Models\Staff;
use Lunar\FieldTypes\Text;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class VariantSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_links_all_ten_variants_to_two_shared_size_values(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->assertSame(1, ProductOption::count());
        $this->assertSame(2, ProductOptionValue::count());
        foreach (Product::with('productOptions', 'variants.values')->get() as $product) {
            $this->assertCount(1, $product->productOptions);
            $this->assertCount(2, $product->variants);
            foreach ($product->variants as $variant) {
                $this->assertCount(1, $variant->values);
                $this->assertSame(str_ends_with($variant->sku, '-50') ? '50ML' : '20ML', $variant->values->first()->translate('name'));
            }
        }
    }

    public function test_repairs_legacy_records_without_recreating_variants_or_resetting_stock_prices(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $product = Product::first();
        $ids = $product->variants()->pluck('id')->all();
        foreach ($product->variants as $variant) {
            $variant->values()->detach();
        }
        $product->productOptions()->detach();
        $product->variants()->first()->update(['stock' => 23]);
        $price = $product->variants()->first()->prices()->first();
        $price->update(['price' => 155500]);

        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $this->assertSame($ids, $product->variants()->pluck('id')->all());
        $this->assertEquals(23, $product->variants()->first()->stock);
        $this->assertEquals(155500, $price->fresh()->getRawOriginal('price'));
        $this->assertSame(10, ProductVariant::count());
        $this->assertSame(1, ProductOption::count());
        $this->assertSame(2, ProductOptionValue::count());
        $this->assertCount(2, $product->fresh()->variants->filter(fn ($v) => $v->values()->count() === 1));
    }

    public function test_lunar_admin_shows_both_existing_variants_and_stock_edits_reach_the_api(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $staff = Staff::create(['first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'variants@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));
        $product = Product::whereHas('urls', fn ($q) => $q->where('slug', 'pearls'))->firstOrFail();
        $ids = $product->variants()->pluck('id')->all();
        $admin = Livewire::test(ProductOptionsWidget::class, ['record' => $product]);
        $variants = $admin->get('variants');
        $this->assertCount(2, $variants);
        $this->assertEqualsCanonicalizing($ids, array_column($variants, 'variant_id'));
        foreach ($variants as $index => $variant) {
            $this->assertContains($variant['values']['Size'], ['50ML', '20ML']);
            $admin->set('variants.'.$index.'.stock', str_ends_with($variant['sku'], '-50') ? 17 : 29);
        }
        $admin->callAction('saveVariants')->assertHasNoActionErrors();
        $this->assertSame($ids, $product->variants()->pluck('id')->all());
        $this->getJson('/api/products/pearls')->assertOk()
            ->assertJsonPath('data.variants.0.label', '50ML')->assertJsonPath('data.variants.0.stock', 17)
            ->assertJsonPath('data.variants.1.label', '20ML')->assertJsonPath('data.variants.1.stock', 29);
    }

    public function test_api_uses_the_same_size_option_as_admin_instead_of_stale_attribute_data(): void
    {
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        ProductVariant::where('sku', 'FR-PEA-50')->firstOrFail()->update([
            'attribute_data' => collect(['size' => new Text('Old label')]),
        ]);
        $this->getJson('/api/products/pearls')->assertOk()->assertJsonPath('data.variants.0.label', '50ML');
    }
}
