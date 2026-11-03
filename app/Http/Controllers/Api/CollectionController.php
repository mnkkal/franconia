<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Lunar\Models\Collection;

class CollectionController extends Controller
{
    public function index(): JsonResponse
    {
        $collections = Collection::with(['defaultUrl', 'products.defaultUrl'])->get()->map(fn ($c) => [
            'slug' => $c->defaultUrl?->slug,
            'name' => $c->attribute_data?->get('name')?->getValue(),
            'description' => strip_tags((string) ($c->attribute_data?->get('description')?->getValue() ?? '')),
            'products_count' => $c->products->count(),
        ]);

        return response()->json(['data' => $collections]);
    }

    public function show(string $slug): JsonResponse
    {
        $collection = Collection::whereHas('urls', fn ($q) => $q->where('slug', $slug))
            ->with(['defaultUrl', 'products' => fn ($q) => $q->where('status', 'published'), 'products.defaultUrl', 'products.tags'])
            ->firstOrFail();

        return response()->json(['data' => [
            'slug' => $collection->defaultUrl?->slug,
            'name' => $collection->attribute_data?->get('name')?->getValue(),
            'description' => strip_tags((string) ($collection->attribute_data?->get('description')?->getValue() ?? '')),
            'products' => $collection->products->map(fn ($p) => [
                'slug' => $p->defaultUrl?->slug,
                'name' => $p->attribute_data?->get('name')?->getValue(),
                'badges' => $p->tags->pluck('value')->values(),
            ])->values(),
        ]]);
    }
}
