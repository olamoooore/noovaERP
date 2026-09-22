<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use App\Services\TenantCurrencyFormatter;
use Illuminate\Contracts\Container\Container;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Renderers\BlockRenderer;
use Modules\TemplateBuilder\Renderers\CodeRenderer;
use Modules\TemplateBuilder\Renderers\TemplateRendererInterface;
use RuntimeException;

/**
 * TemplateRendererService — picks the right renderer for a template's mode
 * and produces the final inner-body HTML (no <html> wrapper).
 */
final class TemplateRendererService
{
    /** @var array<string, TemplateRendererInterface> */
    private array $renderers = [];

    public function __construct(
        Container $container,
        private readonly MergeFieldResolver $resolver,
        private readonly TenantCurrencyFormatter $currencyFormatter,
    ) {
        $this->renderers = [
            DocumentTemplate::MODE_BLOCK => $container->make(BlockRenderer::class),
            DocumentTemplate::MODE_CODE => $container->make(CodeRenderer::class),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{html: string, warnings: array<int,string>}
     */
    public function render(DocumentTemplate $template, array $data): array
    {
        $mode = $template->mode ?: DocumentTemplate::MODE_BLOCK;

        $renderer = $this->renderers[$mode] ?? null;
        if ($renderer === null) {
            throw new RuntimeException("No renderer registered for mode '{$mode}'.");
        }

        $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $currencyCode = (string) ($data['meta']['currency_code'] ?? $this->currencyFormatter->preferences()['code']);
        $data['meta']['currency_code'] = strtoupper($currencyCode);
        // Keep direct {{ meta.currency_symbol }} fields consistent too. Monetary
        // values themselves are formatted by MergeFieldResolver's money pipe.
        $data['meta']['currency_symbol'] = $this->currencyFormatter->prefix($currencyCode);

        return $renderer->render($template, $data);
    }
}
