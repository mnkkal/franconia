<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Receipt {{ $order->reference }}</title>
<meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{font:16px/1.6 sans-serif;max-width:800px;margin:40px auto;padding:20px}table{width:100%;border-collapse:collapse}td,th{padding:10px;border-bottom:1px solid #ddd;text-align:left}@media print{button{display:none}}</style></head><body>
<h1>{{ config('store.business_name') }}</h1>
<p>{{ config('store.business_address') }}<br>GSTIN: {{ config('store.gstin') }}</p>
<h2>Order receipt {{ $order->reference }}</h2><p>{{ $order->created_at->format('d M Y') }} · {{ $order->status }}</p>
<p>{{ $order->name }}<br>{{ $order->address }}<br>{{ $order->city }}, {{ $order->state }} {{ $order->pincode }}</p>
<table><thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead><tbody>
@foreach ($order->items as $item)
<tr><td>{{ $item->product_name }} · {{ $item->variant_label }}</td><td>{{ $item->qty }}</td><td>₹{{ number_format($item->line_total / 100, 2) }}</td></tr>
@endforeach
<tr><td colspan="2">Shipping</td><td>₹{{ number_format($order->shipping / 100, 2) }}</td></tr>
<tr><th colspan="2">Total</th><th>₹{{ number_format($order->total / 100, 2) }}</th></tr>
</tbody></table>
<p>Product prices include GST. Included product GST: ₹{{ number_format($order->tax / 100, 2) }}. This order receipt is not a tax invoice. Contact us for your tax invoice.</p>
<p>{{ config('store.support_email') }} · +91 77578 82971</p>
<button onclick="window.print()">Print receipt</button></body></html>
