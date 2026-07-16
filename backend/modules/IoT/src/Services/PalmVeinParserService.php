<?php

namespace Modules\IoT\Services;

/**
 * Service for parsing and validating palm vein biometric data.
 *
 * Handles:
 * - Palm vein template parsing
 * - Quality score validation
 * - Match score interpretation
 * - Biometric data normalization
 */
class PalmVeinParserService
{
    /**
     * Parse raw palm vein data into structured format.
     */
    public function parse(string $rawData): array
    {
        // Try to decode as JSON first (structured data from agent)
        $decoded = json_decode($rawData, true);

        if ($decoded && is_array($decoded)) {
            return $this->parseStructuredData($decoded);
        }

        // Try to decode as base64 template
        if ($this->isBase64($rawData)) {
            return [
                'type' => 'palm_vein',
                'data' => [
                    'template' => $rawData,
                    'quality' => null,
                    'matchScore' => null,
                    'format' => 'base64_template',
                ],
                'raw' => $rawData,
            ];
        }

        // Try to decode as hex string
        if ($this->isHexString($rawData)) {
            return [
                'type' => 'palm_vein',
                'data' => [
                    'template' => $rawData,
                    'quality' => null,
                    'matchScore' => null,
                    'format' => 'hex_template',
                ],
                'raw' => $rawData,
            ];
        }

        // Fallback: treat as raw template data
        return [
            'type' => 'palm_vein',
            'data' => [
                'template' => $rawData,
                'quality' => null,
                'matchScore' => null,
                'format' => 'raw',
            ],
            'raw' => $rawData,
        ];
    }

    /**
     * Parse structured biometric data from agent.
     */
    private function parseStructuredData(array $data): array
    {
        $parsed = [
            'type' => 'palm_vein',
            'template' => $data['template'] ?? null,
            'quality' => isset($data['quality']) ? (int) $data['quality'] : null,
            'matchScore' => isset($data['matchScore']) ? (int) $data['matchScore'] : null,
            'timestamp' => $data['timestamp'] ?? null,
        ];

        // Validate quality score (0-100)
        if ($parsed['quality'] !== null) {
            $parsed['quality'] = max(0, min(100, $parsed['quality']));
        }

        // Validate match score (0-100)
        if ($parsed['matchScore'] !== null) {
            $parsed['matchScore'] = max(0, min(100, $parsed['matchScore']));
        }

        return [
            'type' => 'palm_vein',
            'data' => $parsed,
            'raw' => json_encode($data),
        ];
    }

    /**
     * Check if string is valid base64.
     */
    private function isBase64(string $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check if it looks like base64 (alphanumeric + / + = + -)
        if (! preg_match('/^[A-Za-z0-9+\/\-_]*={0,2}$/', $data)) {
            return false;
        }

        // Try to decode
        $decoded = base64_decode($data, true);

        return $decoded !== false;
    }

    /**
     * Check if string is valid hex.
     */
    private function isHexString(string $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Must be even length and only hex characters
        return strlen($data) % 2 === 0 && ctype_xdigit($data);
    }

    /**
     * Get quality assessment.
     */
    public function getQualityAssessment(?int $quality): string
    {
        if ($quality === null) {
            return 'unknown';
        }

        if ($quality >= 80) {
            return 'excellent';
        }

        if ($quality >= 60) {
            return 'good';
        }

        if ($quality >= 40) {
            return 'fair';
        }

        return 'poor';
    }

    /**
     * Get match result interpretation.
     */
    public function getMatchResult(?int $matchScore): string
    {
        if ($matchScore === null) {
            return 'no_match';
        }

        if ($matchScore >= 90) {
            return 'strong_match';
        }

        if ($matchScore >= 70) {
            return 'match';
        }

        if ($matchScore >= 50) {
            return 'weak_match';
        }

        return 'no_match';
    }

    /**
     * Detect format from raw data.
     */
    public function detectFormat(string $data): string
    {
        // Check if JSON
        if (json_decode($data) !== null) {
            return 'PALM_VEIN_JSON';
        }

        // Check if base64
        if ($this->isBase64($data)) {
            return 'PALM_VEIN_BASE64';
        }

        // Check if hex
        if ($this->isHexString($data)) {
            return 'PALM_VEIN_HEX';
        }

        return 'PALM_VEIN';
    }
}
