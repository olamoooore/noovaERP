<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Renderers;

use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Services\MergeFieldResolver;
use Modules\TemplateBuilder\Services\TemplateContentSanitiser;

/**
 * CodeRenderer — renders user-authored HTML/CSS templates ("code mode").
 *
 * ADR-004 §15 security: every user payload is run through the
 * {@see TemplateContentSanitiser} BEFORE merge-token resolution and BEFORE
 * the HTML is passed to dompdf. No user-supplied script, event handler,
 * disallowed tag or remote URL ever survives into the rendered output.
 *
 * Custom HTML/CSS is privileged (ADR-004 §14 `template.custom_code`); the
 * route middleware gates who can save code-mode templates.
 */
final class CodeRenderer implements TemplateRendererInterface
{
    public function __construct(
        private readonly MergeFieldResolver $resolver,
        private readonly TemplateContentSanitiser $sanitiser,
    ) {}

    public function supports(string $mode): bool
    {
        return $mode === DocumentTemplate::MODE_CODE;
    }

    public function render(DocumentTemplate $template, array $data): array
    {
        $this->resolver->with($data);
        $meta = $template->metadata ?? [];
        $dateFormat = $meta['date_format'] ?? null;
        if ($dateFormat) {
            $this->resolver->setDateFormat($dateFormat);
        }

        // Sanitise FIRST (before tokens are interpolated, so token values are
        // also escaped downstream by the resolver).
        $sanitised = $this->sanitiser->sanitise(
            html: (string) ($template->html ?? ''),
            css: (string) ($template->css ?? ''),
        );

        $resolved = $this->resolver->resolveString($sanitised['html']);

        return [
            'html' => $resolved,
            'warnings' => $this->resolver->warnings(),
            'css' => $sanitised['css'],
        ];
    }
}
