<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement of Account - Comparison</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; line-height: 1.4; }

        .header { margin-bottom: 16px; }
        .company-name { font-size: 18px; font-weight: bold; color: #1a1a1a; }
        .company-details { font-size: 9px; color: #6b7280; margin-top: 2px; }

        .title-bar { background: #1a56db; color: #fff; padding: 10px 16px; font-size: 16px; font-weight: bold; margin-bottom: 16px; text-align: center; letter-spacing: 1px; }

        .info-grid { display: flex; gap: 12px; margin-bottom: 16px; }
        .info-box { flex: 1; border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; }
        .info-box .label { font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 2px; }
        .info-box .value { font-size: 11px; color: #111827; }
        .info-box.attention { flex: 2; }

        .section-title { font-size: 13px; font-weight: bold; color: #1a56db; margin: 18px 0 8px 0; padding-bottom: 4px; border-bottom: 2px solid #e5e7eb; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th { background: #f3f4f6; font-weight: bold; font-size: 10px; text-transform: uppercase; padding: 8px 6px; border-bottom: 2px solid #d1d5db; text-align: left; }
        td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
        .num { text-align: right; }

        .total-row { font-weight: bold; background: #f9fafb; }
        .total-row td { border-bottom: 2px solid #1a56db; border-top: 2px solid #1a56db; padding: 10px 6px; }

        .delta-positive { color: #059669; font-weight: bold; }
        .delta-negative { color: #dc2626; font-weight: bold; }
        .delta-neutral { color: #6b7280; }

        .arrow-up::before { content: "▲ "; font-size: 8px; }
        .arrow-down::before { content: "▼ "; font-size: 8px; }

        .footer-section { margin-top: 24px; border-top: 1px solid #d1d5db; padding-top: 12px; }
        .bank-info .title { font-size: 10px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
        .bank-info .detail { font-size: 10px; color: #374151; line-height: 1.6; }

        .no-data { text-align: center; padding: 40px 0; color: #9ca3af; font-style: italic; }

        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    {{-- Company Header --}}
    <div class="header">
        @if(! empty($payload['company']['logo']))
            <img src="{{ $payload['company']['logo'] }}" alt="{{ $payload['company']['name'] ?? '' }}" style="max-height: 60px; max-width: 260px; margin-bottom: 4px;" />
        @else
            <div class="company-name">{{ $payload['company']['name'] ?? '' }}</div>
        @endif
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
    <div class="title-bar">STATEMENT OF ACCOUNT — COMPARISON</div>

    {{-- Customer Info --}}
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
            <div class="value">{{ $payload['customer']['address'] ?? '-' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Currency</div>
            <div class="value">{{ $payload['currency'] ?? 'MYR' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Payment Term</div>
            <div class="value">{{ $payload['customer']['payment_terms'] ?? '-' }}</div>
        </div>
        <div class="info-box">
            <div class="label">Generated On</div>
            <div class="value">{{ $payload['generated_at'] ?? '' }}</div>
        </div>
    </div>

    {{-- Comparison Summary Table --}}
    @if(count($payload['comparisons']) > 0)
    <div class="section-title">Outstanding Balance Summary</div>
    <table>
        <thead>
            <tr>
                <th style="width: 35%;">Metric</th>
                @foreach($payload['comparisons'] as $comparison)
                <th class="num" style="width: {{ round(65 / count($payload['comparisons']), 1) }}%;">As of {{ $comparison['as_of_date'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Total Outstanding ({{ $payload['currency'] ?? 'MYR' }})</strong></td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ number_format($comparison['total_outstanding'], 2) }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php
                            $delta = $payload['deltas'][$i - 1];
                            $change = $delta['outstanding_change'];
                        @endphp
                        <br/>
                        <span class="{{ $change > 0 ? 'delta-positive arrow-up' : ($change < 0 ? 'delta-negative arrow-down' : 'delta-neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }} ({{ $delta['outstanding_change_pct'] }}%)
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr>
                <td><strong>Invoice Count</strong></td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ $comparison['invoice_count'] }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php $countChange = $payload['deltas'][$i - 1]['invoice_count_change']; @endphp
                        <br/>
                        <span class="{{ $countChange > 0 ? 'delta-positive' : ($countChange < 0 ? 'delta-negative' : 'delta-neutral') }}">
                            {{ $countChange > 0 ? '+' : '' }}{{ $countChange }}
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr class="total-row">
                <td colspan="{{ count($payload['comparisons']) + 1 }}" style="text-align: center;">
                    @if(!empty($payload['deltas']))
                        Last change: {{ $payload['deltas'][count($payload['deltas']) - 1]['outstanding_change'] > 0 ? '+' : '' }}{{ number_format($payload['deltas'][count($payload['deltas']) - 1]['outstanding_change'], 2) }} ({{ $payload['deltas'][count($payload['deltas']) - 1]['outstanding_change_pct'] }}%)
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    {{-- Aging Breakdown Table --}}
    <div class="section-title">Aging Breakdown</div>
    <table>
        <thead>
            <tr>
                <th style="width: 35%;">Aging Bucket</th>
                @foreach($payload['comparisons'] as $comparison)
                <th class="num" style="width: {{ round(65 / count($payload['comparisons']), 1) }}%;">As of {{ $comparison['as_of_date'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Current (not due)</td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ number_format($comparison['aging']['current'], 2) }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php $change = $payload['deltas'][$i - 1]['aging_changes']['current']; @endphp
                        <br/>
                        <span class="{{ $change > 0 ? 'delta-positive' : ($change < 0 ? 'delta-negative' : 'delta-neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }}
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr>
                <td>1 - 30 Days</td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ number_format($comparison['aging']['days_1_30'], 2) }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php $change = $payload['deltas'][$i - 1]['aging_changes']['days_1_30']; @endphp
                        <br/>
                        <span class="{{ $change > 0 ? 'delta-positive' : ($change < 0 ? 'delta-negative' : 'delta-neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }}
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr>
                <td>31 - 60 Days</td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ number_format($comparison['aging']['days_31_60'], 2) }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php $change = $payload['deltas'][$i - 1]['aging_changes']['days_31_60']; @endphp
                        <br/>
                        <span class="{{ $change > 0 ? 'delta-positive' : ($change < 0 ? 'delta-negative' : 'delta-neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }}
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr>
                <td>61 - 90 Days</td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ number_format($comparison['aging']['days_61_90'], 2) }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php $change = $payload['deltas'][$i - 1]['aging_changes']['days_61_90']; @endphp
                        <br/>
                        <span class="{{ $change > 0 ? 'delta-positive' : ($change < 0 ? 'delta-negative' : 'delta-neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }}
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr>
                <td>90+ Days</td>
                @foreach($payload['comparisons'] as $i => $comparison)
                <td class="num">
                    {{ number_format($comparison['aging']['days_90_plus'], 2) }}
                    @if(!empty($payload['deltas'][$i - 1]))
                        @php $change = $payload['deltas'][$i - 1]['aging_changes']['days_90_plus']; @endphp
                        <br/>
                        <span class="{{ $change > 0 ? 'delta-positive' : ($change < 0 ? 'delta-negative' : 'delta-neutral') }}">
                            {{ $change > 0 ? '+' : '' }}{{ number_format($change, 2) }}
                        </span>
                    @endif
                </td>
                @endforeach
            </tr>
            <tr class="total-row">
                <td><strong>Total</strong></td>
                @foreach($payload['comparisons'] as $comparison)
                <td class="num"><strong>{{ number_format($comparison['total_outstanding'], 2) }}</strong></td>
                @endforeach
            </tr>
        </tbody>
    </table>

    @else
    <div class="no-data">No outstanding invoices found for the selected comparison dates.</div>
    @endif
</body>
</html>
