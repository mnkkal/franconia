<?php

use App\Http\Controllers\Api\EngagementController;
use App\Models\ShopOrder;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{reference}/receipt', function (string $reference) {
    $order = ShopOrder::with('items')->where('reference', $reference)->whereNotNull('paid_at')->firstOrFail();

    return response()->view('orders.receipt', compact('order'))->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
})->middleware(['signed', 'throttle:30,1'])->name('orders.receipt');

Route::get('/newsletter/confirm/{token}', [EngagementController::class, 'confirm'])
    ->where('token', '[a-zA-Z0-9]{64}')->middleware('throttle:20,1')->name('newsletter.confirm');
Route::get('/newsletter/unsubscribe/{token}', [EngagementController::class, 'unsubscribe'])
    ->where('token', '[a-zA-Z0-9]{64}')->middleware('throttle:20,1')->name('newsletter.unsubscribe');
