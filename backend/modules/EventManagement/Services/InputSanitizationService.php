<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\Log;

/**
 * Input Sanitization Service
 *
 * Provides comprehensive input sanitization and validation
 * to prevent injection attacks and ensure data integrity.
 *
 * Requirements: 8.5 - Input sanitization validation
 */
class InputSanitizationService
{
    /**
     * Dangerous HTML tags that should be stripped
     */
    private const DANGEROUS_TAGS = [
        'script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea',
        'select', 'button', 'link', 'meta', 'style', 'base', 'applet',
    ];

    /**
     * Allowed HTML tags for rich text content
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'a', 'img', 'div', 'span',
    ];

    /**
     * Allowed attributes for HTML tags
     */
    private const ALLOWED_ATTRIBUTES = [
        'href', 'src', 'alt', 'title', 'class', 'id', 'style',
    ];

    /**
     * File upload MIME types whitelist
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Maximum file size in bytes (5MB)
     */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Sanitize string input to prevent XSS attacks
     */
    public function sanitizeString(string $input, bool $allowHtml = false): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Trim whitespace
        $input = trim($input);

        // Remove control characters except newlines and tabs
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);

        if ($allowHtml) {
            return $this->sanitizeHtml($input);
        }

        // For non-HTML content, escape all HTML entities
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitize HTML content while preserving safe tags
     */
    public function sanitizeHtml(string $html): string
    {
        // Remove dangerous tags completely
        foreach (self::DANGEROUS_TAGS as $tag) {
            $html = preg_replace('/<'.$tag.'\b[^>]*>.*?<\/'.$tag.'>/is', '', $html);
            $html = preg_replace('/<'.$tag.'\b[^>]*\/?>/i', '', $html);
        }

        // Remove javascript: and data: URLs
        $html = preg_replace('/javascript:/i', '', $html);
        $html = preg_replace('/data:/i', '', $html);

        // Remove on* event handlers
        $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);

        // Clean up attributes
        $html = $this->sanitizeHtmlAttributes($html);

        return $html;
    }

    /**
     * Sanitize HTML attributes
     */
    private function sanitizeHtmlAttributes(string $html): string
    {
        // Use DOMDocument for proper HTML parsing
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*');

        foreach ($nodes as $node) {
            if ($node->hasAttributes()) {
                $attributesToRemove = [];

                foreach ($node->attributes as $attribute) {
                    if (! in_array($attribute->name, self::ALLOWED_ATTRIBUTES)) {
                        $attributesToRemove[] = $attribute->name;
                    } else {
                        // Sanitize attribute values
                        $value = $this->sanitizeAttributeValue($attribute->value);
                        $node->setAttribute($attribute->name, $value);
                    }
                }

                foreach ($attributesToRemove as $attrName) {
                    $node->removeAttribute($attrName);
                }
            }
        }

        return $dom->saveHTML();
    }

    /**
     * Sanitize HTML attribute values
     */
    private function sanitizeAttributeValue(string $value): string
    {
        // Remove javascript: and data: protocols
        $value = preg_replace('/^(javascript|data|vbscript):/i', '', $value);

        // Remove any script-like content
        $value = preg_replace('/[<>"\']/', '', $value);

        return trim($value);
    }

    /**
     * Validate and sanitize file upload
     */
    public function validateFileUpload($file): array
    {
        $errors = [];

        if (! $file || ! $file->isValid()) {
            $errors[] = 'Invalid file upload';

            return ['valid' => false, 'errors' => $errors];
        }

        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = 'File size exceeds maximum allowed size of '.(self::MAX_FILE_SIZE / 1024 / 1024).'MB';
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            $errors[] = 'File type not allowed. Allowed types: '.implode(', ', self::ALLOWED_MIME_TYPES);
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'xls', 'xlsx'];
        if (! in_array($extension, $allowedExtensions)) {
            $errors[] = 'File extension not allowed';
        }

        // Check for executable files
        $executableExtensions = ['exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar', 'php', 'asp', 'jsp'];
        if (in_array($extension, $executableExtensions)) {
            $errors[] = 'Executable files are not allowed';
        }

        // Validate file content (basic check)
        if (! $this->validateFileContent($file)) {
            $errors[] = 'File content validation failed';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized_filename' => $this->sanitizeFilename($file->getClientOriginalName()),
        ];
    }

    /**
     * Validate file content to prevent malicious files
     */
    private function validateFileContent($file): bool
    {
        $content = file_get_contents($file->getRealPath());

        // Check for PHP tags in uploaded files
        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            return false;
        }

        // Check for script tags
        if (preg_match('/<script\b[^>]*>/i', $content)) {
            return false;
        }

        // Check for suspicious patterns
        $suspiciousPatterns = [
            '/eval\s*\(/i',
            '/exec\s*\(/i',
            '/system\s*\(/i',
            '/shell_exec\s*\(/i',
            '/passthru\s*\(/i',
            '/base64_decode\s*\(/i',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize filename for safe storage
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove path traversal attempts
        $filename = basename($filename);

        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Prevent hidden files
        $filename = ltrim($filename, '.');

        // Ensure filename is not empty
        if (empty($filename)) {
            $filename = 'file_'.time();
        }

        // Limit filename length
        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = substr(pathinfo($filename, PATHINFO_FILENAME), 0, 250 - strlen($extension));
            $filename = $name.'.'.$extension;
        }

        return $filename;
    }

    /**
     * Sanitize SQL input to prevent injection
     */
    public function sanitizeSqlInput(string $input): string
    {
        // Remove SQL injection patterns
        $patterns = [
            '/(\s*(union|select|insert|update|delete|drop|create|alter|exec|execute)\s+)/i',
            '/(\s*(or|and)\s+\d+\s*=\s*\d+)/i',
            '/(\s*;\s*)/i',
            '/(\s*--\s*)/i',
            '/(\s*\/\*.*?\*\/\s*)/i',
        ];

        foreach ($patterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        return trim($input);
    }

    /**
     * Validate email address
     */
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL
     */
    public function validateUrl(string $url): bool
    {
        $url = filter_var($url, FILTER_VALIDATE_URL);

        if (! $url) {
            return false;
        }

        // Only allow HTTP and HTTPS protocols
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https']);
    }

    /**
     * Sanitize array recursively
     */
    public function sanitizeArray(array $data, bool $allowHtml = false): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $sanitizedKey = $this->sanitizeString($key);

            if (is_array($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeArray($value, $allowHtml);
            } elseif (is_string($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeString($value, $allowHtml);
            } else {
                $sanitized[$sanitizedKey] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Log security violation
     */
    public function logSecurityViolation(string $type, string $input, array $context = []): void
    {
        Log::warning('Input sanitization security violation', [
            'type' => $type,
            'input' => substr($input, 0, 200), // Limit logged input length
            'context' => $context,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);
    }
}
