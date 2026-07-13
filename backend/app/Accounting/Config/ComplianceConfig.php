<?php

namespace App\Accounting\Config;

/**
 * Compliance Configuration Helper
 * Centralizes configuration loading from environment variables
 */
class ComplianceConfig
{
    /**
     * Get Singapore PEPPOL configuration
     */
    public static function singaporePeppol(): array
    {
        return [
            'test_mode' => (bool)env('COMPLIANCE_SG_PEPPOL_TEST_MODE', true),
            'test_endpoint' => env('COMPLIANCE_SG_PEPPOL_TEST_ENDPOINT', 'https://test.peppol.example.com'),
            'production_endpoint' => env('COMPLIANCE_SG_PEPPOL_ENDPOINT', 'https://peppol.example.com'),
            'timeout' => (int)env('COMPLIANCE_SG_PEPPOL_TIMEOUT', 30),
            'max_retries' => (int)env('COMPLIANCE_SG_PEPPOL_MAX_RETRIES', 5),
            'retry_delay_minutes' => (int)env('COMPLIANCE_SG_PEPPOL_RETRY_DELAY_MINUTES', 5),
        ];
    }

    /**
     * Get Singapore SFRS validation rules
     */
    public static function singaporeSFRS(): array
    {
        return [
            'gst_rate' => 8, // 8% as of April 2024
            'gst_rates_allowed' => [0, 8],
            'uen_pattern' => '/^[0-9]{10}[A-Z]$|^[0-9]{8}[A-Z]{2}$/',
            'currency' => 'SGD',
            'require_supplier_uen' => true,
            'require_customer_uen' => false, // SMEs exempt
        ];
    }

    /**
     * Get Malaysia MyInvoice configuration
     */
    public static function malaysiaMyInvoice(): array
    {
        return [
            'test_mode' => (bool)env('COMPLIANCE_MY_MYINVOICE_TEST_MODE', true),
            'test_endpoint' => env('COMPLIANCE_MY_MYINVOICE_TEST_ENDPOINT', 'https://test-api.myinvoice.my'),
            'production_endpoint' => env('COMPLIANCE_MY_MYINVOICE_ENDPOINT', 'https://api.myinvoice.my'),
            'timeout' => (int)env('COMPLIANCE_MY_MYINVOICE_TIMEOUT', 30),
            'max_retries' => (int)env('COMPLIANCE_MY_MYINVOICE_MAX_RETRIES', 5),
        ];
    }

    /**
     * Get Malaysia MFRS validation rules
     */
    public static function malaysiaMFRS(): array
    {
        return [
            'sst_rate' => 6, // 6% Service and Sales Tax
            'sst_rates_allowed' => [0, 6],
            'company_id_pattern' => '/^[0-9]{12}$|^[0-9]+$/',
            'currency' => 'MYR',
            'require_company_registration' => true,
            'support_individual_buyer' => true,
        ];
    }

    /**
     * Get submission status poll configuration
     */
    public static function statusPollConfig(): array
    {
        return [
            'enabled' => (bool)env('COMPLIANCE_STATUS_POLL_ENABLED', true),
            'interval_minutes' => (int)env('COMPLIANCE_STATUS_POLL_INTERVAL_MINUTES', 15),
            'batch_size' => (int)env('COMPLIANCE_STATUS_POLL_BATCH_SIZE', 100),
            'check_pending_hours' => (int)env('COMPLIANCE_STATUS_POLL_HOURS', 48),
        ];
    }

    /**
     * Get auto-submit configuration
     */
    public static function autoSubmitConfig(): array
    {
        return [
            'enabled' => (bool)env('COMPLIANCE_AUTO_SUBMIT_ENABLED', false),
            'countries' => explode(',', env('COMPLIANCE_AUTO_SUBMIT_COUNTRIES', 'SG,MY')),
            'delay_minutes' => (int)env('COMPLIANCE_AUTO_SUBMIT_DELAY_MINUTES', 5),
        ];
    }

    /**
     * Get audit configuration
     */
    public static function auditConfig(): array
    {
        return [
            'log_submissions' => (bool)env('COMPLIANCE_AUDIT_LOG_SUBMISSIONS', true),
            'log_events' => (bool)env('COMPLIANCE_AUDIT_LOG_EVENTS', true),
            'log_responses' => (bool)env('COMPLIANCE_AUDIT_LOG_RESPONSES', true),
            'encrypt_payloads' => (bool)env('COMPLIANCE_ENCRYPT_PAYLOADS', true),
        ];
    }

    /**
     * Get endpoint for country
     */
    public static function getEndpoint(string $provider, string $country, bool $testMode = null): ?string
    {
        $testMode = $testMode ?? env('APP_DEBUG', false);

        return match (strtolower($provider . ':' . $country)) {
            'peppol:sg' => $testMode
                ? self::singaporePeppol()['test_endpoint']
                : self::singaporePeppol()['production_endpoint'],
            'myinvoice:my' => $testMode
                ? self::malaysiaMyInvoice()['test_endpoint']
                : self::malaysiaMyInvoice()['production_endpoint'],
            default => null,
        };
    }
}
