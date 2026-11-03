<div class="space-y-3">
<p><strong>{{ $order->reference }}</strong> · {{ $order->status }} · Inventory: {{ $order->inventory_state }}</p>
<p>{{ $order->name }} · {{ $order->email }} · {{ $order->phone }}</p>
<p>{{ $order->address }}, {{ $order->city }}, {{ $order->state }}, {{ $order->pincode }}</p>
@foreach ($order->items as $item)
<p>{{ $item->sku }} · {{ $item->product_name }} {{ $item->variant_label }} × {{ $item->qty }} — ₹{{ number_format($item->line_total / 100, 2) }}</p>
@endforeach
<p>Shipping: ₹{{ number_format($order->shipping / 100, 2) }} · Total: ₹{{ number_format($order->total / 100, 2) }}</p>
<p>Payment: {{ $order->razorpay_payment_id ?? 'Not captured' }} · Refund: {{ $order->refund_id ?? 'None' }} {{ $order->refund_status }}</p>
<p>Tracking: {{ $order->tracking_number }} {{ $order->tracking_link }}</p>
</div>
