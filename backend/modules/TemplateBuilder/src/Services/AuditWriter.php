<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;

/**
 * Thin audit facade for the Universal Template Platform.
 *
 * Routes template lifecycle / resolution / generation events through the
 * canonical ADR-000 SystemLog service (ADR-004 §6 — artifact/response audit
 * context owned by the platform).
 */
final class AuditWriter
{
    public function __construct(
        private readonly SystemLogServiceContract $systemLog,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function record(string $event, DocumentTemplate $template, array $context = []): void
    {
        $this->systemLog->log(
            level: 'info',
            category: 'template_platform',
            message: $event,
            context: array_merge([
                'template_id' => $template->id,
                'template_type' => $template->document_type,
                'interaction_type' => $template->interaction_type,
                'status' => $template->status,
                'version' => $template->published_version,
            ], $context),
        );
    }

    /**
     * Record a generic (non-template-bound) platform event.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordEvent(string $event, array $context = [], string $level = 'info'): void
    {
        $this->systemLog->log(
            level: $level,
            category: 'template_platform',
            message: $event,
            context: $context,
        );
    }
}
