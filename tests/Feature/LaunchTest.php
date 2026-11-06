<?php

namespace Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\ProductVariantResource\Pages\ManageVariantMedia;
use Lunar\Admin\Models\Staff;
use Lunar\Models\ProductVariant;
use Tests\TestCase;

class LaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_has_free_shipping_and_all_five_products_in_new_arrivals(): void
    {
        $this->artisan('franconia:launch')->assertSuccessful();
        $this->assertSame(0, config('store.shipping_fee'));
        $this->assertSame(0, config('store.shipping_threshold'));
        $this->getJson('/api/collections/new-arrivals')->assertOk()->assertJsonCount(5, 'data.products');
        foreach ($this->getJson('/api/products')->json('data') as $product) {
            $this->assertContains('New Launch', $product['badges']);
        }
    }

    public function test_variant_photo_is_returned_for_20ml_and_not_in_50ml_product_gallery(): void
    {
        Storage::fake('public');
        config(['media-library.image_optimizers' => []]);
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $variant = ProductVariant::where('sku', 'FR-PEA-20')->firstOrFail();
        $media = $variant->product->addMedia(UploadedFile::fake()->image('20ml.png'))
            ->withCustomProperties(['variant_only' => true])->toMediaCollection('images', 'public');
        $variant->images()->attach($media->id, ['primary' => true, 'position' => 1]);
        $this->getJson('/api/products/pearls')->assertOk()->assertJsonCount(0, 'data.images')
            ->assertJsonCount(0, 'data.variants.0.images')->assertJsonPath('data.variants.1.images.0', $media->getUrl());
    }

    public function test_admin_can_upload_a_photo_on_the_variant_media_page(): void
    {
        Storage::fake('public');
        config(['media-library.image_optimizers' => []]);
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();
        $staff = Staff::create(['first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'photo@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));
        $variant = ProductVariant::where('sku', 'FR-PEA-20')->firstOrFail();
        Livewire::test(ManageVariantMedia::class, ['record' => $variant->id, 'product' => $variant->product_id])
            ->callAction(TestAction::make('uploadVariantPhoto')->table(), ['photo' => UploadedFile::fake()->image('20ml.png')])->assertHasNoActionErrors();
        $this->assertSame(1, $variant->images()->count());
        Storage::disk('public')->assertExists($variant->images()->first()->getPathRelativeToRoot());
    }
}
