<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
        .header { margin-bottom: 24px; }
        .title { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
        .meta { color: #4b5563; font-size: 11px; }
        .summary { margin: 20px 0; }
        .summary td { padding: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Customer Statement</div>
        @if(!empty($payload['customer']['code']))
        <div class="meta">Customer ID: {{ $payload['customer']['code'] }}</div>
        @endif
        <div class="meta">Customer: {{ $payload['customer']['name'] ?? 'Unknown' }}</div>
        <div class="meta">Period: {{ $payload['period']['start_date'] ?? '' }} to {{ $payload['period']['end_date'] ?? '' }}</div>
        <div class="meta">Generated: {{ now()->format('Y-m-d H:i') }}</div>
    </div>

    <table class="summary">
        <tr><td>Opening Balance</td><td class="num">{{ number_format($payload['opening_balance'] ?? 0, 2) }}</td></tr>
        <tr><td>Total Debits</td><td class="num">{{ number_format($payload['total_debits'] ?? 0, 2) }}</td></tr>
        <tr><td>Total Credits</td><td class="num">{{ number_format($payload['total_credits'] ?? 0, 2) }}</td></tr>
        <tr><td><strong>Closing Balance</strong></td><td class="num"><strong>{{ number_format($payload['closing_balance'] ?? 0, 2) }}</strong></td></tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Document</th>
                <th>Description</th>
                <th>Status</th>
                <th class="num">Debit</th>
                <th class="num">Credit</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach(($payload['transactions'] ?? []) as $transaction)
                <tr>
                    <td>{{ $transaction['date'] ?? '' }}</td>
                    <td>{{ $transaction['document_number'] ?? '' }}</td>
                    <td>{{ $transaction['description'] ?? '' }}</td>
                    <td>{{ ucfirst($transaction['status'] ?? '') }}</td>
                    <td class="num">{{ number_format($transaction['debit'] ?? 0, 2) }}</td>
                    <td class="num">{{ number_format($transaction['credit'] ?? 0, 2) }}</td>
                    <td class="num">{{ number_format($transaction['balance'] ?? 0, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
