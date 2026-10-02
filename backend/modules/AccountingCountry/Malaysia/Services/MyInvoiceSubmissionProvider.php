<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Accounting\Contracts\SubmissionProviderContract;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Malaysia MyInvoice Submission Provider
 * Submits invoices to MyInvoice portal via REST API
 */
class MyInvoiceSubmissionProvider implements SubmissionProviderContract
{
    private const SUBMISSION_BUILD_VERSION = '2026-04-06-retry-remap';

    protected Client $client;

    protected string $endpoint;

    protected bool $testMode;

    protected bool $hasEndpointOverride;

    public function __construct(?Client $client = null, ?string $endpoint = null)
    {
        $this->client = $client ?? new Client;
        $this->testMode = (bool) $this->safeConfig('compliance.malaysia.myinvoice_test_mode', true);

        $defaultTestEndpoint = 'https://preprod-api.myinvois.hasil.gov.my';
        $defaultProdEndpoint = 'https://api.myinvois.hasil.gov.my';

        $configuredEndpoint = $endpoint
            ?? $this->safeConfig('compliance.malaysia.myinvoice_api_endpoint');
        $this->hasEndpointOverride = is_string($configuredEndpoint)
            && trim($configuredEndpoint) !== '';

        // An explicitly blank environment variable should behave like an
        // unset value, not produce a relative URL with no host.
        $this->endpoint = rtrim(
            is_string($configuredEndpoint) && trim($configuredEndpoint) !== ''
                ? $configuredEndpoint
                : ($this->testMode ? $defaultTestEndpoint : $defaultProdEndpoint),
            '/'
        );
    }

