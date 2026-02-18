<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            font-size: 12px;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }
        .company-info h1 {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 5px;
        }
        .company-info p {
            color: #666;
            font-size: 12px;
        }
        .invoice-title {
            text-align: right;
        }
        .invoice-title h2 {
            font-size: 36px;
            color: #333;
            margin-bottom: 10px;
        }
        .invoice-title .invoice-number {
            font-size: 14px;
            color: #666;
        }
        .invoice-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }
        .invoice-meta-section {
            width: 45%;
        }
        .invoice-meta-section h4 {
            font-size: 11px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .invoice-meta-section p {
            margin-bottom: 5px;
        }
        .invoice-meta-section .name {
            font-weight: 600;
            font-size: 14px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-paid {
            background-color: #d4edda;
            color: #155724;
        }
        .status-sent {
            background-color: #cce5ff;
            color: #004085;
        }
        .status-draft {
            background-color: #e2e3e5;
            color: #383d41;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th {
            text-align: left;
            padding: 12px 15px;
            background-color: #f8f9fa;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            border-bottom: 2px solid #dee2e6;
            letter-spacing: 0.5px;
        }
        .items-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        .items-table tbody tr:last-child td {
            border-bottom: none;
        }
        .item-description {
            font-weight: 500;
        }
        .item-sku {
            font-size: 11px;
            color: #999;
            margin-top: 3px;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 40px;
        }
        .totals-table {
            width: 300px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        .totals-row.grand-total {
            border-top: 2px solid #333;
            margin-top: 10px;
            padding-top: 15px;
            font-size: 16px;
            font-weight: 700;
        }
        .totals-label {
            color: #666;
        }
        .totals-value {
            font-weight: 500;
        }
        .grand-total .totals-value {
            color: #667eea;
        }
        .discount {
            color: #28a745;
        }
        .payment-info {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .payment-info h4 {
            font-size: 11px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .payment-info p {
            margin-bottom: 5px;
        }
        .notes-section {
            margin-bottom: 30px;
        }
        .notes-section h4 {
            font-size: 11px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .notes-section p {
            color: #666;
            font-style: italic;
        }
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #999;
            font-size: 11px;
        }
        .footer p {
            margin-bottom: 5px;
        }
        @media print {
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .invoice-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <div class="company-info">
                <h1>{{ config('app.name', 'NoovaERP') }}</h1>
                <p>{{ config('app.address', '123 Business Street') }}</p>
                <p>{{ config('app.phone', '') }}</p>
                <p>{{ config('app.email', '') }}</p>
            </div>
            <div class="invoice-title">
                <h2>INVOICE</h2>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
            </div>
        </div>
        
        <div class="invoice-meta">
            <div class="invoice-meta-section">
                <h4>Bill To</h4>
                <p class="name">{{ $customer->name ?? $customer->first_name . ' ' . $customer->last_name }}</p>
                @if (!empty($customer->email))
                <p>{{ $customer->email }}</p>
                @endif
                @if (!empty($customer->phone))
                <p>{{ $customer->phone }}</p>
                @endif
                @if (!empty($customer->billing_address))
                <p>{{ $customer->billing_address['address'] ?? '' }}</p>
                <p>{{ $customer->billing_address['city'] ?? '' }}, {{ $customer->billing_address['state'] ?? '' }} {{ $customer->billing_address['zip'] ?? '' }}</p>
                @endif
            </div>
            <div class="invoice-meta-section" style="text-align: right;">
                <h4>Invoice Details</h4>
                <p><strong>Invoice Date:</strong> {{ $invoice->invoice_date->format('M j, Y') }}</p>
                <p><strong>Due Date:</strong> {{ $invoice->due_date->format('M j, Y') }}</p>
                <p><strong>Status:</strong> <span class="status-badge status-{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span></p>
                @if ($invoice->sent_at)
                <p><strong>Sent:</strong> {{ $invoice->sent_at->format('M j, Y') }}</p>
                @endif
            </div>
        </div>
        
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Description</th>
                    <th class="text-center">Qty</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Tax</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                <tr>
                    <td>
                        <div class="item-description">{{ $line->description }}</div>
                        @if (!empty($line->product?->sku))
                        <div class="item-sku">SKU: {{ $line->product->sku }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ $line->quantity }}</td>
                    <td class="text-right">{{ $currency }}{{ number_format($line->unit_price, 2) }}</td>
                    <td class="text-right">{{ $currency }}{{ number_format($line->tax_amount, 2) }}</td>
                    <td class="text-right">{{ $currency }}{{ number_format($line->line_total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <div class="totals-section">
            <div class="totals-table">
                <div class="totals-row">
                    <span class="totals-label">Subtotal</span>
                    <span class="totals-value">{{ $currency }}{{ number_format($invoice->subtotal, 2) }}</span>
                </div>
                @if ($invoice->discount_amount > 0)
                <div class="totals-row discount">
                    <span class="totals-label">Discount</span>
                    <span class="totals-value">-{{ $currency }}{{ number_format($invoice->discount_amount, 2) }}</span>
                </div>
                @endif
                @if ($invoice->tax_amount > 0)
                <div class="totals-row">
                    <span class="totals-label">Tax</span>
                    <span class="totals-value">{{ $currency }}{{ number_format($invoice->tax_amount, 2) }}</span>
                </div>
                @endif
                <div class="totals-row grand-total">
                    <span class="totals-label">Total</span>
                    <span class="totals-value">{{ $currency }}{{ number_format($invoice->total, 2) }}</span>
                </div>
                @if ($invoice->paid_amount > 0)
                <div class="totals-row" style="color: #28a745;">
                    <span class="totals-label">Amount Paid</span>
                    <span class="totals-value">-{{ $currency }}{{ number_format($invoice->paid_amount, 2) }}</span>
                </div>
                @if ($invoice->balance > 0)
                <div class="totals-row" style="font-weight: 600;">
                    <span class="totals-label">Balance Due</span>
                    <span class="totals-value">{{ $currency }}{{ number_format($invoice->balance, 2) }}</span>
                </div>
                @endif
                @endif
            </div>
        </div>
        
        @if ($invoice->status === 'paid')
        <div class="payment-info">
            <h4>Payment Information</h4>
            <p><strong>Payment Status:</strong> Paid in Full</p>
            <p><strong>Amount Paid:</strong> {{ $currency }}{{ number_format($invoice->paid_amount, 2) }}</p>
            <p><strong>Payment Date:</strong> {{ $invoice->updated_at->format('M j, Y') }}</p>
        </div>
        @endif
        
        @if (!empty($invoice->notes))
        <div class="notes-section">
            <h4>Notes</h4>
            <p>{{ $invoice->notes }}</p>
        </div>
        @endif
        
        @if (!empty($invoice->terms))
        <div class="notes-section">
            <h4>Terms & Conditions</h4>
            <p>{{ $invoice->terms }}</p>
        </div>
        @endif
        
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>{{ config('app.name', 'NoovaERP') }} | {{ config('app.url', '') }}</p>
        </div>
    </div>
</body>
</html>
