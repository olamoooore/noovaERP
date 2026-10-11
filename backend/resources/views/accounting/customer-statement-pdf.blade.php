<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account Statement</title>
    <style>
        @page { margin: 110px 40px 70px 40px; }

        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.45;
            color: #1f2937;
            margin: 0;
        }

        /* Repeats on every page DomPDF renders. */
        header { position: fixed; top: -75px; left: 0; right: 0; height: 70px; }
        footer { position: fixed; bottom: -45px; left: 0; right: 0; height: 40px; }

        .muted { color: #6b7280; }
        .small { font-size: 9px; }
        .bold { font-weight: bold; }
        .right { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }

        .doc-title {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 1.5px;
            color: #111827;
            text-align: right;
        }
        .company-name { font-size: 15px; font-weight: bold; color: #111827; }
        .company-meta { font-size: 9px; color: #6b7280; }

        .rule { border-top: 2px solid #1f2937; margin: 10px 0 14px; }
        .hairline { border-top: 1px solid #e5e7eb; }

        /* Section labels */
        .label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            margin-bottom: 3px;
        }

        /* Summary cards */
        table.summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 12px -6px 4px; }
        table.summary td {
            width: 25%;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 8px 10px;
            vertical-align: top;
        }
        .summary-label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.8px; color: #6b7280; }
        .summary-value { font-size: 13px; font-weight: bold; color: #111827; margin-top: 2px; }
        .summary-value.accent { color: #2E8B74; }
        .summary-value.danger { color: #b91c1c; }

        /* Transactions */
        table.txns { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.txns thead th {
            background: #1f2937;
            color: #ffffff;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 6px 6px;
            text-align: left;
        }
        table.txns thead th.right { text-align: right; }
        table.txns tbody td {
            padding: 6px 6px;
            border-bottom: 1px solid #eef0f3;
            vertical-align: top;
        }
        table.txns tbody tr:nth-child(even) { background: #fafbfc; }
        table.txns tbody tr.opening td { background: #f3f4f6; font-style: italic; color: #4b5563; }
        table.txns .type { font-size: 9px; }
        table.txns .doc { font-family: DejaVu Sans Mono, monospace; font-size: 9px; }
        table.txns .money { text-align: right; white-space: nowrap; }
        table.txns .balance { text-align: right; white-space: nowrap; font-weight: bold; }

        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 8px;
            font-size: 8px;
            background: #eef2ff;
            color: #3730a3;
            text-transform: capitalize;
        }
        .badge.paid, .badge.applied, .badge.received { background: #ecfdf5; color: #047857; }
        .badge.overdue { background: #fef2f2; color: #b91c1c; }

        table.closing { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.closing td { padding: 6px 10px; }
        table.closing tr.grand td {
            background: #1f2937;
            color: #ffffff;
            font-weight: bold;
            font-size: 11px;
        }

        .footer-note { font-size: 8px; color: #9ca3af; text-align: center; }
        .empty { padding: 24px; text-align: center; color: #9ca3af; border: 1px dashed #d1d5db; border-radius: 4px; }
    </style>
</head>
<body>

@php
    /** @var array $payload */
    $company  = $payload['company'] ?? [];
    $customer = $payload['customer'] ?? [];
    $period   = $payload['period'] ?? [];
    $totals   = [
        'opening' => (float) ($payload['opening_balance'] ?? 0),
        'debits'  => (float) ($payload['total_debits'] ?? 0),
        'credits' => (float) ($payload['total_credits'] ?? 0),
        'closing' => (float) ($payload['closing_balance'] ?? 0),
    ];
    $bank     = $payload['bank'] ?? [];
    $txns     = $payload['transactions'] ?? [];

    $currency = strtoupper((string) ($payload['currency'] ?? 'USD'));
    $symbols  = ['SGD' => 'S$', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'MYR' => 'RM', 'AUD' => 'A$', 'IDR' => 'Rp'];
    $symbol   = $symbols[$currency] ?? $currency.' ';

    $money = fn ($value) => $symbol.number_format((float) $value, 2);
    $hasPeriod = ! empty($period['start_date']);
@endphp

    <header>
        <table style="width: 100%;">
            <tr>
                <td style="vertical-align: top; width: 60%;">
                    @if(! empty($company['logo']))
                        <img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}" style="max-height: 60px; max-width: 260px; margin-bottom: 4px;" />
                    @else
                        <div class="company-name">{{ $company['name'] }}</div>
                    @endif
                    @if(! empty($company['address']))<div class="company-meta">{{ $company['address'] }}</div>@endif
                    <div class="company-meta">
                        @if(! empty($company['email'])){{ $company['email'] }}@endif
                        @if(! empty($company['phone'])) · {{ $company['phone'] }}@endif
                        @if(! empty($company['registration_number'])) · Reg. {{ $company['registration_number'] }}@endif
                    </div>
                </td>
                <td style="vertical-align: top; width: 40%;">
                    <div class="doc-title">ACCOUNT STATEMENT</div>
                    <div class="small muted right" style="margin-top: 2px;">Statement of Account</div>
                </td>
            </tr>
        </table>
        <div class="rule"></div>
    </header>

    <footer>
        <div class="hairline"></div>
        <div class="footer-note" style="margin-top: 6px;">
            {{ $company['name'] ?? '' }}
            @if(! empty($company['email'])) · {{ $company['email'] }}@endif
            @if(! empty($company['phone'])) · {{ $company['phone'] }}@endif
        </div>
        <div class="footer-note">
            Statement generated {{ $payload['generated_at'] ?? now()->toDateTimeString() }}
        </div>
    </footer>

    {{-- Customer / period block --}}
    <table style="width: 100%;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <div class="label">Bill To</div>
                <div class="bold" style="font-size: 12px;">{{ $customer['name'] ?? 'Customer' }}</div>
                @if(! empty($customer['code']))<div class="small muted">{{ $customer['code'] }}</div>@endif
                @if(! empty($customer['billing_address']))<div class="small muted">{{ $customer['billing_address'] }}</div>@endif
                @if(! empty($customer['email']))<div class="small muted">{{ $customer['email'] }}</div>@endif
                @if(! empty($customer['attention_to']))<div class="small muted">Attn: {{ $customer['attention_to'] }}</div>@endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table style="width: 100%;">
                    <tr>
                        <td class="small muted nowrap" style="width: 45%;">Statement No.</td>
                        <td class="small bold right">{{ ! empty($statementId) ? 'STMT-'.$statementId : 'STMT' }}</td>
                    </tr>
                    <tr>
                        <td class="small muted nowrap">Statement Period</td>
                        <td class="small right">
                            @if($hasPeriod)
                                {{ \Carbon\Carbon::parse($period['start_date'])->format('d M Y') }}
                                &ndash;
                                {{ \Carbon\Carbon::parse($period['end_date'])->format('d M Y') }}
                            @else
                                As of {{ \Carbon\Carbon::parse($period['end_date'] ?? ($payload['as_of_date'] ?? now()))->format('d M Y') }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="small muted nowrap">Generated</td>
                        <td class="small right">{{ \Carbon\Carbon::parse($payload['generated_at'] ?? now())->format('d M Y') }}</td>
                    </tr>
                    @if(! empty($customer['payment_terms']))
                    <tr>
                        <td class="small muted nowrap">Payment Terms</td>
                        <td class="small right">{{ $customer['payment_terms'] }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- Summary cards --}}
    <table class="summary">
        <tr>
            <td>
                <div class="summary-label">Opening Balance</div>
                <div class="summary-value">{{ $money($totals['opening']) }}</div>
            </td>
            <td>
                <div class="summary-label">Total Debits</div>
                <div class="summary-value">{{ $money($totals['debits']) }}</div>
            </td>
            <td>
                <div class="summary-label">Total Credits</div>
                <div class="summary-value" style="color: #047857;">{{ $money($totals['credits']) }}</div>
            </td>
            <td>
                <div class="summary-label">Closing Balance</div>
                <div class="summary-value {{ $totals['closing'] > 0 ? 'danger' : '' }}">{{ $money($totals['closing']) }}</div>
            </td>
        </tr>
    </table>

    {{-- Transactions --}}
    <div class="label" style="margin-top: 14px;">Transactions</div>

    @if(count($txns) === 0)
        <div class="empty">No transactions in this period.</div>
    @else
        <table class="txns">
            <thead>
                <tr>
                    <th style="width: 5%;" class="center">#</th>
                    <th style="width: 11%;">Date</th>
                    <th style="width: 12%;">Type</th>
                    <th style="width: 16%;">Document #</th>
                    <th style="width: 27%;">Description</th>
                    <th class="right" style="width: 9%;">Debit</th>
                    <th class="right" style="width: 9%;">Credit</th>
                    <th class="right" style="width: 11%;">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($txns as $i => $line)
                    @php $isOpening = ($line['type'] ?? '') === 'Opening Balance'; @endphp
                    <tr class="{{ $isOpening ? 'opening' : '' }}">
                        <td class="center">{{ $i + 1 }}</td>
                        <td class="nowrap">{{ ! empty($line['date']) ? \Carbon\Carbon::parse($line['date'])->format('d M Y') : '' }}</td>
                        <td class="type">
                            {{ $line['type'] ?? '' }}
                            @php
                                $status = strtolower((string) ($line['status'] ?? ''));
                                $showBadge = $status !== '' && ! in_array($status, ['open'], true);
                            @endphp
                            @if($showBadge)
                                <div class="small"><span class="badge {{ $status }}">{{ $status }}</span></div>
                            @endif
                        </td>
                        <td class="doc">
                            {{ $line['document_number'] ?? '' }}
                            @if(! empty($line['due_date']))
                                <div class="small muted">Due {{ \Carbon\Carbon::parse($line['due_date'])->format('d M Y') }}</div>
                            @endif
                        </td>
                        <td>{{ $line['description'] ?? '' }}</td>
                        <td class="money">{{ ((float) ($line['debit'] ?? 0)) !== 0.0 ? $money($line['debit']) : '' }}</td>
                        <td class="money" style="color: #047857;">{{ ((float) ($line['credit'] ?? 0)) !== 0.0 ? $money($line['credit']) : '' }}</td>
                        <td class="balance">{{ $money($line['balance'] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="closing">
            <tr class="grand">
                <td style="width: 60%;">TOTAL AMOUNT {{ $totals['closing'] >= 0 ? 'DUE' : 'IN CREDIT' }}</td>
                <td class="right">{{ $money(abs($totals['closing'])) }}</td>
            </tr>
        </table>
    @endif

    @if(! empty($bank['bank_name']) || ! empty($bank['account_number']))
        <div class="label" style="margin-top: 16px;">Remittance Details</div>
        <div class="small muted">
            {{ $bank['bank_name'] ?? '' }}
            @if(! empty($bank['account_name'])) · {{ $bank['account_name'] }}@endif
            @if(! empty($bank['account_number'])) · A/C {{ $bank['account_number'] }}@endif
            @if(! empty($bank['swift_code'])) · SWIFT {{ $bank['swift_code'] }}@endif
        </div>
    @endif

    <div class="small muted" style="margin-top: 18px;">
        Please settle the closing balance by the payment terms shown above. If you have already paid,
        kindly disregard this statement and accept our thanks.
    </div>

</body>
</html>
