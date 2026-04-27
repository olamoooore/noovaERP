<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention Policy
    |--------------------------------------------------------------------------
    |
    | Number of days to retain audit logs before pruning.
    | Default: 2555 days (~7 years) for financial compliance.
    |
    | Regulatory minimums:
    |   - SOX: 7 years
    |   - PCI-DSS: 1 year (3 months online)
    |   - GDPR: As long as necessary (right to erasure applies to PII, not audit)
    |
    */
    'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 2555),

    /*
    |--------------------------------------------------------------------------
    | Operations to Audit
    |--------------------------------------------------------------------------
    |
    | List of POS operations that must generate durable audit records.
    |
    */
    'audited_operations' => [
        'pos_payment',
        'pos_void',
        'pos_refund',
        'pos_return',
        'approval_grant',
        'approval_reject',
        'session_open',
        'session_close',
        'cash_drawer_open',
    ],

    /*
    |--------------------------------------------------------------------------
    | Immutability
    |--------------------------------------------------------------------------
    |
    | Audit logs are immutable by default. The prune command uses a direct
    | DB query to bypass the model guard for legitimate retention pruning.
    |
    */
    'immutable' => true,
];
