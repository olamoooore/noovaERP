<x-mail::message>
# Account Statement

Please find your account statement attached.

Statement period: {{ $payload['period']['start_date'] }} to {{ $payload['period']['end_date'] }}

Opening balance: {{ number_format($payload['opening_balance'] ?? 0, 2) }}

Closing balance: {{ number_format($payload['closing_balance'] ?? 0, 2) }}

Total debits: {{ number_format($payload['total_debits'] ?? 0, 2) }}

Total credits: {{ number_format($payload['total_credits'] ?? 0, 2) }}

Thanks,

{{ config('app.name') }}
</x-mail::message>
