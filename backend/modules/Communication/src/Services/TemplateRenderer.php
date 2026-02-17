<?php

namespace Modules\Communication\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Communication\Models\CommunicationTemplate;

/**
 * TemplateRenderer — Renders message templates with variable injection.
 *
 * Handles template lookup, caching, and variable replacement.
 * Supports {{variable.path}} syntax and nested data access.
 */
class TemplateRenderer
{
    /**
     * Render a template by key with given variables.
     *
     * @param string $templateKey  e.g. "invoice.sent"
     * @param array $variables     e.g. ['customer' => ['name' => 'John'], 'invoice' => ['number' => 'INV-001']]
     * @param string $language     Language code, defaults to 'en'
     * @param string|null $tenantId
     * @return array{subject: string|null, body_html: string|null, body_plain: string|null}|null
     */
    public function render(string $templateKey, array $variables = [], string $language = 'en', ?string $tenantId = null): ?array
    {
        $template = $this->getTemplate($templateKey, $language, $tenantId);

        if (!$template) {
            return null;
        }

        return $template->render($this->flattenVariables($variables));
    }

    /**
     * Get a template by key, with caching.
     */
    public function getTemplate(string $templateKey, string $language = 'en', ?string $tenantId = null): ?CommunicationTemplate
    {
        $cacheEnabled = config('communication.templates.cache', true);
        $cacheTtl = config('communication.templates.cache_ttl', 3600);
        $cacheKey = "comm_tpl:{$tenantId}:{$templateKey}:{$language}";

        if ($cacheEnabled) {
            return Cache::remember($cacheKey, $cacheTtl, function () use ($templateKey, $language, $tenantId) {
                return $this->findTemplate($templateKey, $language, $tenantId);
            });
        }

        return $this->findTemplate($templateKey, $language, $tenantId);
    }

    /**
     * Find template from database.
     */
    protected function findTemplate(string $templateKey, string $language, ?string $tenantId): ?CommunicationTemplate
    {
        $query = CommunicationTemplate::active()
            ->forKey($templateKey)
            ->forLanguage($language);

        if ($tenantId) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id'); // Fall back to system template
            });
            $query->orderByRaw('tenant_id IS NULL ASC'); // Tenant-specific first
        }

        return $query->first();
    }

    /**
     * Flatten nested arrays for template variable replacement.
     *
     * ['customer' => ['name' => 'John']] => ['customer.name' => 'John']
     */
    protected function flattenVariables(array $variables, string $prefix = ''): array
    {
        $flat = [];

        foreach ($variables as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $flat = array_merge($flat, $this->flattenVariables($value, $fullKey));
            } elseif (is_object($value)) {
                // Handle Eloquent models — convert to array
                if (method_exists($value, 'toArray')) {
                    $flat = array_merge($flat, $this->flattenVariables($value->toArray(), $fullKey));
                }
            } else {
                $flat[$fullKey] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * Invalidate cached template.
     */
    public function clearCache(string $templateKey, string $language = 'en', ?string $tenantId = null): void
    {
        Cache::forget("comm_tpl:{$tenantId}:{$templateKey}:{$language}");
    }
}
