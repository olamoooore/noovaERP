<?php

namespace App\Accounting\Contracts;

/**
 * Submits compliance documents to government/provider endpoints
 * Handles transport, signing, and acknowledgment
 */
interface SubmissionProviderContract
{
    /**
     * Submit document to provider
     * 
     * @param array $payload The formatted document
     * @param array $credentials Provider authentication
     * @return array ['success' => bool, 'provider_reference' => string|null, 'message' => string]
     */
    public function submit(array $payload, array $credentials): array;

    /**
     * Get submission status from provider
     * 
     * @param string $providerReference Reference from submit response
     * @param array $credentials
     * @return array ['status' => string, 'message' => string, 'details' => array]
     */
    public function getStatus(string $providerReference, array $credentials): array;

    /**
     * Cancel a submission
     * 
     * @param string $providerReference
     * @param array $credentials
     * @return array ['success' => bool, 'message' => string]
     */
    public function cancel(string $providerReference, array $credentials): array;

    /**
     * Retry failed submission
     * 
     * @param array $payload
     * @param string $providerReference
     * @param array $credentials
     * @return array
     */
    public function retry(array $payload, string $providerReference, array $credentials): array;

    /**
     * Get provider configuration requirements
     * 
     * @return array ['fields' => string[], 'description' => string]
     */
    public function getConfigRequirements(): array;
}
