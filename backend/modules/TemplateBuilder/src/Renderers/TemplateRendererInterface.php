<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Renderers;

use Modules\TemplateBuilder\Models\DocumentTemplate;

interface TemplateRendererInterface
{
    /** The template mode this renderer handles, e.g. 'block' or 'code'. */
    public function supports(string $mode): bool;

    /**
     * Render the template definition to HTML using the resolved data payload.
     *
     * @param  array<string,mixed>  $data
     * @return array{html: string, warnings: array<int,string>}
     */
    public function render(DocumentTemplate $template, array $data): array;
}
