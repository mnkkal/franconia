<?php

namespace Tests\Feature;

use App\Services\ReplaceMediaImage;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\ProductResource\Pages\ManageProductMedia;
use Lunar\Admin\Models\Staff;
use Lunar\Admin\Support\RelationManagers\MediaRelationManager;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaEditorTest extends TestCase
{
    use RefreshDatabase;

    private function media(): Media
    {
        config(['media-library.image_optimizers' => []]);
        Storage::fake('public');
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();

        return Product::first()->addMedia(UploadedFile::fake()->image('old.png', 40, 60))
            ->withCustomProperties(['name' => 'Front photo', 'primary' => true, 'other' => 'preserved'])
            ->toMediaCollection('images', 'public');
    }

    private function editor(Media $media)
    {
        $staff = Staff::create(['first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'editor@example.com', 'password' => bcrypt('test-password'), 'admin' => true]);
        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));

        return Livewire::test(MediaRelationManager::class, [
            'ownerRecord' => $media->model, 'pageClass' => ManageProductMedia::class, 'mediaCollection' => 'images',
        ])->call('loadTable');
    }

    public function test_replacement_keeps_product_primary_position_and_variant_links(): void
    {
        $original = $this->media();
        $oldPath = $original->getPathRelativeToRoot();
        $original->update(['order_column' => 3]);
        $variant = $original->model->variants()->first();
        $variant->images()->attach($original->id, ['primary' => true, 'position' => 2]);
        $replacement = app(ReplaceMediaImage::class)->replace($original, UploadedFile::fake()->image('new.png', 80, 100), $original->custom_properties);

        $this->assertFalse(Media::whereKey($original->id)->exists());
        $this->assertSame($original->model_id, $replacement->model_id);
        $this->assertSame('images', $replacement->collection_name);
        $this->assertSame(3, $replacement->order_column);
        $this->assertTrue($replacement->getCustomProperty('primary'));
        $this->assertSame('Front photo', $replacement->getCustomProperty('name'));
        $this->assertSame($replacement->id, $variant->images()->first()->id);
        $this->assertSame(2, $variant->images()->first()->pivot->position);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($replacement->getPathRelativeToRoot());
        $this->assertTrue($replacement->fresh()->hasGeneratedConversion('small'));
        $this->getJson('/api/products/noble')->assertOk()->assertJsonPath('data.images.0', $replacement->getUrl());
    }

    public function test_edit_form_exposes_optional_image_replacement_and_preserves_file_for_name_only_edits(): void
    {
        $media = $this->media();
        $path = $media->getPathRelativeToRoot();
        $this->editor($media)->mountAction(TestAction::make('edit')->table($media))
            ->assertFormFieldExists('replacement_image', fn (FileUpload $field) => ! $field->isHidden() && ! $field->isRequired())
            ->fillForm(['custom_properties' => ['name' => 'Updated label', 'primary' => true]])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame('Updated label', $media->fresh()->getCustomProperty('name'));
        $this->assertSame('preserved', $media->fresh()->getCustomProperty('other'));
        $this->assertSame(1, Media::count());
        Storage::disk('public')->assertExists($path);
    }

    public function test_edit_action_replaces_file_through_the_upload_field(): void
    {
        $media = $this->media();
        $this->editor($media)->callAction(TestAction::make('edit')->table($media), [
            'custom_properties' => ['name' => 'New photo', 'primary' => true],
            'replacement_image' => UploadedFile::fake()->image('replacement.png', 60, 80),
        ])->assertHasNoActionErrors();
        $replacement = Media::firstOrFail();
        $this->assertNotEquals($media->id, $replacement->id);
        $this->assertSame(1, Media::count());
        $this->assertSame('New photo', $replacement->getCustomProperty('name'));
        Storage::disk('public')->assertExists($replacement->getPathRelativeToRoot());
    }

    public function test_invalid_replacement_is_rejected_without_removing_the_original(): void
    {
        $media = $this->media();
        $this->editor($media)->callAction(TestAction::make('edit')->table($media), [
            'replacement_image' => UploadedFile::fake()->create('document.txt', 1, 'text/plain'),
        ])->assertHasActionErrors(['replacement_image']);
        $this->assertSame(1, Media::count());
        $this->assertTrue(Media::whereKey($media->id)->exists());
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }
}
