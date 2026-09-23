<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement of Account</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; line-height: 1.4; }

        .header { margin-bottom: 16px; }
        .company-name { font-size: 18px; font-weight: bold; color: #1a1a1a; }
        .company-details { font-size: 9px; color: #6b7280; margin-top: 2px; }

        .title-bar { background: #1a56db; color: #fff; padding: 10px 16px; font-size: 16px; font-weight: bold; margin-bottom: 16px; text-align: center; letter-spacing: 1px; }

        .info-grid { display: flex; gap: 12px; margin-bottom: 20px; }
        .info-box { flex: 1; border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; }
        .info-box .label { font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 2px; }
        .info-box .value { font-size: 11px; color: #111827; }
        .info-box.attention { flex: 2; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th { background: #f3f4f6; font-weight: bold; font-size: 10px; text-transform: uppercase; padding: 8px 6px; border-bottom: 2px solid #d1d5db; text-align: left; }
        td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
        .num { text-align: right; }

        .total-row { font-weight: bold; background: #f9fafb; }
        .total-row td { border-bottom: 2px solid #1a56db; border-top: 2px solid #1a56db; padding: 10px 6px; }

        .footer-section { margin-top: 24px; }
        .amount-due { text-align: right; font-size: 14px; font-weight: bold; margin-bottom: 20px; }
        .amount-due .value { color: #1a56db; }

        .bank-info { border-top: 1px solid #d1d5db; padding-top: 12px; }
        .bank-info .title { font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .bank-info .detail { font-size: 10px; color: #374151; line-height: 1.6; }

        .no-data { text-align: center; padding: 40px 0; color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    @php
        $statementLines = collect($payload['transactions'] ?? $payload['lines'] ?? []);
        $amountDue = $payload['total_amount_due'] ?? $payload['closing_balance'] ?? 0;
    @endphp
    {{-- Company Header --}}
    <div class="header">
        <div class="company-name">{{ $payload['company']['name'] ?? 'Your Company' }}</div>
        @if(!empty($payload['company']['address']))
        <div class="company-details">{{ $payload['company']['address'] }}</div>
        @endif
        @if(!empty($payload['company']['email']) || !empty($payload['company']['phone']))
        <div class="company-details">
            @if(!empty($payload['company']['email']))Email: {{ $payload['company']['email'] }}@endif
            @if(!empty($payload['company']['phone']) && !empty($payload['company']['email'])) | @endif
            @if(!empty($payload['company']['phone']))Tel: {{ $payload['company']['phone'] }}@endif
        </div>
        @endif
    </div>

    {{-- Title --}}
    <div class="title-bar">STATEMENT OF ACCOUNT</div>

    {{-- Info Boxes --}}
    <div class="info-grid">
        <div class="info-box attention">
            <div class="label">Attention To</div>
            <div class="value">{{ $payload['customer']['attention_to'] ?? $payload['customer']['name'] ?? '' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Account Name</div>
            <div class="value">{{ $payload['customer']['name'] ?? '' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Account Code</div>
            <div class="value">{{ $payload['customer']['code'] ?? '' }}</div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-box">
            <div class="label">Address</div>
            <div class="value">{{ $payload['customer']['address'] ?? $payload['customer']['billing_address'] ?? '-' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Currency</div>
            <div class="value">{{ $currency }}</div>
        </div>
        <div class="info-box">
            <div class="label">Payment Term</div>
            <div class="value">{{ $payload['customer']['payment_terms'] ?? '-' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Statement Date</div>
            <div class="value">
                @if($periodStart){{ $periodStart }} to {{ $asOfDate }}@else{{ $asOfDate }}@endif
            </div>
        </div>
    </div>

    {{-- Invoice Table --}}
    @if($statementLines->count() > 0)
    <table>
        <thead>
            <tr>
                <th style="width: 11%;">Date</th>
                <th style="width: 12%;">Type</th>
                <th style="width: 15%;">Document</th>
                <th style="width: 24%;">Description</th>
                <th style="width: 10%;">Due Date</th>
                <th class="num" style="width: 9%;">Debit</th>
                <th class="num" style="width: 9%;">Credit</th>
                <th class="num" style="width: 10%;">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach($statementLines as $line)
            <tr>
                <td>{{ $line['invoice_date'] ?? $line['date'] ?? '' }}</td>
                <td>{{ $line['invoice_number'] ?? $line['document_number'] ?? '' }}</td>
                <td>{{ $line['reference'] ?? $line['description'] ?? '' }}</td>
                <td>{{ $line['due_date'] ?? '-' }}</td>
                <td class="num">{{ number_format($line['total'] ?? $line['debit'] ?? 0, 2) }}</td>
                <td class="num">{{ number_format($line['balance'] ?? 0, 2) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="4" style="text-align: right;">Total</td>
                <td class="num">{{ number_format($statementLines->sum(fn ($line) => $line['total'] ?? $line['debit'] ?? 0), 2) }}</td>
                <td class="num">{{ number_format($amountDue, 2) }}</td>
            </tr>
        </tbody>
    </table>
    @else
    <div class="no-data">No transactions for {{ $periodStart ? $periodStart.' to '.$asOfDate : 'the period ending '.$asOfDate }}.</div>
    @endif

    {{-- Total Amount Due --}}
    <div class="amount-due">
        Total Amount Due ({{ $payload['currency'] ?? 'USD' }}): <span class="value">{{ number_format($amountDue, 2) }}</span>
    </div>

    {{-- Bank Info Footer --}}
    @if(!empty($payload['bank']['bank_name']) || !empty($payload['bank']['name']))
    @php $bankName = $payload['bank']['bank_name'] ?? $payload['bank']['name'] ?? ''; @endphp
    <div class="bank-info">
        <div class="title">Bank Details</div>
        <div class="detail">
            <strong>Bank Name:</strong> {{ $bankName }}<br/>
            @if(!empty($payload['bank']['account_name']))<strong>Account Name:</strong> {{ $payload['bank']['account_name'] }}<br/>@endif
            @if(!empty($payload['bank']['account_number']))<strong>Account No:</strong> {{ $payload['bank']['account_number'] }}<br/>@endif
            @if(!empty($payload['bank']['swift_code']))<strong>SWIFT Code:</strong> {{ $payload['bank']['swift_code'] }}@endif
        </div>
    </div>
    @endif
</body>
</html>
