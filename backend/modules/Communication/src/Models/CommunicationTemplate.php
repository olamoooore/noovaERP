<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CommunicationTemplate — Reusable message templates.
 *
 * Templates with variable injection, linked to modules and events.
 * Example: "Hello {{customer.name}}, your invoice {{invoice.number}} is ready."
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $module
 * @property string|null $event
 * @property string|null $subject
 * @property string|null $body_html
 * @property string|null $body_plain
 * @property array|null $channels
 * @property array|null $variables
 * @property string $language
 * @property bool $is_active
 * @property bool $is_system
 */
class CommunicationTemplate extends Model
{
    protected $table = 'communication_templates';

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'description',
        'module',
        'event',
        'subject',
        'body_html',
        'body_plain',
        'channels',
        'variables',
        'language',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'channels' => 'array',
        'variables' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (function_exists('tenancy') && tenancy()->initialized) {
                // Tenant isolation for tenant-scoped rows PLUS visibility of
                // SYSTEM defaults (tenant_id NULL). Previously this was a
                // plain where('tenant_id', X), which AND-ed with
                // TemplateRenderer::findTemplate()'s orWhereNull fallback and
                // made every system template invisible under tenancy — i.e.
                // exactly in the queued/webhook contexts that render them.
                $tenantId = tenant('id');
                $query->where(function (Builder $q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                });
            }
        });
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    public function scopeForLanguage($query, string $language = 'en')
    {
        return $query->where('language', $language);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Render the template with given variables.
     */
    public function render(array $variables): array
    {
        return [
            'subject' => $this->replaceVariables($this->subject, $variables),
            'body_html' => $this->replaceVariables($this->body_html, $variables),
            'body_plain' => $this->replaceVariables($this->body_plain, $variables),
        ];
    }

    /**
     * Replace {{variable.path}} with actual values.
     */
    protected function replaceVariables(?string $template, array $variables): ?string
    {
        if (! $template) {
            return null;
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];

            // The TemplateRenderer FLATTENS nested variables into literal
            // dotted keys ('order.order_number'), but data_get() SPLITS on
            // dots — so a flattened map never resolved and every dotted
            // placeholder rendered raw (platform-wide latent bug found by
            // SF019). Check the literal key first, then the nested path,
            // and only then keep the placeholder visible.
            if (array_key_exists($key, $variables)) {
                return (string) $variables[$key];
            }

            $nested = data_get($variables, $key, null);

            return $nested === null ? $matches[0] : (string) $nested;
        }, $template);
    }

    /**
     * Get the list of required variables from the template body.
     */
    public function extractVariables(): array
    {
        $combined = ($this->subject ?? '').($this->body_html ?? '').($this->body_plain ?? '');

        preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $combined, $matches);

        return array_unique($matches[1] ?? []);
    }
}
