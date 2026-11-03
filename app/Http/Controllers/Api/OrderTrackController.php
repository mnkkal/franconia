<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderTrackController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:160']]);
        $order = ShopOrder::where('reference', $data['reference'])->where('email', strtolower($data['email']))->firstOrFail();

        return response()->json([
            'id' => $order->reference,
            'status' => $order->status,
            'tracking_number' => $order->tracking_number,
            'tracking_link' => $order->tracking_link,
            'updated_at' => $order->updated_at?->toIso8601String(),
        ]);
    }
}
