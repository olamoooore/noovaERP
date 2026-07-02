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
            font-size: 11px;
            line-height: 1.35;
            padding: 8px;
            max-width: 306px;
            margin: 0 auto;
            color: #000;
        }
        .receipt {
            width: 100%;
        }
        .center {
            text-align: center;
        }
        .header {
            padding-bottom: 6px;
        }
        .store-name {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .store-line {
            font-size: 10px;
            margin-bottom: 2px;
        }
        .store-logo-wrap {
            margin-bottom: 6px;
        }
        .store-logo {
            max-height: 46px;
            max-width: 170px;
            width: auto;
            height: auto;
        }
        .copy-label {
            font-weight: bold;
            margin-top: 4px;
            font-size: 11px;
        }
        .line {
            border-bottom: 1px dashed #000;
            margin: 8px 0;
        }
        .receipt-code {
            margin-bottom: 6px;
            font-weight: bold;
        }
        .row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 2px;
        }
        .row.bold {
            font-weight: bold;
        }
        .item-block {
            margin-bottom: 5px;
        }
        .item-main {
            font-size: 11px;
        }
        .item-sub {
            font-size: 10px;
            color: #111;
            padding-left: 2px;
        }
        .term-date {
            margin-top: 2px;
        }
        .footer-msg {
            margin-bottom: 1px;
        }
        .qr-wrap {
            margin-top: 8px;
            margin-bottom: 4px;
        }
        .qr-wrap img {
            width: 108px;
            height: 108px;
            display: inline-block;
        }
        .tiny-note {
            font-size: 9px;
            margin-top: 2px;
        }
        .truncate {
            max-width: 220px;
            word-break: break-word;
        }
        .amount-col {
            min-width: 72px;
            text-align: right;
        }
    </style>
