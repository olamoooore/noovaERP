<?php

namespace Modules\FinancialClose\Contracts;

interface AiOutputContract
{
    /**
     * Validate structured AI output
     * Returns validated data or throws if malformed
     */
    public function validate(array $output, array $requiredFields): array;

    /**
     * Check if confidence score meets minimum threshold
     */
    public function meetsConfidenceThreshold(float $confidence, float $threshold): bool;

    /**
     * Sanitize AI output text to prevent injection
     */
    public function sanitize(string $text): string;
}
