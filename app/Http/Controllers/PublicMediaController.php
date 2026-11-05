<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    // Shared hosts may not create or follow public/storage symlinks. Serve only
    // registered public image files through Laravel when the web server cannot.
    public function __invoke(string $id, string $file): BinaryFileResponse
    {
        $media = Media::findOrFail($id);
        abort_unless($media->disk === 'public' || $media->conversions_disk === 'public', 404);
        $requested = $id.'/'.$file;
        $path = null;
        $disk = null;

        if ($requested === $media->getPathRelativeToRoot()) {
            $path = $requested;
            $disk = $media->disk;
        } else {
            foreach (ConversionCollection::createForMedia($media) as $conversion) {
                if ($requested === $media->getPathRelativeToRoot($conversion->getName())) {
                    $disk = $media->conversions_disk ?: $media->disk;
                    $path = $requested;
                    // Keep the admin usable while existing thumbnails regenerate.
                    if ($disk === 'public' && ! Storage::disk('public')->exists($path)) {
                        $disk = $media->disk;
                        $path = $media->getPathRelativeToRoot();
                    }
                    break;
                }
            }
        }

        abort_unless($disk === 'public' && $path && config('filesystems.disks.public.driver') === 'local', 404);
        $root = realpath(Storage::disk('public')->path(''));
        $absolute = realpath(Storage::disk('public')->path($path));
        abort_unless($root && $absolute && str_starts_with($absolute, $root.DIRECTORY_SEPARATOR) && is_file($absolute), 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon'], true), 404);

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
