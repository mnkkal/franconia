<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\OrderTrackController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

Route::get('/collections', [CollectionController::class, 'index']);
Route::get('/collections/{slug}', [CollectionController::class, 'show']);

Route::post('/cart', [CartController::class, 'store']);
Route::get('/cart/{cart}', [CartController::class, 'show']);
Route::patch('/cart/{cart}', [CartController::class, 'update']);
Route::delete('/cart/{cart}/items/{item}', [CartController::class, 'destroyItem']);

Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:store-checkout');
Route::post('/payment/verify', [PaymentController::class, 'verify'])->middleware('throttle:30,1');
Route::post('/payment/webhook', [PaymentController::class, 'webhook'])->withoutMiddleware('auth:sanctum');

Route::post('/orders/track', [OrderTrackController::class, 'show'])->middleware('throttle:20,1');

Route::get('/reviews', [ReviewController::class, 'index']);
Route::post('/reviews', [ReviewController::class, 'store'])->middleware('throttle:5,1');
Route::post('/contact', [EngagementController::class, 'contact'])->middleware('throttle:5,1');
Route::post('/newsletter', [EngagementController::class, 'subscribe'])->middleware('throttle:3,1');

Route::get('/store-info', fn () => response()->json(['data' => [
    'business_address' => config('store.business_address'),
    'grievance_officer' => config('store.grievance_officer'),
    'grievance_email' => config('store.grievance_email'),
    'grievance_phone' => config('store.grievance_phone'),
]]));
