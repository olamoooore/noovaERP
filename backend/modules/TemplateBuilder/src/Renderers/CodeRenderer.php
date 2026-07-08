<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Renderers;

use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Services\MergeFieldResolver;

/**
 * CodeRenderer — renders user-authored HTML/CSS templates ("code mode").
 *
 * Phase 1 scope: resolve merge tokens in the supplied HTML and return it.
 * Tokens are resolved via MergeFieldResolver (dot-path + whitelist) BEFORE the
 * HTML is passed to dompdf, so no user-supplied code is ever executed.
 *
 * A full HTML allow-list sanitiser lands in Phase 3 (templates.code_mode perm).
 */
final class CodeRenderer implements TemplateRendererInterface
{
    public function __construct(private readonly MergeFieldResolver $resolver) {}

    public function supports(string $mode): bool
    {
        return $mode === DocumentTemplate::MODE_CODE;
    }

    public function render(DocumentTemplate $template, array $data): array
    {
        $this->resolver->with($data);

        $html = (string) ($template->html ?? '');
        $resolved = $this->resolver->resolveString($html);

        return [
            'html' => $resolved,
            'warnings' => $this->resolver->warnings(),
        ];
    }
}
