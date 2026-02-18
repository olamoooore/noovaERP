<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation - {{ $order->order_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-wrapper {
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
        }
        .order-info {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .order-info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .order-info-row:last-child {
            margin-bottom: 0;
        }
        .order-info-label {
            color: #666;
            font-size: 14px;
        }
        .order-info-value {
            font-weight: 600;
            color: #333;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-confirmed {
            background-color: #d4edda;
            color: #155724;
        }
        .status-paid {
            background-color: #cce5ff;
            color: #004085;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        .items-table th {
            text-align: left;
            padding: 12px 10px;
            background-color: #f8f9fa;
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
            border-bottom: 2px solid #e9ecef;
        }
        .items-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        .item-image {
            width: 60px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
            background-color: #f8f9fa;
        }
        .item-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        .item-sku {
            font-size: 12px;
            color: #999;
        }
        .item-price {
            color: #666;
        }
        .item-quantity {
            text-align: center;
        }
        .item-total {
            font-weight: 600;
            text-align: right;
        }
        .discount-row {
            color: #28a745;
        }
        .totals-section {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .totals-row:last-child {
            margin-bottom: 0;
        }
        .totals-label {
            color: #666;
        }
        .totals-value {
            font-weight: 500;
        }
        .grand-total {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            border-top: 2px solid #dee2e6;
            padding-top: 15px;
            margin-top: 10px;
        }
        .grand-total .totals-value {
            color: #667eea;
        }
        .shipping-address {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .shipping-address h4 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .address-text {
            color: #333;
            line-height: 1.8;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 25px 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .footer-logo {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
        }
        .loyalty-section {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .loyalty-section h4 {
            font-size: 14px;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        .loyalty-points {
            font-size: 24px;
            font-weight: 700;
        }
        .thank-you {
            text-align: center;
            padding: 20px 0;
        }
        .thank-you h3 {
            font-size: 20px;
            color: #333;
            margin-bottom: 10px;
        }
        .thank-you p {
            color: #666;
        }
        @media only screen and (max-width: 600px) {
            .container {
                padding: 10px;
            }
            .content {
                padding: 20px;
            }
            .items-table th,
            .items-table td {
                padding: 10px 5px;
                font-size: 13px;
            }
            .item-image {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-wrapper">
            <div class="header">
                <h1>Order Confirmed!</h1>
                <p>Thank you for shopping with {{ config('app.name', 'NoovaERP') }}</p>
            </div>
            
            <div class="content">
                <p class="greeting">Hello {{ $customerName }},</p>
                
                <p style="margin-bottom: 25px; color: #666;">
                    Your order has been confirmed and will be shipped soon. Below are your order details:
                </p>
                
                <div class="order-info">
                    <div class="order-info-row">
                        <span class="order-info-label">Order Number</span>
                        <span class="order-info-value">{{ $order->order_number }}</span>
                    </div>
                    <div class="order-info-row">
                        <span class="order-info-label">Order Date</span>
                        <span class="order-info-value">{{ $order->created_at->format('F j, Y \a\t g:i A') }}</span>
                    </div>
                    <div class="order-info-row">
                        <span class="order-info-label">Payment Status</span>
                        <span class="status-badge status-paid">{{ ucfirst($order->payment_status ?? 'paid') }}</span>
                    </div>
                    <div class="order-info-row">
                        <span class="order-info-label">Order Status</span>
                        <span class="status-badge status-confirmed">{{ ucfirst($order->status ?? 'confirmed') }}</span>
                    </div>
                </div>
                
                <h3 class="section-title">Order Items</h3>
                
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Image</th>
                            <th>Product</th>
                            <th style="text-align: center;">Qty</th>
                            <th style="text-align: right;">Price</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                        <tr>
                            <td>
                                @if ($item->product_image)
                                <img src="{{ $item->product_image }}" alt="{{ $item->product_name }}" class="item-image">
                                @else
                                <div class="item-image" style="display: flex; align-items: center; justify-content: center; background: #e9ecef; color: #999; font-size: 10px;">No Image</div>
                                @endif
                            </td>
                            <td>
                                <div class="item-name">{{ $item->product_name }}</div>
                                @if ($item->product_sku)
                                <div class="item-sku">SKU: {{ $item->product_sku }}</div>
                                @endif
                                <div class="item-price">{{ $currency }}{{ number_format($item->unit_price, 2) }} each</div>
                            </td>
                            <td class="item-quantity">{{ $item->quantity }}</td>
                            <td class="item-total">{{ $currency }}{{ number_format($item->unit_price, 2) }}</td>
                            <td class="item-total">{{ $currency }}{{ number_format($item->line_total, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                
                <div class="totals-section">
                    <div class="totals-row">
                        <span class="totals-label">Subtotal</span>
                        <span class="totals-value">{{ $currency }}{{ number_format($order->subtotal, 2) }}</span>
                    </div>
                    
                    @if ($order->discount_amount > 0)
                    <div class="totals-row discount-row">
                        <span class="totals-label">Discount</span>
                        <span class="totals-value">-{{ $currency }}{{ number_format($order->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    
                    @if ($order->loyalty_discount > 0)
                    <div class="totals-row discount-row">
                        <span class="totals-label">Loyalty Discount</span>
                        <span class="totals-value">-{{ $currency }}{{ number_format($order->loyalty_discount, 2) }}</span>
                    </div>
                    @endif
                    
                    @if ($order->shipping_cost > 0)
                    <div class="totals-row">
                        <span class="totals-label">Shipping</span>
                        <span class="totals-value">{{ $currency }}{{ number_format($order->shipping_cost, 2) }}</span>
                    </div>
                    @endif
                    
                    @if ($order->tax_amount > 0)
                    <div class="totals-row">
                        <span class="totals-label">Tax</span>
                        <span class="totals-value">{{ $currency }}{{ number_format($order->tax_amount, 2) }}</span>
                    </div>
                    @endif
                    
                    <div class="totals-row grand-total">
                        <span class="totals-label">Total</span>
                        <span class="totals-value">{{ $currency }}{{ number_format($order->total, 2) }}</span>
                    </div>
                </div>
                
                @if ($order->loyalty_points_earned > 0)
                <div class="loyalty-section">
                    <h4>Loyalty Points Earned</h4>
                    <div class="loyalty-points">+{{ $order->loyalty_points_earned }} Points</div>
                    <p style="font-size: 12px; margin-top: 8px; opacity: 0.9;">
                        @if ($order->loyalty_points_redeemed > 0)
                        You redeemed {{ $order->loyalty_points_redeemed }} points and earned {{ $order->loyalty_points_earned }} new points!
                        @else
                        Keep shopping to earn more rewards!
                        @endif
                    </p>
                </div>
                @endif
                
                @if ($shippingAddress)
                <div class="shipping-address">
                    <h4>Shipping Address</h4>
                    <div class="address-text">
                        {{ $shippingAddress['name'] ?? $customerName }}<br>
                        {{ $shippingAddress['address'] ?? '' }}<br>
                        @if (!empty($shippingAddress['address2']))
                        {{ $shippingAddress['address2'] }}<br>
                        @endif
                        {{ $shippingAddress['city'] ?? '' }}, {{ $shippingAddress['state'] ?? '' }} {{ $shippingAddress['zip'] ?? '' }}<br>
                        {{ $shippingAddress['country'] ?? '' }}
                        @if (!empty($shippingAddress['phone']))
                        <br>Phone: {{ $shippingAddress['phone'] }}
                        @endif
                    </div>
                </div>
                @endif
                
                <div class="thank-you">
                    <h3>Thank You for Your Order!</h3>
                    <p>We will notify you when your order has been shipped.</p>
                </div>
            </div>
            
            <div class="footer">
                <div class="footer-logo">{{ config('app.name', 'NoovaERP') }}</div>
                <p>If you have any questions, please <a href="mailto:{{ config('mail.from.address') }}">contact us</a></p>
                <p style="font-size: 12px; color: #999;">
                    This email was sent to {{ $customerEmail }} regarding order {{ $order->order_number }}.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
