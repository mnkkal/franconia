<h1>Franconia Fine Fragrances</h1>
<p>Hello {{ $order->name }},</p>
@if ($event === 'payment_review')
<p>We received your payment for {{ $order->reference }}. Your order needs a stock review before we can confirm fulfilment. Our team will contact you to arrange fulfilment or a refund.</p>
@else
<p>Your order {{ $order->reference }} is now {{ str_replace('_', ' ', $event) }}.</p>
@endif
<table cellpadding="8">
@foreach ($order->items as $item)
<tr><td>{{ $item->product_name }} · {{ $item->variant_label }} × {{ $item->qty }}</td><td>₹{{ number_format($item->line_total / 100, 2) }}</td></tr>
@endforeach
<tr><td>Shipping</td><td>₹{{ number_format($order->shipping / 100, 2) }}</td></tr>
<tr><th>Total (GST included)</th><th>₹{{ number_format($order->total / 100, 2) }}</th></tr>
</table>
@if ($order->tracking_number)
<p>Courier tracking: {{ $order->tracking_number }}. <a href="{{ $order->tracking_link }}">Track your parcel</a></p>
@endif
<p><a href="{{ $receiptUrl }}">View or print your order receipt</a> (link expires in 30 days).</p>
<p>Support: {{ config('store.support_email') }} · +91 77578 82971</p>