    /**
     * Submit invoice to MyInvoice portal
     */
    public function submit(array $payload, array $credentials): array
    {
        $this->configureTenantEnvironment($credentials);
        $idempotencyKey = (string) ($credentials['idempotency_key'] ?? Str::uuid());

        try {
            $credentials = $this->withAccessToken($credentials);

            // Validate against required MyInvoice schema
            $payloadValidationError = $this->validateMyInvoicePayload($payload);
            if ($payloadValidationError !== null) {
                return [
                    'success' => false,
                    'message' => $payloadValidationError,
                    'error_code' => 'PAYLOAD_VALIDATION_FAILED',
                    'error_category' => 'schema_validation_error',
                    'finance_action' => 'Complete the required supplier, buyer, and line item fields before submitting to MyInvois.',
                ];
            }

            $submissionBody = $this->buildSubmissionBody($payload, $credentials);

            $this->safeLog('info', 'MyInvois submission prepared', $this->summarizeSubmissionBody($submissionBody));

            // MyInvois submission endpoint
            $response = $this->client->post("{$this->endpoint}/api/v1.0/documentsubmissions/", [
                'headers' => $this->buildHeaders($credentials, $idempotencyKey),
                'json' => $submissionBody,
                'timeout' => 30,
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);
            if (! is_array($responseBody)) {
                $responseBody = [];
            }

            if (in_array($response->getStatusCode(), [200, 201, 202], true)) {
                $acceptedDocuments = is_array($responseBody['acceptedDocuments'] ?? null)
                    ? $responseBody['acceptedDocuments']
                    : [];
                $rejectedDocuments = is_array($responseBody['rejectedDocuments'] ?? null)
                    ? $responseBody['rejectedDocuments']
                    : [];
                $firstAcceptedDocument = is_array($acceptedDocuments[0] ?? null)
                    ? $acceptedDocuments[0]
                    : [];

                if (empty($acceptedDocuments) && ! empty($rejectedDocuments)) {
                    $firstRejectedDocument = is_array($rejectedDocuments[0] ?? null)
                        ? $rejectedDocuments[0]
                        : [];
                    $rejectionError = is_array($firstRejectedDocument['error'] ?? null)
                        ? $firstRejectedDocument['error']
                        : [];

                    $error = $this->extractErrorDetails([
                        'error' => $rejectionError,
                        'message' => $rejectionError['message'] ?? null,
                    ], 'MyInvois rejected the document during synchronous validation');

                    return [
                        'success' => false,
                        'message' => $error['message'],
                        'error_code' => $error['code'],
                        'error_category' => $error['category'],
                        'finance_action' => $error['finance_action'],
                        'details' => $responseBody,
                        'status_details' => [
                            'accepted_documents' => $acceptedDocuments,
                            'rejected_documents' => $rejectedDocuments,
                        ],
                    ];
                }

                return [
                    'success' => true,
                    'provider_reference' => $responseBody['submissionUID']
                        ?? $responseBody['submissionId']
                        ?? $responseBody['submission_id']
                        ?? Str::uuid(),
                    'response_code' => $responseBody['response_code'] ?? 'RECEIVED',
                    'status' => 'acknowledged',
                    'provider_status' => $responseBody['status'] ?? 'submitted',
                    'external_document_uuid' => $firstAcceptedDocument['uuid'] ?? null,
                    'external_long_id' => $firstAcceptedDocument['longId'] ?? $firstAcceptedDocument['long_id'] ?? null,
                    'status_details' => [
                        'accepted_documents' => $acceptedDocuments,
                        'rejected_documents' => $rejectedDocuments,
                    ],
                    'idempotency_key' => $idempotencyKey,
                    'details' => $responseBody,
                ];
            }

            $error = $this->extractErrorDetails($responseBody);

            // Log error responses so they appear in logs for debugging
            try {
                Log::error('MyInvoice API response error', [
                    'status' => $response->getStatusCode(),
                    'error_code' => $error['code'],
                    'error_message' => $error['message'],
                    'body' => $responseBody,
                ]);
            } catch (\Throwable) {
                // Facade not available outside Laravel runtime
            }

            return [
                'success' => false,
                'message' => $error['message'],
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'finance_action' => $error['finance_action'],
                'details' => $responseBody,
            ];

        } catch (RequestException $e) {
            $responseData = $this->decodeExceptionResponse($e);
            $error = $this->extractErrorDetails($responseData, $e->getMessage());

            $responseBody = null;
            $correlationId = null;
            if ($e->hasResponse()) {
                $responseBody = (string) $e->getResponse()->getBody();
                $correlationId = $e->getResponse()->getHeaderLine('X-Correlation-ID');
                if ($correlationId === '') {
                    $correlationId = $e->getResponse()->getHeaderLine('x-correlation-id');
                }
            }

            $this->safeLog('error', 'MyInvoice submission request failed', [
                'error' => $e->getMessage(),
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'endpoint' => $this->endpoint,
                'correlation_id' => $correlationId,
                'response_body' => $responseBody,
                'response_data' => $responseData,
            ]);

            return [
                'success' => false,
                'message' => $error['message'],
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'finance_action' => $error['finance_action'],
                'details' => $responseData,
            ];

        } catch (\Exception $e) {
            $this->safeLog('error', 'MyInvoice submission error', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Submission error: '.$e->getMessage()];
        }
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable) {
            // Facade not available outside Laravel runtime.
        }
    }

    /**
     * Retry submission.
     *
     * $providerReference may be null when the original attempt failed before LHDN returned
     * a submissionUID (auth error, timeout, etc.). In that case we derive an idempotency key
     * from the payload hash so the retry is still deduplicated on the LHDN side.
     */
    public function retry(array $payload, ?string $providerReference, array $credentials): array
    {
        $credentials['idempotency_key'] = $credentials['idempotency_key']
            ?? ($providerReference ?: hash('sha256', (string) json_encode($payload)));

        return $this->submit($payload, $credentials);
    }

    /**
     * Get submission status
     */
    public function getStatus(string $providerReference, array $credentials): array
    {
        $this->configureTenantEnvironment($credentials);
        try {
            $credentials = $this->withAccessToken($credentials);

            $response = $this->client->get("{$this->endpoint}/api/v1.0/documentsubmissions/{$providerReference}", [
                'headers' => $this->buildHeaders($credentials),
                'query' => [
                    'pageNo' => 1,
                    'pageSize' => 100,
                ],
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data)) {
                $data = [];
            }

            $documentSummary = $this->resolvePrimaryDocumentSummary($data);
            $providerStatus = (string) ($documentSummary['status'] ?? $data['overallStatus'] ?? $data['status'] ?? 'unknown');
            $documentUuid = $documentSummary['uuid'] ?? null;
            $documentLongId = $documentSummary['longId'] ?? $documentSummary['long_id'] ?? null;
            $validationLink = $this->buildValidationLink(
                is_string($documentUuid) ? $documentUuid : null,
                is_string($documentLongId) ? $documentLongId : null
            );

            $details = $data;
            $statusDetails = [
                'submission' => $data,
                'document_summary' => $documentSummary,
            ];

            if (strtolower($providerStatus) === 'invalid' && is_string($documentUuid) && $documentUuid !== '') {
                $documentDetails = $this->fetchDocumentDetails($documentUuid, $credentials);
                if ($documentDetails !== null) {
                    $details = $documentDetails;
                    $statusDetails['document_details'] = $documentDetails;
                    $providerStatus = (string) ($documentDetails['status'] ?? $providerStatus);
                    $documentLongId = $documentDetails['longId'] ?? $documentLongId;
                    $validationLink = $this->buildValidationLink($documentUuid, is_string($documentLongId) ? $documentLongId : null);
                }
            }

            $error = $this->extractErrorDetails($details);
            $validationErrors = $this->extractValidationErrors($details);
            if (! empty($validationErrors)) {
                $details['validation_errors'] = $validationErrors;
                $statusDetails['validation_errors'] = $validationErrors;
                if ($error['code'] === null || $error['category'] === 'schema_validation_error') {
                    $firstValidationError = $validationErrors[0];
                    $error = $this->extractErrorDetails([
                        'error_code' => $firstValidationError['code'] ?? null,
                        'message' => $firstValidationError['message'] ?? null,
                    ]);
                }
            }

            $internalStatus = $this->mapStatus($providerStatus);

            // If the document is Valid but a buyer rejection request exists,
            // override internal status so the supplier can respond.
            $rejectRequestDateTime = $documentSummary['rejectRequestDateTime']
                ?? $details['rejectRequestDateTime']
                ?? null;
            if ($rejectRequestDateTime !== null && $rejectRequestDateTime !== '' && $internalStatus === 'accepted') {
                $internalStatus = 'rejection_requested';
                $statusDetails['reject_request_datetime'] = $rejectRequestDateTime;
            }

            return [
                'success' => true,
                'status' => $internalStatus,
                'provider_status' => $providerStatus,
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'finance_action' => $error['finance_action'],
                'external_document_uuid' => is_string($documentUuid) ? $documentUuid : null,
                'external_long_id' => is_string($documentLongId) ? $documentLongId : null,
                'validation_link' => $validationLink,
                'qr_payload' => $validationLink,
                'status_details' => $statusDetails,
                'details' => $details,
            ];

        } catch (RequestException $e) {
            $responseData = $this->decodeExceptionResponse($e);
            $error = $this->extractErrorDetails($responseData, $e->getMessage());

            return [
                'success' => false,
                'status' => 'error',
                'message' => $error['message'],
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'finance_action' => $error['finance_action'],
                'details' => $responseData,
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get document status directly by MyInvois document UUID.
     */
    public function getStatusByDocumentUuid(string $documentUuid, array $credentials): array
    {
        $this->configureTenantEnvironment($credentials);
        try {
            $credentials = $this->withAccessToken($credentials);
            $details = $this->fetchDocumentDetails($documentUuid, $credentials);

            if (! is_array($details)) {
                return [
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Unable to fetch document details from MyInvois.',
                ];
            }

            $providerStatus = (string) ($details['status'] ?? 'unknown');
            $documentLongId = $details['longId'] ?? $details['long_id'] ?? null;
            $validationLink = $this->buildValidationLink(
                $documentUuid,
                is_string($documentLongId) ? $documentLongId : null
            );

            $error = $this->extractErrorDetails($details);
            $validationErrors = $this->extractValidationErrors($details);
            $statusDetails = [
                'document_details' => $details,
            ];

            if (! empty($validationErrors)) {
                $statusDetails['validation_errors'] = $validationErrors;
            }

            $internalStatus = $this->mapStatus($providerStatus);

            // Detect buyer rejection request — same check as getStatus()
            $rejectRequestDateTime = $details['rejectRequestDateTime'] ?? null;
            if ($rejectRequestDateTime !== null && $rejectRequestDateTime !== '' && $internalStatus === 'accepted') {
                $internalStatus = 'rejection_requested';
                $statusDetails['reject_request_datetime'] = $rejectRequestDateTime;
            }

            return [
                'success' => true,
                'status' => $internalStatus,
                'provider_status' => $providerStatus,
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'finance_action' => $error['finance_action'],
                'external_document_uuid' => $documentUuid,
                'external_long_id' => is_string($documentLongId) ? $documentLongId : null,
                'validation_link' => $validationLink,
                'qr_payload' => $validationLink,
                'status_details' => $statusDetails,
                'details' => $details,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Cancel submission
     */
    public function cancel(string $providerReference, array $credentials): array
    {
        $this->configureTenantEnvironment($credentials);
        try {
            $credentials = $this->withAccessToken($credentials);

            $response = $this->client->put("{$this->endpoint}/api/v1.0/documents/state/{$providerReference}/state", [
                'headers' => $this->buildHeaders($credentials),
                'json' => [
                    'status' => 'cancelled',
                    'reason' => $credentials['cancellation_reason'] ?? 'Cancelled in NoovaERP',
                ],
                'timeout' => 10,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            if (! is_array($body)) {
                $body = [];
            }

            return [
                'success' => in_array($response->getStatusCode(), [200, 202], true),
                'status' => $this->mapStatus($body['status'] ?? 'cancelled'),
                'provider_status' => $body['status'] ?? 'cancelled',
                'details' => $body,
            ];

        } catch (RequestException $e) {
            $responseData = $this->decodeExceptionResponse($e);
            $error = $this->extractErrorDetails($responseData, $e->getMessage());

            return [
                'success' => false,
                'message' => $error['message'],
                'error_code' => $error['code'],
                'error_category' => $error['category'],
                'finance_action' => $error['finance_action'],
                'details' => $responseData,
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate MyInvoice payload structure
     */
    private function validateMyInvoicePayload(array $payload): ?string
    {
        $required = [
            'document_type',
            'invoice_number',
            'invoice_date',
            'supplier',
            'buyer',
            'line_items',
            'totals',
        ];

        foreach ($required as $field) {
            if (! isset($payload[$field])) {
                return "Missing required payload field: {$field}";
            }
        }

        if (empty($payload['supplier']['name']) || empty($payload['supplier']['tin'])) {
            return 'Supplier name and TIN are required';
        }

        $supplierHasCompanyRegistration = ! empty($payload['supplier']['company_registration']);
        $supplierHasIdentityDocument = ! empty($payload['supplier']['identity_type'])
            && ! empty($payload['supplier']['identity_number']);

        if (! $supplierHasCompanyRegistration && ! $supplierHasIdentityDocument) {
            return 'Supplier must have either company registration or identity document details';
        }

        if (empty($payload['buyer']['name'])) {
            return 'Buyer name is required';
        }

        $isConsolidatedInvoice = $this->isConsolidatedInvoicePayload($payload);
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $buyerAddress = is_array($buyer['address'] ?? null) ? $buyer['address'] : [];
        $buyerTin = $this->resolveBuyerTinValue($buyer);
        $buyerCountry = $this->normalizeCountryCode($buyerAddress['country'] ?? null);
        $buyerState = $this->normalizeIdentifierValue($buyerAddress['state'] ?? null);
        $buyerHasCompanyRegistration = ! empty($buyer['company_registration']);
        $buyerHasIdentityDocument = ! empty($buyer['identity_type'])
            && ! empty($buyer['identity_number']);

        // Issue #664: a business/government/foreign buyer must carry a real TIN
        // (the general-public TIN is for individuals / consolidated B2C only).
        // Reject before the auto-consolidation rule below can upgrade it.
        $buyerType = strtolower(trim((string) ($buyer['buyer_type'] ?? '')));
        $nonIndividualBuyerTypes = ['business', 'government', 'foreign'];
        if ($buyerTin === '' && in_array($buyerType, $nonIndividualBuyerTypes, true)) {
            $buyerName = trim((string) ($buyer['name'] ?? '')) ?: 'Customer';

            return "Customer '{$buyerName}' has no TIN — add it or mark as individual";
        }

        // LHDN rule: EI00000000010 is the end-consumer TIN and is only valid for
        // consolidated invoices (type 004). Auto-classify as consolidated when the
        // buyer has no real TIN so submission proceeds correctly.
        // G-37 (#274): an EXPLICIT non-consolidated payload must never be
        // silently upgraded to consolidated — it requires a real buyer TIN.
        // Unspecified callers (B2C/POS) keep the auto-classification.
        $explicitlyNonConsolidated = array_key_exists('is_consolidated', $payload) && $payload['is_consolidated'] === false;

        if (! $explicitlyNonConsolidated && ($buyerTin === 'EI00000000010' || $buyerTin === '')) {
            $isConsolidatedInvoice = true;
        }

        if (! $isConsolidatedInvoice) {
            if ($buyerTin === '') {
                return 'Buyer TIN is required for non-consolidated invoices';
            }

            if ($buyerTin === 'EI00000000010') {
                return 'Buyer TIN EI00000000010 is only allowed for consolidated invoices (classification code 004)';
            }

            // Registration/identity number is optional for buyers — the UBL builder
            // falls back to 'NA' (which LHDN accepts) when no value is present.
            // Do not block submission here; let LHDN's own validator decide.

            // Block explicit '17' (Not Applicable) — LHDN rejects it for non-consolidated invoices.
            // Null/empty is allowed here: the UBL builder defaults it to '14' (Kuala Lumpur).
            if ($buyerCountry === 'MYS' && $buyerState === '17') {
                return 'Buyer state cannot be 17 (Not Applicable) for non-consolidated Malaysian invoices';
            }
        }

        if (empty($payload['line_items'])) {
            return 'At least one line item is required';
        }

        $taxMismatchMessage = $this->validateTaxTotals($payload);
        if ($taxMismatchMessage !== null) {
            return $taxMismatchMessage;
        }

        return null;
    }

    private function validateTaxTotals(array $payload): ?string
    {
        $lineItems = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
        $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : [];

        $documentTaxAmount = (float) ($totals['tax_amount'] ?? $totals['sst_amount'] ?? 0);
        $lineTaxAmount = 0.0;

        foreach ($lineItems as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lineAmount = (float) ($line['line_amount'] ?? ($line['quantity'] ?? 0) * ($line['unit_price'] ?? 0));
            $explicitTaxAmount = $line['sst_amount'] ?? $line['tax_amount'] ?? null;

            if ($explicitTaxAmount !== null) {
                $lineTaxAmount += (float) $explicitTaxAmount;

                continue;
            }

            $lineTaxRate = (float) ($line['sst_rate'] ?? $line['tax_rate'] ?? 0);
            $lineTaxAmount += \App\Services\TaxEngineService::calculate((float) $lineAmount, (float) $lineTaxRate, false)['tax'];
        }

        if (abs($documentTaxAmount - $lineTaxAmount) > 0.01) {
            return sprintf(
                'Document tax total %.2f does not match the sum of line taxes %.2f',
                $documentTaxAmount,
                $lineTaxAmount
            );
        }

        return null;
    }

    /**
     * Map MyInvoice status codes
     */
    private function mapStatus(string $myinvoiceStatus): string
    {
        return match (strtolower($myinvoiceStatus)) {
            'submitted', 'processing', 'in_progress', 'in progress', 'partially valid' => 'pending',
            'received' => 'ack',
            'accepted', 'valid' => 'accepted',
            'rejection_requested' => 'rejection_requested',
            'invalid', 'rejected', 'validation_failed' => 'rejected',
            'failed', 'error' => 'failed',
            'cancelled' => 'cancelled',
            default => 'unknown',
        };
    }

    /**
     * Map provider errors into finance-actionable categories.
     *
     * @return array{category:string,finance_action:string,code:?string,message:string}
     */
    public function mapFinanceErrorCategory(array $providerError): array
    {
        $code = strtoupper((string) ($providerError['code']
            ?? $providerError['error_code']
            ?? $providerError['response_code']
            ?? ''));

        $message = (string) ($providerError['message']
            ?? $providerError['error_message']
            ?? $providerError['reason']
            ?? 'Unknown provider error');

        if ($code === 'DUPLICATE_SUBMISSION' || str_contains(strtolower($message), 'duplicate')) {
            return [
                'category' => 'duplicate_submission',
                'finance_action' => 'Verify whether invoice is already accepted before retrying.',
                'code' => $code !== '' ? $code : null,
                'message' => $message,
            ];
        }

        if (in_array($code, ['INVALID_TIN', 'INVALID_REGISTRATION', 'BUYER_TIN_INVALID', 'SUPPLIER_TIN_INVALID'], true)
            || str_contains(strtolower($message), 'tin')
            || str_contains(strtolower($message), 'registration')) {
            $isTinMismatch = str_contains(strtolower($message), 'not matching')
                || str_contains(strtolower($message), 'mismatch');

            return [
                'category' => 'party_identifier_error',
                'finance_action' => $isTinMismatch
                    ? 'The TIN in the document does not match the TIN registered for the API credentials on the LHDN portal. Verify that the "Supplier TIN" field in MyInvois settings matches the TIN for your myinvoice_client_id.'
                    : 'Update supplier or buyer tax identifiers and re-submit.',
                'code' => $code !== '' ? $code : null,
                'message' => $message,
            ];
        }

        if (in_array($code, ['INVALID_TAX_CODE', 'INVALID_SST_RATE', 'TAX_MISMATCH'], true)
            || str_contains(strtolower($message), 'tax')
            || str_contains(strtolower($message), 'sst')) {
            return [
                'category' => 'tax_mapping_error',
                'finance_action' => 'Correct tax code/rate mapping and regenerate the invoice payload.',
                'code' => $code !== '' ? $code : null,
                'message' => $message,
            ];
        }

        if (in_array($code, ['UNAUTHORIZED', 'TOKEN_EXPIRED', 'INVALID_API_KEY'], true)
            || str_contains(strtolower($message), 'unauthorized')
            || str_contains(strtolower($message), 'token')) {
            return [
                'category' => 'authentication_error',
                'finance_action' => 'Refresh MyInvoice credentials in country configuration and retry.',
                'code' => $code !== '' ? $code : null,
                'message' => $message,
            ];
        }

        if (in_array($code, ['SYSTEM_ERROR', 'SERVICE_UNAVAILABLE', 'TIMEOUT'], true)
            || str_contains(strtolower($message), 'timeout')
            || str_contains(strtolower($message), 'unavailable')) {
            return [
                'category' => 'provider_transient_error',
                'finance_action' => 'Retry later. Escalate only if issue persists beyond SLA window.',
                'code' => $code !== '' ? $code : null,
                'message' => $message,
            ];
        }

        return [
            'category' => 'schema_validation_error',
            'finance_action' => 'Review mandatory invoice fields and line totals, then re-submit.',
            'code' => $code !== '' ? $code : null,
            'message' => $message,
        ];
    }

    private function buildHeaders(array $credentials, ?string $idempotencyKey = null): array
    {
        $token = $credentials['api_token'] ?? $credentials['access_token'] ?? '';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => $idempotencyKey ?? ((string) ($credentials['idempotency_key'] ?? Str::uuid())),
        ];

        $apiKey = (string) ($credentials['api_key'] ?? '');
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }

        return $headers;
    }

    /**
     * Ensure a bearer token is available for API calls.
     *
     * Tokens are cached per-tenant using a key scoped to the tenant ID and a
     * hash of the client_id so one tenant can never receive another tenant's token.
     * The cache TTL is set to expires_in minus a 60-second safety buffer.
     *
     * Credential resolution order: DB row (per-tenant) → .env fallback.
     * If .env values are used because the DB row is empty, a warning is logged
     * so operators can detect misconfigured tenants early.
     */
    private function withAccessToken(array $credentials): array
    {
        if (! empty($credentials['access_token']) || ! empty($credentials['api_token'])) {
            return $credentials;
        }

        $dbClientId = (string) ($credentials['myinvoice_client_id'] ?? $credentials['client_id'] ?? '');
        $envClientId = (string) $this->safeConfig('compliance.malaysia.myinvoice_client_id', '');
        $usingEnvFallback = $dbClientId === '' && $envClientId !== '';

        $clientId = $dbClientId !== '' ? $dbClientId : $envClientId;
        $clientSecret = (string) (
            $credentials['myinvoice_client_secret']
            ?? $credentials['client_secret']
            ?? $this->safeConfig('compliance.malaysia.myinvoice_client_secret', '')
        );

        if ($clientId === '' || $clientSecret === '') {
            return $credentials;
        }

        $identityService = rtrim((string) $this->safeConfig('compliance.malaysia.myinvoice_identity_service', ''), '/');
        if ($identityService === '') {
            $identityService = $this->endpoint;
        }

        if ($usingEnvFallback) {
            Log::warning('MyInvois: using .env credential fallback — tenant DB config may be missing', [
                'client_id_hint' => substr($clientId, 0, 6).'***',
            ]);
        }

        // Tenant-isolated token cache. Key includes tenant ID so different tenants
        // never share a token, even when their client_id happens to be the same string.
        $tenantId = 'default';
        try {
            $tenantId = (string) (tenancy()->tenant?->id ?? 'default');
        } catch (\Throwable) {
            // Outside tenant context (e.g. unit tests); proceed without tenant isolation.
        }
        $cacheKey = 'myinvois_token_'.$tenantId.'_'.substr(hash('sha256', $clientId), 0, 24);

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $credentials['access_token'] = $cached;
                $credentials['api_token'] = $cached;

                return $credentials;
            }
        } catch (\Throwable) {
            // Cache unavailable; fall through to a fresh token request.
        }

        $scope = (string) ($credentials['myinvoice_scope'] ?? $this->safeConfig('compliance.malaysia.myinvoice_scope', 'InvoicingAPI'));

        try {
            $response = $this->client->post("{$identityService}/connect/token", [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $scope,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => 20,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            if (! is_array($body)) {
                return $credentials;
            }

            $token = (string) ($body['access_token'] ?? '');
            $expiresIn = max(120, (int) ($body['expires_in'] ?? 3600));

            if ($token === '') {
                return $credentials;
            }

            Log::debug('MyInvois OAuth token obtained', [
                'token_endpoint' => "{$identityService}/connect/token",
                'expires_in' => $expiresIn,
                'scope' => $body['scope'] ?? $scope,
                'tenant_id' => $tenantId,
            ]);

            // Cache with a 60-second buffer so we never hand out a token that is
            // about to expire between obtaining it and the API call.
            try {
                Cache::put($cacheKey, $token, $expiresIn - 60);
            } catch (\Throwable) {
                // Non-fatal: submission proceeds without caching.
            }

            $credentials['access_token'] = $token;
            $credentials['api_token'] = $token;

        } catch (\Throwable $e) {
            Log::error('MyInvois OAuth token request failed', [
                'token_endpoint' => "{$identityService}/connect/token",
                'error' => $e->getMessage(),
            ]);
        }

        return $credentials;
    }

    /**
     * Select sandbox or production from the tenant configuration supplied to
     * this request. A non-empty constructor/environment endpoint remains an
     * explicit operator override.
     */
    private function configureTenantEnvironment(array $credentials): void
    {
        if (array_key_exists('test_mode', $credentials)) {
            $this->testMode = (bool) $credentials['test_mode'];
        }

        if (! $this->hasEndpointOverride) {
            $this->endpoint = $this->testMode
                ? 'https://preprod-api.myinvois.hasil.gov.my'
                : 'https://api.myinvois.hasil.gov.my';
        }
    }

    private function decodeExceptionResponse(RequestException $e): array
    {
        if (! $e->hasResponse()) {
            return [];
        }

        $decoded = json_decode((string) $e->getResponse()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build MyInvois documentsubmissions envelope.
     */
    private function buildSubmissionBody(array $payload, array $credentials): array
    {
        if (isset($payload['documents']) && is_array($payload['documents']) && isset($payload['documents'][0]) && is_array($payload['documents'][0])) {
            $first = $payload['documents'][0];

            $rawDocument = $first['document'] ?? null;
            if (is_array($rawDocument)) {
                $rawDocument = json_encode($rawDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $document = is_string($rawDocument) ? $rawDocument : '';
            $decodedDocument = base64_decode($document, true);
            if ($decodedDocument !== false) {
                $documentJson = $decodedDocument;
            } else {
                $documentJson = $document !== '' ? $document : '{}';
                $document = base64_encode($documentJson);
            }

            $codeNumber = (string) (
                $first['codeNumber']
                ?? $payload['invoice_number']
                ?? $payload['document_number']
                ?? $credentials['code_number']
                ?? Str::uuid()
            );

            $format = strtoupper((string) ($first['format'] ?? 'JSON'));
            if ($format === '') {
                $format = 'JSON';
            }

            return [
                'documents' => [[
                    'format' => $format,
                    'document' => $document,
                    'documentHash' => (string) ($first['documentHash'] ?? hash('sha256', $documentJson)),
                    'codeNumber' => $codeNumber,
                ]],
            ];
        }

        $documentSource = $payload['_signed_document'] ?? null;
        if (! is_array($documentSource) || ! isset($documentSource['Invoice'])) {
            $documentSource = $this->buildUblDocumentFromMappedPayload($payload);
        }

        $documentJson = json_encode($documentSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($documentJson) || $documentJson === '') {
            $documentJson = '{}';
        }

        $codeNumber = (string) (
            $payload['invoice_number']
            ?? $payload['document_number']
            ?? $credentials['code_number']
            ?? Str::uuid()
        );

        // Log the actual TINs in the submitted document so that LHDN "TIN mismatch" errors
        // can be diagnosed: supplier_tin in MyInvois settings must match the TIN registered
        // for the API client credentials on the LHDN portal.
        $invoiceNode = $documentSource['Invoice'][0] ?? [];
        $submittedSupplierTin = $invoiceNode['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0]['_'] ?? null;
        $submittedBuyerTin = $invoiceNode['AccountingCustomerParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0]['_'] ?? null;
        $submittedTypeCode = $invoiceNode['InvoiceTypeCode'][0]['_'] ?? null;

        $this->safeLog('info', 'myinvois:submitToMyInvois — Signed JSON minified', [
            'invoice_id' => $payload['invoice_id'] ?? null,
            'invoice_number' => $payload['invoice_number'] ?? $codeNumber,
            'code_number' => $codeNumber,
            'payload_hash' => hash('sha256', $documentJson),
            'document_json_length' => strlen($documentJson),
            'document_type_code' => $submittedTypeCode,
            'supplier_tin' => $submittedSupplierTin,
            'buyer_tin' => $submittedBuyerTin,
            'has_ubl_extensions' => isset($documentSource['Invoice'][0]['UBLExtensions']),
            'has_signature' => isset($documentSource['Invoice'][0]['Signature']),
        ]);

        return [
            'documents' => [[
                'format' => 'JSON',
                'document' => base64_encode($documentJson),
                'documentHash' => hash('sha256', $documentJson),
                'codeNumber' => $codeNumber,
            ]],
        ];
    }

    private function summarizeSubmissionBody(array $submissionBody): array
    {
        $documents = is_array($submissionBody['documents'] ?? null) ? $submissionBody['documents'] : [];
        $firstDocument = is_array($documents[0] ?? null) ? $documents[0] : [];
        $encodedDocument = is_string($firstDocument['document'] ?? null) ? $firstDocument['document'] : '';
        $decodedDocument = $encodedDocument !== '' ? base64_decode($encodedDocument, true) : false;
        $documentData = is_string($decodedDocument) ? json_decode($decodedDocument, true) : null;
        $invoice = is_array($documentData['Invoice'][0] ?? null) ? $documentData['Invoice'][0] : [];
        $supplierParty = is_array($invoice['AccountingSupplierParty'][0]['Party'][0] ?? null)
            ? $invoice['AccountingSupplierParty'][0]['Party'][0]
            : [];
        $buyerParty = is_array($invoice['AccountingCustomerParty'][0]['Party'][0] ?? null)
            ? $invoice['AccountingCustomerParty'][0]['Party'][0]
            : [];

        $unitCodes = [];
        foreach ((array) ($invoice['InvoiceLine'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $unitCode = $line['InvoicedQuantity'][0]['unitCode'] ?? null;
            if (is_string($unitCode) && $unitCode !== '') {
                $unitCodes[] = strtoupper($unitCode);
            }
        }

        return [
            'build_version' => self::SUBMISSION_BUILD_VERSION,
            'provider_class' => self::class,
            'document_count' => count($documents),
            'code_number' => $firstDocument['codeNumber'] ?? null,
            'document_hash' => $firstDocument['documentHash'] ?? null,
            'invoice_number' => $invoice['ID'][0]['_'] ?? null,
            'invoice_keys' => array_keys($invoice),
            'line_count' => count((array) ($invoice['InvoiceLine'] ?? [])),
            'unit_codes' => array_values(array_unique($unitCodes)),
            'has_invoice_period' => array_key_exists('InvoicePeriod', $invoice),
            'has_additional_account_id' => array_key_exists('AdditionalAccountID', (array) ($invoice['AccountingSupplierParty'][0] ?? [])),
            'has_supplier_party_identification' => array_key_exists('PartyIdentification', $supplierParty),
            'has_buyer_party_identification' => array_key_exists('PartyIdentification', $buyerParty),
            'supplier_identification_count' => count((array) ($supplierParty['PartyIdentification'] ?? [])),
            'buyer_identification_count' => count((array) ($buyerParty['PartyIdentification'] ?? [])),
            'has_ubl_extensions' => array_key_exists('UBLExtensions', $invoice),
            'has_signature' => array_key_exists('Signature', $invoice),
        ];
    }

    /**
     * Convert mapped payload into a MyInvois UBL-style JSON document.
     */
    private function buildUblDocumentFromMappedPayload(array $payload): array
    {
        ['invoice_date' => $invoiceDate, 'issue_time' => $issueTime] = $this->resolveIssueDateTime($payload);
        $currency = (string) ($payload['currency_code'] ?? 'MYR');
        $isConsolidatedInvoice = $this->isConsolidatedInvoicePayload($payload);

        $supplier = is_array($payload['supplier'] ?? null) ? $payload['supplier'] : [];
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : [];
        $lineItems = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
        $signatureMetadata = is_array($payload['_signature_metadata'] ?? null) ? $payload['_signature_metadata'] : [];

        $resolvedBuyerTin = $this->resolveBuyerTinValue($buyer);
        if ($resolvedBuyerTin !== '') {
            $buyer['tin'] = $resolvedBuyerTin;
        }

        $supplierTaxId = $this->resolveTaxTypeCode($supplier['identity_type'] ?? null, ! empty($supplier['company_registration'] ?? null));
        $buyerTaxId = $this->resolveTaxTypeCode($buyer['identity_type'] ?? null, ! empty($buyer['company_registration'] ?? null));
        $buyerTinValue = strtoupper((string) ($buyer['tin'] ?? ''));
        $isGeneralBuyerTin = $buyerTinValue === '' || $buyerTinValue === 'EI00000000010';
        // Issue #664: never auto-classify a declared business/government/foreign
        // buyer as a consolidated general-public invoice.
        $buyerTypeValue = strtolower(trim((string) ($buyer['buyer_type'] ?? '')));
        $isNonIndividualBuyer = in_array($buyerTypeValue, ['business', 'government', 'foreign'], true);
        $explicitlyNonConsolidated = array_key_exists('is_consolidated', $payload) && $payload['is_consolidated'] === false;
        if ($isGeneralBuyerTin && ! $explicitlyNonConsolidated && ! $isNonIndividualBuyer) {
            $isConsolidatedInvoice = true;
            // LHDN's consolidated/general-public identity is TIN
            // EI00000000010 with BRN "NA". A fabricated NRIC is rejected by
            // production validation (CF324).
            $buyerTaxId = 'BRN';
        }
        $docTypeCode = $this->resolveDocumentTypeCode((string) ($payload['document_type'] ?? 'invoice'));
        $invoiceNumber = (string) ($payload['invoice_number'] ?? 'INV');
        $formattedIssueTime = $this->formatIssueTime($issueTime);

        $invoiceLines = [];
        $taxCategoryTotals = [];
        foreach ($lineItems as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $lineNo = (string) ($line['line_number'] ?? ($index + 1));
            $qty = (float) ($line['quantity'] ?? 1);
            $unitCode = $this->normalizeUnitCode($line['unit_code'] ?? null);
            $lineAmount = (float) ($line['line_amount'] ?? ($line['unit_price'] ?? 0));
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $taxRate = (float) ($line['sst_rate'] ?? $line['tax_rate'] ?? 0);
            $taxAmount = (float) ($line['sst_amount'] ?? \App\Services\TaxEngineService::calculate((float) $lineAmount, (float) $taxRate, false)['tax']);
            $classificationCode = (string) ($line['classification_code'] ?? '001');
            // G-37 (#274): never default to '01' blindly — a zero-tax line is
            // at least Not Applicable ('E' when exemption data exists).
            $taxTypeCode = (string) ($line['tax_type_code'] ?? ($taxAmount > 0 ? '01' : 'E'));
            $discountAmount = (float) ($line['discount_amount'] ?? 0);

            $taxSubtotal = [
                'TaxableAmount' => [['_' => $this->formatDecimal($lineAmount), 'currencyID' => $currency]],
                'TaxAmount' => [['_' => $this->formatDecimal($taxAmount), 'currencyID' => $currency]],
                'Percent' => [['_' => $this->formatDecimal($taxRate)]],
                'TaxCategory' => [[
                    'ID' => [['_' => $taxTypeCode]],
                    'TaxScheme' => [[
                        'ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']],
                    ]],
                ]],
            ];

            // G-37: exemption/zero-rated lines carry reason + exempted amount.
            if (in_array($taxTypeCode, ['E', '06'], true)) {
                $taxSubtotal['TaxExemptionReason'] = [[
                    '_' => (string) ($line['tax_exemption_reason']
                        ?? ($taxTypeCode === '06' ? 'Zero-rated supply' : 'Exempt supply')),
                ]];
                $taxSubtotal['TaxExemptionAmount'] = [[
                    '_' => $this->formatDecimal((float) ($line['tax_exemption_amount'] ?? $lineAmount)),
                    'currencyID' => $currency,
                ]];
            }

            $categoryKey = $taxTypeCode.'|'.$this->formatDecimal($taxRate);
            $taxCategoryTotals[$categoryKey] ??= [
                'code' => $taxTypeCode,
                'rate' => $taxRate,
                'taxable' => 0.0,
                'tax' => 0.0,
                'reason' => $taxSubtotal['TaxExemptionReason'][0]['_'] ?? null,
            ];
            $taxCategoryTotals[$categoryKey]['taxable'] = round($taxCategoryTotals[$categoryKey]['taxable'] + $lineAmount, 2);
            $taxCategoryTotals[$categoryKey]['tax'] = round($taxCategoryTotals[$categoryKey]['tax'] + $taxAmount, 2);

            $invoiceLine = [
                'ID' => [['_' => $lineNo]],
                'InvoicedQuantity' => [['_' => $this->formatQuantity($qty), 'unitCode' => $unitCode]],
                'LineExtensionAmount' => [['_' => $this->formatDecimal($lineAmount), 'currencyID' => $currency]],
                'TaxTotal' => [[
                    'TaxAmount' => [['_' => $this->formatDecimal($taxAmount), 'currencyID' => $currency]],
                    'TaxSubtotal' => [$taxSubtotal],
                ]],
                'Item' => [[
                    'CommodityClassification' => [[
                        'ItemClassificationCode' => [['_' => $classificationCode, 'listID' => 'CLASS']],
                    ]],
                    'Description' => [['_' => (string) ($line['description'] ?? 'Line Item')]],
                ]],
                'Price' => [[
                    'PriceAmount' => [['_' => $this->formatDecimal($unitPrice), 'currencyID' => $currency]],
                ]],
                'ItemPriceExtension' => [[
                    'Amount' => [['_' => $this->formatDecimal($lineAmount), 'currencyID' => $currency]],
                ]],
            ];

            if ($discountAmount > 0) {
                $invoiceLine['AllowanceCharge'] = [[
                    'ChargeIndicator' => [['_' => false]],
                    'AllowanceChargeReason' => [['_' => 'Line discount']],
                    'Amount' => [['_' => $this->formatDecimal($discountAmount), 'currencyID' => $currency]],
                ]];
            }

            $invoiceLines[] = $invoiceLine;
        }

        $totalExcludingTax = (float) ($totals['net_amount'] ?? $totals['subtotal_after_discount'] ?? $totals['subtotal'] ?? 0);
        $totalTax = (float) ($totals['tax_amount'] ?? $totals['sst_amount'] ?? 0);
        $totalIncludingTax = (float) ($totals['gross_amount'] ?? ($totalExcludingTax + $totalTax));
        $totalDiscount = (float) ($totals['discount_total'] ?? 0);
        $signatureId = (string) ($signatureMetadata['key_id'] ?? ('urn:uuid:'.(string) Str::uuid()));
        $signatureValue = (string) ($signatureMetadata['signature_value'] ?? '');
        $digestValue = (string) ($signatureMetadata['digest_value'] ?? base64_encode(hash('sha256', $invoiceNumber.'|'.$invoiceDate, true)));
        $signedAt = (string) ($signatureMetadata['signed_at'] ?? gmdate('c'));
        $certificate = is_array($signatureMetadata['certificate'] ?? null) ? $signatureMetadata['certificate'] : [];
        $signatoryName = (string) ($signatureMetadata['signatory_name'] ?? $supplier['name'] ?? 'Supplier');

        $shouldIncludeSignature = ! empty($signatureMetadata['enabled'])
            && $signatureValue !== '';

        // G-37 (#274): invoice-level TaxTotal carries one TaxSubtotal per
        // category/rate (was a single blanket subtotal).
        $taxSubtotals = [];

        foreach ($taxCategoryTotals as $category) {
            $subtotal = [
                'TaxableAmount' => [['_' => $this->formatDecimal($category['taxable']), 'currencyID' => $currency]],
                'TaxAmount' => [['_' => $this->formatDecimal($category['tax']), 'currencyID' => $currency]],
                'TaxCategory' => [[
                    'ID' => [['_' => $category['code']]],
                    'TaxScheme' => [[
                        'ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']],
                    ]],
                ]],
            ];

            if ((float) $category['rate'] > 0) {
                $subtotal['Percent'] = [['_' => $this->formatDecimal($category['rate'])]];
            }

            if (in_array($category['code'], ['E', '06'], true)) {
                $subtotal['TaxExemptionReason'] = [['_' => (string) ($category['reason'] ?? 'Exempt supply')]];
                $subtotal['TaxExemptionAmount'] = [['_' => $this->formatDecimal($category['taxable']), 'currencyID' => $currency]];
            }

            $taxSubtotals[] = $subtotal;
        }

        if ($taxSubtotals === []) {
            $taxSubtotals[] = [
                'TaxableAmount' => [['_' => $this->formatDecimal($totalExcludingTax), 'currencyID' => $currency]],
                'TaxAmount' => [['_' => $this->formatDecimal($totalTax), 'currencyID' => $currency]],
                'TaxCategory' => [[
                    'ID' => [['_' => $totalTax > 0 ? '01' : 'E']],
                    'TaxScheme' => [[
                        'ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']],
                    ]],
                ]],
            ];
        }

        $invoice = [
            'ID' => [['_' => $invoiceNumber]],
            'IssueDate' => [['_' => $invoiceDate]],
            'IssueTime' => [['_' => $formattedIssueTime]],
            'InvoiceTypeCode' => [['_' => $docTypeCode, 'listVersionID' => '1.0']],
            'DocumentCurrencyCode' => [['_' => $currency]],
            'TaxCurrencyCode' => [['_' => $currency]],
            'AccountingSupplierParty' => [[
                'Party' => [[
                    'IndustryClassificationCode' => [[
                        '_' => (string) ($supplier['msic_code'] ?? $this->safeConfig('compliance.malaysia.supplier_msic_code', '00000')),
                        'name' => (string) ($supplier['business_activity_description'] ?? $this->safeConfig('compliance.malaysia.supplier_business_activity_description', 'General Trading')),
                    ]],
                    'PartyIdentification' => $this->buildPartyIdentification($supplier, $supplierTaxId, true),
                    'PostalAddress' => [$this->buildPostalAddress($supplier['address'] ?? [])],
                    'PartyLegalEntity' => [[
                        'RegistrationName' => [['_' => (string) ($supplier['name'] ?? 'Supplier')]],
                    ]],
                    'Contact' => [[
                        'Telephone' => [['_' => $this->firstNonEmptyString(
                            $supplier['contact']['phone'] ?? null,
                            $supplier['phone'] ?? null,
                            $this->safeConfig('compliance.malaysia.supplier_phone', '+60000000000')
                        )]],
                        'ElectronicMail' => [['_' => $this->firstNonEmptyString(
                            $supplier['contact']['email'] ?? null,
                            $supplier['email'] ?? null,
                            $this->safeConfig('compliance.malaysia.supplier_email', 'no-reply@example.com')
                        )]],
                    ]],
                ]],
            ]],
            'AccountingCustomerParty' => [[
                'Party' => [[
                    'PostalAddress' => [$this->buildPostalAddress($buyer['address'] ?? [], $isConsolidatedInvoice)],
                    'PartyLegalEntity' => [[
                        'RegistrationName' => [['_' => (string) ($buyer['name'] ?? 'General Public')]],
                    ]],
                    'PartyIdentification' => $this->buildPartyIdentification($buyer, $buyerTaxId, false, $isConsolidatedInvoice),
                    'Contact' => [[
                        'Telephone' => [['_' => $isGeneralBuyerTin
                            ? $this->safeConfig('compliance.malaysia.default_buyer_phone', '+60000000000')
                            : $this->normalizePhoneOrDefault(
                                $buyer['contact']['phone'] ?? null,
                                $buyer['phone'] ?? null,
                                $this->safeConfig('compliance.malaysia.default_buyer_phone', '+60000000000')
                            )]],
                        'ElectronicMail' => [['_' => $isGeneralBuyerTin
                            ? $this->safeConfig('compliance.malaysia.default_buyer_email', 'customer@example.com')
                            : ($this->normalizeBuyerEmail($buyer['contact']['email'] ?? null)
                                ?? $this->normalizeBuyerEmail($buyer['email'] ?? null)
                                ?? $this->safeConfig('compliance.malaysia.default_buyer_email', 'customer@example.com'))]],
                    ]],
                ]],
            ]],
            'TaxTotal' => [[
                'TaxAmount' => [['_' => $this->formatDecimal($totalTax), 'currencyID' => $currency]],
                'TaxSubtotal' => $taxSubtotals,
            ]],
            'LegalMonetaryTotal' => [[
                'LineExtensionAmount' => [['_' => $this->formatDecimal($totalExcludingTax), 'currencyID' => $currency]],
                'TaxExclusiveAmount' => [['_' => $this->formatDecimal($totalExcludingTax), 'currencyID' => $currency]],
                'TaxInclusiveAmount' => [['_' => $this->formatDecimal($totalIncludingTax), 'currencyID' => $currency]],
                'PayableAmount' => [['_' => $this->formatDecimal($totalIncludingTax), 'currencyID' => $currency]],
            ]],
            'InvoiceLine' => ! empty($invoiceLines) ? $invoiceLines : [[
                'ID' => [['_' => '1']],
                'InvoicedQuantity' => [['_' => '1', 'unitCode' => 'C62']],
                'LineExtensionAmount' => [['_' => '0.00', 'currencyID' => $currency]],
                'TaxTotal' => [[
                    'TaxAmount' => [['_' => '0.00', 'currencyID' => $currency]],
                    'TaxSubtotal' => [[
                        'TaxableAmount' => [['_' => '0.00', 'currencyID' => $currency]],
                        'TaxAmount' => [['_' => '0.00', 'currencyID' => $currency]],
                        'TaxCategory' => [[
                            'ID' => [['_' => 'E']],
                            'TaxScheme' => [[
                                'ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']],
                            ]],
                        ]],
                    ]],
                ]],
                'Item' => [[
                    'CommodityClassification' => [[
                        'ItemClassificationCode' => [['_' => '001', 'listID' => 'CLASS']],
                    ]],
                    'Description' => [['_' => 'Line Item']],
                ]],
                'Price' => [[
                    'PriceAmount' => [['_' => '0.00', 'currencyID' => $currency]],
                ]],
                'ItemPriceExtension' => [[
                    'Amount' => [['_' => '0.00', 'currencyID' => $currency]],
                ]],
            ]],
        ];

        if ($shouldIncludeSignature) {
            $invoice['UBLExtensions'] = [[
                'UBLExtension' => [[
                    'ExtensionURI' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']],
                    'ExtensionContent' => [[
                        'Signature' => [[
                            'ID' => [['_' => $signatureId]],
                            'SignatureMethod' => [['_' => (string) ($signatureMetadata['signature_method'] ?? 'RSA-SHA256')]],
                            'DigestMethod' => [['_' => (string) ($signatureMetadata['digest_method'] ?? 'SHA-256')]],
                            'DigestValue' => [['_' => $digestValue]],
                            'SignatureValue' => [['_' => $signatureValue]],
                            'SigningTime' => [['_' => $signedAt]],
                        ]],
                    ]],
                ]],
            ]];

            $invoice['Signature'] = [[
                'ID' => [['_' => $signatureId]],
                'Note' => [['_' => 'Issuer digital signature']],
                'SignatoryParty' => [[
                    'PartyIdentification' => [[
                        'ID' => [['_' => (string) ($certificate['serial_number'] ?? $signatureId), 'schemeID' => 'X509_SERIAL']],
                    ]],
                    'PartyName' => [[
                        'Name' => [['_' => $signatoryName]],
                    ]],
                ]],
                'DigitalSignatureAttachment' => [[
                    'ExternalReference' => [[
                        'URI' => [['_' => '#'.$signatureId]],
                    ]],
                ]],
            ]];
        }

        if ($totalDiscount > 0) {
            $invoice['LegalMonetaryTotal'][0]['AllowanceTotalAmount'] = [['_' => $this->formatDecimal($totalDiscount), 'currencyID' => $currency]];
        }

        if (! empty($payload['payment_terms'])) {
            $invoice['PaymentTerms'] = [[
                'Note' => [['_' => (string) $payload['payment_terms']]],
            ]];
        }

        // Normalise original invoice references into a flat list.
        // Accepts either 'original_invoice_references' (array of {invoice_number, uuid})
        // or the legacy scalar 'original_invoice_number' / 'original_invoice_uuid'.
        $originalRefs = [];
        if (! empty($payload['original_invoice_references']) && is_array($payload['original_invoice_references'])) {
            $originalRefs = array_values(array_filter($payload['original_invoice_references'], 'is_array'));
        } elseif (! empty($payload['original_invoice_number'])) {
            $originalRefs = [[
                'invoice_number' => $payload['original_invoice_number'],
                'uuid' => $payload['original_invoice_uuid'] ?? null,
            ]];
        }

        if (! empty($originalRefs)) {
            // Credit/Debit/Refund notes (02–04, 12–14) must reference the original invoice
            // via InvoiceDocumentReference, which supports the IRBM UUID.
            // Each original invoice gets its own BillingReference entry (LHDN allows multiple).
            $isNoteType = in_array($docTypeCode, ['02', '03', '04', '12', '13', '14'], true);
            if ($isNoteType) {
                $invoice['BillingReference'] = array_values(array_map(static function (array $ref): array {
                    $docRef = ['ID' => [['_' => (string) ($ref['invoice_number'] ?? $ref['id'] ?? '')]]];
                    if (! empty($ref['uuid'])) {
                        $docRef['UUID'] = [['_' => (string) $ref['uuid']]];
                    }

                    return ['InvoiceDocumentReference' => [$docRef]];
                }, $originalRefs));
            } else {
                $invoice['BillingReference'] = [[
                    'AdditionalDocumentReference' => [[
                        'ID' => [['_' => (string) ($originalRefs[0]['invoice_number'] ?? $originalRefs[0]['id'] ?? '')]],
                    ]],
                ]];
            }
        }

        return [
            '_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            '_A' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            '_B' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'Invoice' => [$invoice],
        ];
    }

    private function buildPartyIdentification(array $party, string $registrationScheme, bool $isSupplier, bool $isConsolidatedBuyer = false): array
    {
        $identifications = [];

        $tin = (string) ($party['tin'] ?? 'EI00000000010');
        $usesGeneralTin = strtoupper($tin) === 'EI00000000010';
        $identifications[] = [
            'ID' => [['_' => $tin, 'schemeID' => 'TIN']],
        ];

        $registrationValue = $this->normalizeIdentifierValue($party['company_registration'] ?? $party['identity_number'] ?? null);
        if (! $isSupplier && $isConsolidatedBuyer && $usesGeneralTin) {
            $registrationValue = 'NA';
        }

        $identifications[] = [
            'ID' => [['_' => $registrationValue ?? 'NA', 'schemeID' => $registrationScheme]],
        ];

        $sst = $this->normalizeIdentifierValue($party['sst_registration'] ?? null) ?? 'NA';
        $identifications[] = [
            'ID' => [['_' => $sst, 'schemeID' => 'SST']],
        ];

        $tourismTax = $this->normalizeIdentifierValue($party['tourism_tax_registration'] ?? null) ?? 'NA';
        $identifications[] = [
            'ID' => [['_' => $tourismTax, 'schemeID' => 'TTX']],
        ];

        return $identifications;
    }

    private function buildPostalAddress(array $address, bool $allowConsolidatedMalaysiaStateFallback = false): array
    {
        $country = $this->normalizeCountryCode($address['country'] ?? null);
        $state = $this->normalizeCountrySubentityCode($address['state'] ?? null, $country, $allowConsolidatedMalaysiaStateFallback);
        $city = $this->normalizeIdentifierValue($address['city'] ?? null) ?? 'NA';
        $postalCode = $this->normalizeIdentifierValue($address['postal_code'] ?? null) ?? '00000';

        $addressLines = [];
        foreach ([
            $this->stringOrDefault($address['street'] ?? null, 'NA'),
            $address['street_2'] ?? null,
            $address['street_3'] ?? null,
        ] as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $addressLines[] = [
                'Line' => [['_' => trim($line)]],
            ];
        }

        if (empty($addressLines)) {
            $addressLines[] = ['Line' => [['_' => 'NA']]];
        }

        $postalAddress = [
            'CountrySubentityCode' => [['_' => $state]],
            'AddressLine' => $addressLines,
            'CityName' => [['_' => $city]],
            'PostalZone' => [['_' => $postalCode]],
            'Country' => [[
                'IdentificationCode' => [['_' => $country, 'listID' => 'ISO3166-1', 'listAgencyID' => '6']],
            ]],
        ];

        return $postalAddress;
    }

    private function normalizeCountryCode(mixed $value): string
    {
        if (! is_string($value)) {
            return 'MYS';
        }

        return match (strtoupper(trim($value))) {
            '', 'MY', 'MYS', 'MALAYSIA' => 'MYS',
            'SG', 'SGP', 'SINGAPORE' => 'SGP',
            'US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA' => 'USA',
            default => strtoupper(trim($value)),
        };
    }

    private function normalizeCountrySubentityCode(mixed $value, string $country, bool $allowConsolidatedMalaysiaStateFallback = false): string
    {
        $normalized = $this->normalizeIdentifierValue($value);
        if ($normalized !== null) {
            $upper = strtoupper($normalized);

            // Accept already-valid numeric codes.
            if (preg_match('/^\d{1,2}$/', $upper) === 1) {
                return str_pad($upper, 2, '0', STR_PAD_LEFT);
            }

            // Map common MY state names/abbreviations to MyInvois state codes.
            $myStateMap = [
                'JOHOR' => '01', 'JHR' => '01',
                'KEDAH' => '02', 'KDH' => '02',
                'KELANTAN' => '03', 'KTN' => '03',
                'MELAKA' => '04', 'MALACCA' => '04', 'MLK' => '04',
                'NEGERI SEMBILAN' => '05', 'N9' => '05', 'NSN' => '05',
                'PAHANG' => '06', 'PHG' => '06',
                'PENANG' => '07', 'PULAU PINANG' => '07', 'PNG' => '07',
                'PERAK' => '08', 'PRK' => '08',
                'PERLIS' => '09', 'PLS' => '09',
                'SELANGOR' => '10', 'SGR' => '10',
                'TERENGGANU' => '11', 'TRG' => '11',
                'SABAH' => '12', 'SBH' => '12',
                'SARAWAK' => '13', 'SWK' => '13',
                'WILAYAH PERSEKUTUAN KUALA LUMPUR' => '14', 'KUALA LUMPUR' => '14', 'KL' => '14',
                'WILAYAH PERSEKUTUAN LABUAN' => '15', 'LABUAN' => '15',
                'WILAYAH PERSEKUTUAN PUTRAJAYA' => '16', 'PUTRAJAYA' => '16',
                'NOT APPLICABLE' => '17', 'N/A' => '17',
            ];

            if (isset($myStateMap[$upper])) {
                return $myStateMap[$upper];
            }

            // If non-MY country, pass-through string identifiers.
            if ($country !== 'MYS') {
                return $normalized;
            }
        }

        if ($country === 'MYS' && $allowConsolidatedMalaysiaStateFallback) {
            return '17';
        }

        // Default to Kuala Lumpur for missing MY non-consolidated state to avoid invalid "NA" code.
        if ($country === 'MYS') {
            return '14';
        }

        return 'NA';
    }

    private function isConsolidatedInvoicePayload(array $payload): bool
    {
        if (($payload['is_consolidated'] ?? false) === true) {
            return true;
        }

        if (($payload['document_type'] ?? null) === 'consolidated_invoice') {
            return true;
        }

        foreach ([
            $payload['classification_code'] ?? null,
            $payload['invoice_classification_code'] ?? null,
            $payload['document_classification_code'] ?? null,
            $payload['invoice_type_code'] ?? null,
            $payload['document_type_code'] ?? null,
            $payload['type_code'] ?? null,
        ] as $candidate) {
            if ((string) $candidate === '004') {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps internal document_type strings to LHDN e-Invoice type codes.
     * Invoices (individual and consolidated) both use '01'; the buyer TIN
     * EI00000000010 distinguishes consolidated at the LHDN level.
     * Self-billed types use 11–14 per LHDN e-Invoice API v1.0 spec.
     */
    private function resolveDocumentTypeCode(string $documentType): string
    {
        return match ($documentType) {
            'credit_note' => '02',
            'debit_note' => '03',
            'refund_note' => '04',
            'self_billed_invoice' => '11',
            'self_billed_credit_note' => '12',
            'self_billed_debit_note' => '13',
            'self_billed_refund_note' => '14',
            default => '01',
        };
    }

    private function formatDecimal(float $value): float
    {
        return round($value, 2);
    }

    private function formatQuantity(float $value): int|float
    {
        if (fmod($value, 1.0) === 0.0) {
            return (int) round($value);
        }

        return (float) rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : $default;
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function normalizeBuyerEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $trimmed;
    }

    private function resolveBuyerTinValue(array $buyer): string
    {
        foreach ([
            $buyer['tin'] ?? null,
            $buyer['tax_identification_number'] ?? null,
            $buyer['tax_number'] ?? null,
            $buyer['tax_id'] ?? null,
            $buyer['company_registration'] ?? null,
            $buyer['identity_number'] ?? null,
            $this->safeConfig('compliance.malaysia.default_buyer_tin', null),
            env('COMPLIANCE_MY_DEFAULT_BUYER_TIN'),
        ] as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $normalized = strtoupper(trim((string) $candidate));
            if ($normalized !== '' && $normalized !== 'NA') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizePhoneOrDefault(mixed ...$values): string
    {
        $default = '+60000000000';
        if (! empty($values)) {
            $last = $values[count($values) - 1];
            if (is_string($last) && trim($last) !== '') {
                $default = trim($last);
            }
        }

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '' || strtoupper($trimmed) === 'NA') {
                continue;
            }

            return $trimmed;
        }

        return $default;
    }

    private function normalizeIdentifierValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strtoupper($trimmed) === 'NA') {
            return null;
        }

        return $trimmed;
    }

    private function normalizeUnitCode(mixed $value): string
    {
        if (! is_string($value)) {
            return 'C62';
        }

        $unitCode = strtoupper(trim($value));
        if ($unitCode === '' || $unitCode === 'BX') {
            return 'C62';
        }

        return $unitCode;
    }

    private function resolveTaxTypeCode(?string $identityType, bool $hasCompanyRegistration): string
    {
        $identityType = strtoupper((string) $identityType);
        if ($identityType !== '') {
            return $identityType;
        }

        return $hasCompanyRegistration ? 'BRN' : 'NRIC';
    }

    private function formatIssueTime(string $issueTime): string
    {
        if (preg_match('/T(\d{2}:\d{2}:\d{2})/', $issueTime, $matches) === 1) {
            return $matches[1].'Z';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}Z?$/', $issueTime) === 1) {
            return rtrim($issueTime, 'Z').'Z';
        }

        return '00:00:00Z';
    }

    private function resolveIssueDateTime(array $payload): array
    {
        $nowUtc = Carbon::now('UTC');

        return [
            // MyInvois SDK requires IssueDate and IssueTime to be current UTC values.
            'invoice_date' => $nowUtc->toDateString(),
            'issue_time' => $nowUtc->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }

    private function resolvePrimaryDocumentSummary(array $submissionData): array
    {
        $documentSummary = $submissionData['documentSummary'] ?? $submissionData['documents'] ?? [];
        if (! is_array($documentSummary)) {
            return [];
        }

        $firstDocument = $documentSummary[0] ?? [];

        return is_array($firstDocument) ? $firstDocument : [];
    }

    private function fetchDocumentDetails(string $documentUuid, array $credentials): ?array
    {
        try {
            $response = $this->client->get("{$this->endpoint}/api/v1.0/documents/{$documentUuid}/details", [
                'headers' => $this->buildHeaders($credentials),
                'timeout' => 10,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) ? $data : null;
        } catch (RequestException $e) {
            Log::warning('MyInvois document details lookup failed', [
                'document_uuid' => $documentUuid,
                'error' => $e->getMessage(),
                'response' => $this->decodeExceptionResponse($e),
            ]);

            return null;
        }
    }

    private function buildValidationLink(?string $documentUuid, ?string $longId): ?string
    {
        if ($documentUuid === null || $documentUuid === '' || $longId === null || $longId === '') {
            return null;
        }

        $portalBase = str_replace('://api.', '://', str_replace('://preprod-api.', '://preprod.', $this->endpoint));

        return rtrim($portalBase, '/').'/'.rawurlencode($documentUuid).'/share/'.rawurlencode($longId);
    }

    private function extractValidationErrors(array $details): array
    {
        $validationResults = $details['validationResults'] ?? [];
        if (! is_array($validationResults)) {
            return [];
        }

        $steps = $validationResults['validationSteps'] ?? [];
        if (! is_array($steps)) {
            return [];
        }

        $errors = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $stepName = (string) ($step['name'] ?? 'Validation');
            $stepStatus = strtolower((string) ($step['status'] ?? ''));
            if ($stepStatus !== 'invalid') {
                continue;
            }

            $errorPayload = $step['error'] ?? [];
            $nestedErrors = is_array($errorPayload['error'] ?? null)
                ? [$errorPayload['error']]
                : (is_array($errorPayload['errors'] ?? null) ? $errorPayload['errors'] : []);

            if (empty($nestedErrors) && is_array($errorPayload)) {
                $nestedErrors = [$errorPayload];
            }

            foreach ($nestedErrors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $message = trim((string) ($error['message'] ?? $error['error'] ?? ''));
                if ($message === '') {
                    continue;
                }

                $errors[] = [
                    'step' => $stepName,
                    'code' => $error['code'] ?? null,
                    'message' => $message,
                ];
            }
        }

        return $errors;
    }

    private function extractErrorDetails(array $responseData, string $fallbackMessage = 'MyInvoice submission failed'): array
    {
        $nestedError = is_array($responseData['error'] ?? null) ? $responseData['error'] : [];
        $detailMessages = [];
        if (isset($nestedError['details']) && is_array($nestedError['details'])) {
            foreach ($nestedError['details'] as $detail) {
                if (! is_array($detail)) {
                    continue;
                }

                $path = (string) ($detail['target'] ?? $detail['propertyPath'] ?? '');
                $message = (string) ($detail['message'] ?? $detail['error'] ?? '');
                $combined = trim($path !== '' ? "{$path}: {$message}" : $message);
                if ($combined !== '') {
                    $detailMessages[] = $combined;
                }
            }
        }

        $detailMessage = ! empty($detailMessages) ? implode('; ', $detailMessages) : null;

        $rawError = [
            'code' => $responseData['error_code']
                ?? $responseData['response_code']
                ?? $nestedError['code']
                ?? null,
            'message' => $detailMessage
                ?? $responseData['message']
                ?? $nestedError['message']
                ?? $responseData['error_message']
                ?? $responseData['reason']
                ?? $fallbackMessage,
            'reason' => $responseData['reason']
                ?? $nestedError['target']
                ?? null,
        ];

        if (isset($responseData['errors']) && is_array($responseData['errors']) && ! empty($responseData['errors'])) {
            $firstError = $responseData['errors'][0];
            if (is_array($firstError)) {
                $rawError = array_merge($rawError, $firstError);
            }
        }

        $mapped = $this->mapFinanceErrorCategory($rawError);

        return [
            'code' => $mapped['code'],
            'message' => $mapped['message'],
            'category' => $mapped['category'],
            'finance_action' => $mapped['finance_action'],
        ];
    }

    /**
     * Safely resolve config values in both Laravel runtime and plain unit tests.
     */
    private function safeConfig(string $key, mixed $default = null): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Sign a mapped payload with the configured organisation certificate (XAdES / RSA-SHA256).
     * Called by ComplianceService::prepareDocumentArtifacts() before submission.
     */
    public function signDocument(array $payload, array $context): array
    {
        $signingContext = [
            'signature_enabled' => (bool) (
                $context['signature_enabled']
                ?? $this->safeConfig('compliance.malaysia.signature_enabled', false)
            ),
            'signature_use_for_submission' => (bool) (
                $context['signature_use_for_submission']
                ?? $this->safeConfig('compliance.malaysia.signature_use_for_submission', false)
            ),
            'signature_private_key' => (string) (
                $context['signature_private_key']
                ?? $this->safeConfig('compliance.malaysia.signature_private_key', '')
            ),
            'signature_private_key_passphrase' => (string) (
                $context['signature_private_key_passphrase']
                ?? $this->safeConfig('compliance.malaysia.signature_private_key_passphrase', '')
            ),
            'signature_certificate' => (string) (
                $context['signature_certificate']
                ?? $this->safeConfig('compliance.malaysia.signature_certificate', '')
            ),
            'signature_key_id' => (string) (
                $context['signature_key_id']
                ?? $this->safeConfig('compliance.malaysia.signature_key_id', '')
            ),
            'signature_name' => (string) (
                $context['signature_name']
                ?? $this->safeConfig('compliance.malaysia.signature_name', '')
            ),
        ];

        $this->safeLog('info', 'myinvois:signDocument — entry', [
            'invoice_id' => $payload['invoice_id'] ?? null,
            'invoice_number' => $payload['invoice_number'] ?? null,
            'signature_enabled' => $signingContext['signature_enabled'],
            'use_for_submission' => $signingContext['signature_use_for_submission'],
            'has_private_key' => $signingContext['signature_private_key'] !== '',
            'has_certificate' => $signingContext['signature_certificate'] !== '',
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
        ]);

        $ublDocument = $this->buildUblDocumentFromMappedPayload($payload);
        $signatureService = new MyInvoiceDigitalSignatureService;
        $result = $signatureService->sign($ublDocument, $signingContext);

        $this->safeLog('info', 'myinvois:signDocument — complete', [
            'invoice_id' => $payload['invoice_id'] ?? null,
            'success' => $result['success'],
            'used_for_submission' => $result['used_for_submission'] ?? false,
            'signature_status' => $result['signature_metadata']['status'] ?? null,
            'error_message' => $result['message'] ?? null,
        ]);

        return $result;
    }

    /**
     * Provider configuration requirements for MY MyInvois.
     */
    public function getConfigRequirements(): array
    {
        return [
            'fields' => ['myinvoice_client_id', 'myinvoice_client_secret'],
            'description' => 'MyInvois OAuth client credentials used to mint bearer tokens for submission',
        ];
    }
}
