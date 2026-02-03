<?php

namespace Modules\IoT\Services;

/**
 * Service for parsing QR code data into structured formats.
 * 
 * Supports:
 * - JSON data
 * - URLs
 * - NoovaERP Product format (NOOVAERP:PROD:SKU:QTY:N)
 * - Plain text
 */
class QRCodeParserService
{
    /**
     * Parse raw QR code data into structured format.
     */
    public function parse(string $rawData): array
    {
        $rawData = trim($rawData);

        // Try JSON first
        if ($this->isJson($rawData)) {
            return [
                'type' => 'json',
                'data' => json_decode($rawData, true),
                'raw' => $rawData,
            ];
        }

        // Try URL
        if ($this->isUrl($rawData)) {
            return [
                'type' => 'url',
                'data' => $this->parseUrl($rawData),
                'raw' => $rawData,
            ];
        }

        // Try NoovaERP Product Format (NOOVAERP:PROD:SKU123:QTY:1)
        if ($this->isAceFormat($rawData)) {
            return [
                'type' => 'noovaerp_product',
                'data' => $this->parseAceFormat($rawData),
                'raw' => $rawData,
            ];
        }

        // Default: plain text
        return [
            'type' => 'text',
            'data' => ['value' => $rawData],
            'raw' => $rawData,
        ];
    }

    /**
     * Check if string is valid JSON.
     */
    private function isJson(string $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Must start with { or [ to be JSON
        $firstChar = $data[0];
        if ($firstChar !== '{' && $firstChar !== '[') {
            return false;
        }

        json_decode($data);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Check if string looks like a URL (valid or mangled by HID scanner).
     */
    private function isUrl(string $data): bool
    {
        // Standard URL check
        if (filter_var($data, FILTER_VALIDATE_URL)) {
            return true;
        }

        // Check if it looks like a mangled URL from HID scanner
        $upper = strtoupper($data);
        return str_starts_with($upper, 'HTTP') || 
               str_starts_with($upper, 'WWW') ||
               str_starts_with($upper, 'HTTPS');
    }

    /**
     * Try to normalize URL from HID scanner output.
     * Returns best-effort normalized URL or original if can't normalize.
     */
    private function normalizeUrl(string $data): string
    {
        // Already valid URL
        if (filter_var($data, FILTER_VALIDATE_URL)) {
            return $data;
        }

        $upper = strtoupper($data);
        $lower = strtolower($data);
        
        // Pattern: HTTPS or HTTP followed by content (missing ://)
        if (str_starts_with($upper, 'HTTPS') && !str_starts_with($upper, 'HTTPS://')) {
            // Extract the part after HTTPS
            $rest = substr($lower, 5);
            return 'https://' . $rest;
        }
        
        if (str_starts_with($upper, 'HTTP') && !str_starts_with($upper, 'HTTP://')) {
            $rest = substr($lower, 4);
            return 'http://' . $rest;
        }

        // WWW prefix without http
        if (str_starts_with($upper, 'WWW')) {
            return 'https://' . $lower;
        }

        return $data;
    }

    /**
     * Check if string is NoovaERP format.
     */
    private function isAceFormat(string $data): bool
    {
        return str_starts_with(strtoupper($data), 'NOOVAERP:');
    }

    /**
     * Parse URL into components.
     * Handles mangled URLs from HID scanners.
     */
    private function parseUrl(string $url): array
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $isNormalized = $normalizedUrl !== $url;
        
        // Try to parse the normalized URL
        $parsed = parse_url($normalizedUrl);
        
        $result = [
            'url' => $normalizedUrl,
            'scheme' => $parsed['scheme'] ?? null,
            'host' => $parsed['host'] ?? null,
            'path' => $parsed['path'] ?? null,
        ];
        
        // Include original if it was normalized
        if ($isNormalized) {
            $result['original'] = $url;
            $result['normalized'] = true;
            $result['note'] = 'URL was reconstructed from HID scanner output (may need manual verification)';
        }

        // Parse query string if present
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            $result['query'] = $queryParams;
        }

        return array_filter($result, fn($v) => $v !== null);
    }

    /**
     * Parse NoovaERP format string.
     * Format: NOOVAERP:TYPE:VALUE:FIELD:VALUE...
     * Example: NOOVAERP:PROD:SKU-12345:QTY:2:PRICE:29.99
     */
    private function parseAceFormat(string $data): array
    {
        $parts = explode(':', $data);
        $result = ['format' => noovaerp];

        // Skip NoovaERP prefix, process pairs
        for ($i = 1; $i < count($parts); $i += 2) {
            if (isset($parts[$i + 1])) {
                $key = strtolower($parts[$i]);
                $value = $parts[$i + 1];
                
                // Convert numeric values
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false 
                        ? (float) $value 
                        : (int) $value;
                }
                
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Detect barcode/QR format from content.
     */
    public function detectFormat(string $data): string
    {
        // EAN-13: 13 digits
        if (preg_match('/^\d{13}$/', $data)) {
            return 'EAN13';
        }

        // EAN-8: 8 digits
        if (preg_match('/^\d{8}$/', $data)) {
            return 'EAN8';
        }

        // UPC-A: 12 digits
        if (preg_match('/^\d{12}$/', $data)) {
            return 'UPCA';
        }

        // Code 128: alphanumeric (short)
        if (preg_match('/^[A-Za-z0-9\-\.\ \$\/\+\%]+$/', $data) && strlen($data) <= 48) {
            // But check if it looks like a URL first
            if ($this->isUrl($data)) {
                return 'QR';
            }
            return 'CODE128';
        }

        // JSON, URL, or NoovaERP format = QR
        if ($this->isJson($data) || $this->isUrl($data) || $this->isAceFormat($data)) {
            return 'QR';
        }

        // Default to QR for longer/complex data
        return strlen($data) > 48 ? 'QR' : 'UNKNOWN';
    }
}
