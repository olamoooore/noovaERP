<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Contracts\AiOutputContract;

class AiOutputValidationService implements AiOutputContract
{
    /**
     * Validate structured AI output
     * Returns validated data or throws if malformed
     */
    public function validate(array $output, array $requiredFields): array
    {
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $output)) {
                Log::warning('FinancialClose: AI output missing required field', [
                    'field' => $field,
                    'output_keys' => array_keys($output),
                ]);
                throw new \InvalidArgumentException(
                    "AI output missing required field: {$field}"
                );
            }

            if ($output[$field] === null || $output[$field] === '') {
                Log::warning('FinancialClose: AI output field is empty', [
                    'field' => $field,
                ]);
                throw new \InvalidArgumentException(
                    "AI output field must not be empty: {$field}"
                );
            }
        }

        return $output;
    }

    /**
     * Check if confidence score meets minimum threshold
     */
    public function meetsConfidenceThreshold(float $confidence, float $threshold = 0.80): bool
    {
        return $confidence >= $threshold;
    }

    /**
     * Sanitize AI output text to prevent injection
     */
    public function sanitize(string $text): string
    {
        return strip_tags(trim($text));
    }
}