<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lunar\Models\Product;

class ReviewController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Review::where('active', true)->latest()->get(),
        ]);
    }

    /**
     * Staff-moderated submission for MVP (stored inactive until approved).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_slug' => ['required', 'string', 'max:80'],
            'author' => ['required', 'string', 'max:120'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $product = Product::where('status', 'published')->whereHas('urls', fn ($q) => $q->where('slug', $data['product_slug']))->first();
        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 422);
        }
        $data['product_name'] = $product->translateAttribute('name');

        $review = Review::create($data + ['active' => false]);

        return response()->json(['data' => $review, 'message' => 'Received — held for admin moderation.'], 201);
    }
}
