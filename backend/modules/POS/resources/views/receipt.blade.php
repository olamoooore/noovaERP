<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt - {{ $order->order_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            line-height: 1.4;
            padding: 10px;
            max-width: 300px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        .store-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .store-info {
            font-size: 10px;
            color: #333;
        }
        .receipt-info {
            margin-bottom: 15px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        .receipt-info p {
            display: flex;
            justify-content: space-between;
        }
        .receipt-info .label {
            font-weight: bold;
        }
        .items {
            margin-bottom: 15px;
        }
        .items-header {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .item-name {
            flex: 2;
        }
        .item-qty {
            flex: 0.5;
            text-align: center;
        }
        .item-price {
            flex: 1;
            text-align: right;
        }
        .item-total {
            flex: 1;
            text-align: right;
        }
        .totals {
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .total-row.grand-total {
            font-size: 14px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        .payment-info {
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        .footer .thank-you {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .barcode {
            text-align: center;
            margin: 15px 0;
            font-family: 'Libre Barcode 39', cursive;
            font-size: 40px;
        }
        .barcode-text {
            text-align: center;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="store-name">{{ $settings['business_name'] ?? 'NoovaERP Store' }}</div>
        <div class="store-info">
            {{ $settings['business_address'] ?? 'Your Business Address' }}<br>
            Tel: {{ $settings['business_phone'] ?? '+1 234 567 890' }}
        </div>
    </div>

    <div class="receipt-info">
        <p><span class="label">Receipt #:</span> <span>{{ $order->receipt_number ?? $order->order_number }}</span></p>
        <p><span class="label">Date:</span> <span>{{ $order->paid_at ? $order->paid_at->format('M d, Y, h:i A') : $order->created_at->format('M d, Y, h:i A') }}</span></p>
        <p><span class="label">Terminal:</span> <span>{{ $order->terminal_id ?? '-' }}</span></p>
        @if($order->customer)
        <p><span class="label">Customer:</span> <span>{{ $order->customer->name }}</span></p>
        @endif
    </div>

    <div class="items">
        <div class="items-header">
            <span class="item-name">Item</span>
            <span class="item-qty">Qty</span>
            <span class="item-price">Price</span>
            <span class="item-total">Total</span>
        </div>
        @foreach($order->items as $item)
        <div class="item">
            <span class="item-name">{{ $item->product_name ?? $item->product?->name ?? 'Product' }}</span>
            <span class="item-qty">{{ $item->quantity }}</span>
            <span class="item-price">${{ number_format($item->unit_price, 2) }}</span>
            <span class="item-total">${{ number_format($item->line_total, 2) }}</span>
        </div>
        @endforeach
    </div>

    <div class="totals">
        <div class="total-row">
            <span>Subtotal ({{ $order->items->sum('quantity') }} items)</span>
            <span>${{ number_format($order->subtotal, 2) }}</span>
        </div>
        @if($order->tax_amount > 0)
        <div class="total-row">
            <span>Tax</span>
            <span>${{ number_format($order->tax_amount, 2) }}</span>
        </div>
        @endif
        @if($order->discount_amount > 0)
        <div class="total-row">
            <span>Discount</span>
            <span>-${{ number_format($order->discount_amount, 2) }}</span>
        </div>
        @endif
        <div class="total-row grand-total">
            <span>TOTAL</span>
            <span>${{ number_format($order->total, 2) }}</span>
        </div>
    </div>

    <div class="payment-info">
        @foreach($order->payments as $payment)
        @php
            // Extract gateway name from notes (e.g., "Online payment via Stripe")
            $displayMethod = $payment->method;
            if ($payment->notes && preg_match('/via\s+(.+)$/i', $payment->notes, $matches)) {
                $displayMethod = $matches[1];
            }
        @endphp
        <div class="total-row">
            <span>Payment Method:</span>
            <span>{{ strtoupper($displayMethod) }}</span>
        </div>
        <div class="total-row">
            <span>Amount Tendered:</span>
            <span>${{ number_format($payment->amount, 2) }}</span>
        </div>
        @endforeach
        @if($order->payments->isEmpty())
        <div class="total-row">
            <span>Payment Method:</span>
            <span>{{ strtoupper($order->status === 'paid' ? 'CARD' : 'N/A') }}</span>
        </div>
        @endif
    </div>

    <div class="barcode">
        *{{ $order->order_number }}*
    </div>
    <div class="barcode-text">{{ $order->order_number }}</div>

    <div class="footer">
        <div class="thank-you">Thank you for your purchase!</div>
        <p>Thank you for shopping with us!</p>
        <p style="margin-top: 10px; font-size: 10px;">Powered by NoovaERP</p>
    </div>
</body>
</html>
