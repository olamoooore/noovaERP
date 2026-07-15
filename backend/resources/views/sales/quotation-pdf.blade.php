<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotation {{ $quotation->quotation_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; margin: 0; padding: 36px; }
        .doc-header { display: flex; justify-content: space-between; margin-bottom: 28px; }
        .doc-title { font-size: 26px; font-weight: bold; }
        .doc-subtitle { color: #6b7280; font-size: 12px; margin-top: 2px; }
        .company { text-align: right; }
        .company-name { font-size: 14px; font-weight: bold; }
        .muted { color: #6b7280; }
        .parties { display: flex; justify-content: space-between; margin-bottom: 24px; }
        .party-box { width: 48%; }
        .party-label { text-transform: uppercase; letter-spacing: .05em; font-size: 10px; color: #6b7280; margin-bottom: 4px; }
        .party-name { font-weight: bold; font-size: 13px; }
        .meta-grid { display: flex; justify-content: space-between; margin-bottom: 22px; }
        .meta-grid table { font-size: 12px; }
        .meta-grid td { padding: 2px 0; vertical-align: top; }
        .meta-grid td:first-child { color: #6b7280; padding-right: 14px; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        table.items th { background: #f3f4f6; text-align: left; padding: 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; border-bottom: 2px solid #e5e7eb; }
        table.items td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; }
        .totals { width: 45%; margin-left: auto; font-size: 12px; }
        .totals td { padding: 5px 0; }
        .totals .grand td { border-top: 2px solid #111827; font-size: 15px; font-weight: bold; padding-top: 8px; }
        .notes { margin-top: 26px; }
        .notes h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: #6b7280; }
        .footer { margin-top: 40px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="doc-header">
        <div>
            <div class="doc-title">QUOTATION</div>
            <div class="doc-subtitle">{{ $quotation->quotation_number }}</div>
        </div>
        <div class="company">
            <div class="company-name">{{ $company['name'] ?? '' }}</div>
            @if(!empty($company['email']))<div class="muted">{{ $company['email'] }}</div>@endif
            @if(!empty($company['phone']))<div class="muted">{{ $company['phone'] }}</div>@endif
            @if(!empty($company['tax_id']))<div class="muted">Tax/GST Reg. No.: {{ $company['tax_id'] }}</div>@endif
        </div>
    </div>

    <div class="parties">
        <div class="party-box">
            <div class="party-label">Quotation To</div>
            <div class="party-name">{{ $quotation->customer?->name ?? 'Unknown' }}</div>
            @if($quotation->customer?->code)<div class="muted">Code: {{ $quotation->customer->code }}</div>@endif
            @if($quotation->contact_email)<div class="muted">{{ $quotation->contact_email }}</div>@endif
            @if($quotation->contact_phone)<div class="muted">{{ $quotation->contact_phone }}</div>@endif
            @if($quotation->billing_address_snapshot)
                <div class="muted" style="white-space: pre-line; margin-top:4px;">{{ $quotation->billing_address_snapshot }}</div>
            @endif
        </div>
        <div class="party-box" style="text-align:right;">
            <div class="party-label">Ship To</div>
            @if($quotation->shipping_address_snapshot)
                <div class="muted" style="white-space: pre-line;">{{ $quotation->shipping_address_snapshot }}</div>
            @else
                <div class="muted">Same as billing</div>
            @endif
        </div>
    </div>

    <div class="meta-grid">
        <table>
            <tr><td>Quote Date:</td><td>{{ optional($quotation->quotation_date)->format('Y-m-d') ?? '-' }}</td></tr>
            <tr><td>Valid Until:</td><td>{{ optional($quotation->valid_until)->format('Y-m-d') ?? '-' }}</td></tr>
            <tr><td>Attention To:</td><td>{{ $quotation->attention_to ?? '-' }}</td></tr>
            @if($quotation->salesperson)<tr><td>Salesperson:</td><td>{{ $quotation->salesperson }}</td></tr>@endif
            @if($quotation->validity)<tr><td>Validity:</td><td>{{ $quotation->validity }}</td></tr>@endif
        </table>
        <table>
            <tr><td>Customer Ref:</td><td>{{ $quotation->customer_reference ?? '-' }}</td></tr>
            <tr><td>Internal Ref:</td><td>{{ $quotation->internal_reference ?? '-' }}</td></tr>
            <tr><td>Status:</td><td>{{ ucfirst($quotation->status) }}</td></tr>
            @if($quotation->delivery_lead_time)<tr><td>Lead Time:</td><td>{{ $quotation->delivery_lead_time }}</td></tr>@endif
            @if($quotation->shipping_method)<tr><td>Shipping Method:</td><td>{{ $quotation->shipping_method }}</td></tr>@endif
            @if($quotation->shipping_terms)<tr><td>Shipping Terms:</td><td>{{ $quotation->shipping_terms }}</td></tr>@endif
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:24px;">#</th>
                <th>Description</th>
                <th class="num" style="width:50px;">Qty</th>
                <th class="num" style="width:90px;">Unit Price</th>
                <th class="num" style="width:50px;">Tax %</th>
                <th class="num" style="width:100px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @php $i = 0; @endphp
            @foreach($quotation->lines as $line)
                @php $i++; @endphp
                <tr>
                    <td>{{ $i }}</td>
                    <td>
                        {{ $line->description }}
                        @if($line->product?->sku)<div class="muted" style="font-size:10px;">SKU: {{ $line->product->sku }}</div>@endif
                    </td>
                    <td class="num">{{ $line->quantity }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->tax_rate, 2) }}%</td>
                    <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ number_format((float) $quotation->subtotal, 2) }}</td></tr>
        <tr><td>Tax</td><td class="num">{{ number_format((float) $quotation->tax_amount, 2) }}</td></tr>
        @if((float) $quotation->discount_amount > 0)
        <tr><td>Discount</td><td class="num">-{{ number_format((float) $quotation->discount_amount, 2) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ number_format((float) $quotation->total, 2) }}</td></tr>
    </table>

    @if($quotation->terms || $quotation->notes)
    <div class="notes">
        @if($quotation->terms)
            <h4>Terms &amp; Conditions</h4>
            <div style="white-space: pre-line;">{{ $quotation->terms }}</div>
        @endif
        @if($quotation->notes)
            <h4 style="margin-top:14px;">Notes</h4>
            <div style="white-space: pre-line;">{{ $quotation->notes }}</div>
        @endif
    </div>
    @endif

    <div class="footer">
        This quotation was generated by {{ $company['name'] ?? 'NoovaERP' }} on {{ now()->format('Y-m-d') }}.
    </div>
</body>
</html>
