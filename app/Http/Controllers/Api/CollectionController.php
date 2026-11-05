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
            'name' => $c->translateAttribute('name'),
            'description' => strip_tags((string) ($c->translateAttribute('description') ?? '')),
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
            'name' => $collection->translateAttribute('name'),
            'description' => strip_tags((string) ($collection->translateAttribute('description') ?? '')),
            'products' => $collection->products->map(fn ($p) => [
                'slug' => $p->defaultUrl?->slug,
                'name' => $p->translateAttribute('name'),
                'badges' => $p->tags->pluck('value')->values(),
            ])->values(),
        ]]);
    }
}
