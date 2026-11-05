<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class PublicMediaTest extends TestCase
{
    use RefreshDatabase;

    private function upload(): Media
    {
        config(['media-library.image_optimizers' => []]);
        Storage::fake('public');
        $this->artisan('franconia:sync-catalogue')->assertSuccessful();

        return Product::first()->addMedia(UploadedFile::fake()->image('bottle.png', 40, 60))
            ->toMediaCollection('images', 'public');
    }

    public function test_original_and_thumbnail_load_without_a_public_storage_symlink(): void
    {
        $media = $this->upload();
        $this->assertFalse(config('media-library.queue_conversions_by_default'));
        $this->assertTrue($media->fresh()->hasGeneratedConversion('small'));
        foreach (['', 'small'] as $conversion) {
            $path = $media->getPathRelativeToRoot($conversion);
            $response = $this->get('/storage/'.$path)->assertOk()->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame(realpath(Storage::disk('public')->path($path)), $response->baseResponse->getFile()->getRealPath());
        }
        $this->getJson('/api/products/noble')->assertOk()->assertJsonPath('data.images.0', $media->getUrl());
    }

    public function test_missing_thumbnail_uses_original_until_regenerated(): void
    {
        $media = $this->upload();
        $small = $media->getPathRelativeToRoot('small');
        Storage::disk('public')->delete($small);
        $response = $this->get('/storage/'.$small)->assertOk();
        $this->assertSame(realpath(Storage::disk('public')->path($media->getPathRelativeToRoot())), $response->baseResponse->getFile()->getRealPath());
        Storage::disk('public')->delete($media->getPathRelativeToRoot());
        $this->get('/storage/'.$small)->assertNotFound();
    }

    public function test_unregistered_files_private_media_and_non_images_are_not_exposed(): void
    {
        $media = $this->upload();
        Storage::disk('public')->put($media->id.'/unregistered.png', 'not public media');
        $this->get('/storage/'.$media->id.'/unregistered.png')->assertNotFound();
        $this->get('/storage/999999/bottle.png')->assertNotFound();
        $this->get('/storage/'.$media->id.'/conversions/unknown.png')->assertNotFound();
        $this->assertContains($this->get('/storage/'.$media->id.'/../../.env')->status(), [403, 404]);
        $media->update(['disk' => 'local']);
        $this->get('/storage/'.$media->getPathRelativeToRoot())->assertNotFound();
        $media->update(['disk' => 'public']);
        Storage::disk('public')->put($media->getPathRelativeToRoot(), '<?php echo "private";');
        $this->get('/storage/'.$media->getPathRelativeToRoot())->assertNotFound();
    }
}