</head>
<body>
    @php
        $businessAddresses = $settings['business_addresses'] ?? [];
        if (empty($businessAddresses) && !empty($settings['business_address'])) {
            $businessAddresses = [$settings['business_address']];
        }

        $businessPhones = $settings['business_phones'] ?? [];
        if (empty($businessPhones) && !empty($settings['business_phone'])) {
            $businessPhones = [$settings['business_phone']];
        }

        $socialLinks = collect($settings['social_links'] ?? [])->filter(fn ($value) => !empty($value));

        $whatsAppValue = trim((string) ($socialLinks->get('whatsapp') ?? ($businessPhones[0] ?? '')));
        $whatsAppDigits = preg_replace('/[^0-9]/', '', $whatsAppValue);
        $qrPayload = $whatsAppValue;
        if ($whatsAppDigits) {
            $qrPayload = 'https://wa.me/' . $whatsAppDigits;
        }
        $qrUrl = 'https://quickchart.io/qr?size=120&text=' . rawurlencode($qrPayload ?: ($order->order_number ?? 'NoovaERP'));

        $paymentMethod = strtoupper($order->payments->first()?->method ?? ($order->status === 'paid' ? 'CARD' : 'N/A'));
        $paymentAmount = (float) ($order->payments->sum('amount') ?: $order->total);
        $taxableAmount = max((float) $order->total - (float) $order->tax_amount, 0);

        $instagram = $socialLinks->get('instagram');
        $facebook = $socialLinks->get('facebook');
        $website = $settings['business_website'] ?? null;
        $itemQty = (int) $order->items->sum('quantity');
        $gstRate = ($taxableAmount > 0) ? (int) round((float) $order->tax_amount / $taxableAmount * 100) : 9;
    @endphp

    <div class="receipt">
        <div class="header center">
            @if(!empty($settings['business_logo_url']))
            <div class="store-logo-wrap">
                <img src="{{ $settings['business_logo_url'] }}" alt="Business logo" class="store-logo" />
            </div>
            @endif

            <div class="store-name">{{ $settings['business_name'] ?? 'NoovaERP Store' }}</div>

            @forelse($businessAddresses as $address)
            <div class="store-line">{{ $address }}</div>
            @empty
            <div class="store-line">Your Business Address</div>
            @endforelse

            @if(!empty($whatsAppValue))
            <div class="store-line">WhatsApp: {{ $whatsAppValue }}</div>
            @endif

            @if(!empty($instagram))
            <div class="store-line">Instagram: {{ $instagram }}</div>
            @endif

            @if(!empty($facebook))
            <div class="store-line">Facebook: {{ $facebook }}</div>
            @endif

            @if(!empty($website))
            <div class="store-line">Online Shop: {{ $website }}</div>
            @endif

            @if(!empty($settings['gst_registration_number']))
            <div class="store-line">GST REG NO: {{ $settings['gst_registration_number'] }}</div>
            @endif

            <div class="copy-label">** Duplicate Copy-1 **</div>
        </div>

        <div class="line"></div>

        <div class="receipt-code">Receipt: {{ $order->receipt_number ?? $order->order_number }}</div>

        @if(!empty($order->customer) && !empty($order->customer->code))
        <div class="row">
            <span>Customer ID:</span>
            <span>{{ $order->customer->code }}</span>
        </div>
        @endif

        @if(!empty($order->customer) && !empty($order->customer->name) && $order->customer->name !== 'Walk-in Customer')
        <div class="row">
            <span>Customer:</span>
            <span>{{ $order->customer->name }}</span>
        </div>
        @endif

        @foreach($order->items as $item)
        <div class="item-block">
            <div class="row item-main">
                <span class="truncate">{{ (int) $item->quantity }} {{ $item->product_name ?? $item->product?->name ?? 'Product' }}</span>
                <span class="amount-col">{{ number_format((float) $item->line_total, 2) }}</span>
            </div>

            @if(!empty($item->variant?->name))
            <div class="row item-sub">
                <span class="truncate">{{ $item->variant->name }}</span>
                <span class="amount-col"></span>
            </div>
            @endif
        </div>
        @endforeach

        <div class="line"></div>

        <div class="row">
            <span>SUBTOTAL</span>
            <span class="amount-col">{{ number_format((float) $order->subtotal, 2) }}</span>
        </div>

        @if((float) $order->discount_amount > 0)
        <div class="row">
            <span>DISCOUNT</span>
            <span class="amount-col">-{{ number_format((float) $order->discount_amount, 2) }}</span>
        </div>
        @endif

        <div class="row bold">
            <span>TOTAL</span>
            <span class="amount-col">{{ number_format((float) $order->total, 2) }}</span>
        </div>

        <div class="row">
            <span>{{ $paymentMethod }} SG$</span>
            <span class="amount-col">{{ number_format($paymentAmount, 2) }}</span>
        </div>

        <div class="line"></div>

        <div class="row">
            <span>Item Qty: {{ $itemQty }}</span>
            <span>Svc Qty: 0</span>
        </div>

        <div class="term-date">Term: {{ $order->terminal_id ?? '-' }} Cashier: {{ strtoupper(substr($order->createdBy?->name ?? 'ST', 0, 2)) }}</div>
        <div class="term-date">Date: {{ ($order->paid_at ?? $order->created_at)->format('M-d-Y h:i A') }}</div>

        @if((float) $order->tax_amount > 0)
        <div class="line"></div>

        <div class="row">
            <span>GST {{ $gstRate }}%</span>
            <span class="amount-col">{{ number_format((float) $order->tax_amount, 2) }}</span>
        </div>
        <div class="row">
            <span>Taxable</span>
            <span class="amount-col">{{ number_format($taxableAmount, 2) }}</span>
        </div>
        @endif

        <div class="line"></div>

        <div class="center">
            <p class="footer-msg">Items sold are non</p>
            <p class="footer-msg">refundable/exchangeable</p>
            <p class="footer-msg">/returnable</p>
            <p class="footer-msg">Thank you!</p>
            <p class="footer-msg">Please come again!</p>

            @if(!empty($qrPayload))
            <div class="qr-wrap">
                <img src="{{ $qrUrl }}" alt="WhatsApp QR" />
                <p class="tiny-note">Scan to contact us on WhatsApp</p>
            </div>
            @endif
        </div>
    </div>
</body>
</html>
