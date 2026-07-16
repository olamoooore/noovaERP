<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Models\DocumentTemplateVersion;
use Modules\TemplateBuilder\Platform\ResolutionContext;
use Modules\TemplateBuilder\Platform\ResolutionResult;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Support\DefaultTemplates;

final class DocumentTemplateService implements DocumentTemplateServiceContract
{
    public function __construct(
        private readonly TemplateRendererService $renderer,
        private readonly DataSourceRegistry $dataSources,
        private readonly AssignmentResolver $resolver,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        return DocumentTemplate::query()
            ->when($filters['document_type'] ?? null, fn ($q, $t) => $q->forDocumentType($t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(fn ($qq) => $qq->where('name', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%"));
            })
            ->orderByDesc('is_system_default')
            ->orderBy('name')
            ->paginate(perPage: (int) ($filters['per_page'] ?? 20));
    }

    public function find(string $id): ?DocumentTemplate
    {
        return DocumentTemplate::find($id);
    }

    public function create(array $data): DocumentTemplate
    {
        return DB::transaction(function () use ($data) {
            $data['status'] = $data['status'] ?? DocumentTemplate::STATUS_DRAFT;
            $data['mode'] = $data['mode'] ?? DocumentTemplate::MODE_BLOCK;

            return DocumentTemplate::create($data);
        });
    }

    public function update(string $id, array $data): DocumentTemplate
    {
        $template = $this->find($id) ?? $this->notFound($id);

        DB::transaction(function () use ($template, $data) {
            // Editing a published template demotes it back to draft until re-published.
            $payload = collect($data)->only((new DocumentTemplate)->getFillable())->toArray();
            if (isset($payload['layout_config']) || isset($payload['html']) || isset($payload['css'])) {
                if ($template->status === DocumentTemplate::STATUS_PUBLISHED) {
                    $payload['status'] = DocumentTemplate::STATUS_DRAFT;
                }
            }
            $template->update($payload);
        });

        return $template->fresh();
    }

    public function archive(string $id): DocumentTemplate
    {
        $template = $this->find($id) ?? $this->notFound($id);
        $template->update(['status' => DocumentTemplate::STATUS_ARCHIVED]);

        return $template->fresh();
    }

    public function duplicate(string $id, ?string $newName = null): DocumentTemplate
    {
        $source = $this->find($id) ?? $this->notFound($id);
        $attrs = $source->only(['document_type', 'mode', 'format', 'orientation', 'margin', 'default_font', 'default_locale', 'layout_config', 'html', 'css']);
        $attrs['name'] = $newName ?? ($source->name.' (Copy)');
        $attrs['status'] = DocumentTemplate::STATUS_DRAFT;
        $attrs['is_system_default'] = false;

        return DocumentTemplate::create($attrs);
    }

    public function publish(string $id, ?string $changeNote = null): DocumentTemplate
    {
        $template = $this->find($id) ?? $this->notFound($id);

        return DB::transaction(function () use ($template, $changeNote) {
            $next = ($template->versions()->max('version') ?? 0) + 1;

            $template->versions()->update(['is_published' => false]);

            $version = DocumentTemplateVersion::create([
                'template_id' => $template->id,
                'version' => $next,
                'layout_config' => $template->layout_config,
                'html' => $template->html,
                'css' => $template->css,
                'change_note' => $changeNote,
                'is_published' => true,
                'created_by' => auth()->id(),
            ]);

            $template->update([
                'status' => DocumentTemplate::STATUS_PUBLISHED,
                'published_version' => $next,
            ]);

            return $template->fresh();
        });
    }

    public function restoreVersion(string $id, int $version): DocumentTemplate
    {
        $template = $this->find($id) ?? $this->notFound($id);
        $snapshot = $template->versions()->where('version', $version)->first();
        if (! $snapshot) {
            throw new \RuntimeException("Version {$version} not found for template {$id}.");
        }

        $template->update([
            'layout_config' => $snapshot->layout_config,
            'html' => $snapshot->html,
            'css' => $snapshot->css,
            'status' => DocumentTemplate::STATUS_DRAFT,
        ]);

        return $template->fresh();
    }

    public function setDefault(string $id, array $scope = []): void
    {
        $template = $this->find($id) ?? $this->notFound($id);

        DB::transaction(function () use ($template, $scope) {
            $scopeType = $scope['scope_type'] ?? 'tenant';
            $scopeValue = $scope['scope_value'] ?? null;

            // Only one default per (document_type, scope).
            DocumentTemplateAssignment::where('document_type', $template->document_type)
                ->where('scope_type', $scopeType)
                ->when($scopeValue, fn ($q) => $q->where('scope_value', $scopeValue))
                ->update(['is_active' => false]);

            DocumentTemplateAssignment::create([
                'document_type' => $template->document_type,
                'template_id' => $template->id,
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue,
                'priority' => $this->scopePriority($scopeType),
                'is_active' => true,
            ]);
        });
    }

    public function resolveFor(string $documentType, array $context = []): ?DocumentTemplate
    {
        // ADR-004 §11 — delegate to the deterministic resolver. Back-compat:
        // callers still receive a single template (or null); use
        // {@see resolveWithExplanation()} for the full outcome + explanation.
        return $this->resolver->resolve(ResolutionContext::forDocument($documentType, $context))->template;
    }

    /**
     * ADR-004 §11 — deterministic resolution with a structured outcome +
     * explanation. Prefer this over {@see resolveFor()} for new callers.
     */
    public function resolveWithExplanation(string $documentType, array $context = []): ResolutionResult
    {
        return $this->resolver->resolve(ResolutionContext::forDocument($documentType, $context));
    }

    public function preview(DocumentTemplate $template, ?array $sampleData = null): array
    {
        $data = $sampleData ?? ($this->dataSources->has($template->document_type)
            ? $this->dataSources->sampleFor($template->document_type)
            : []);

        $result = $this->renderer->render($template, $data);

        // Wrap in the canvas template so the preview gets full styling (fonts, margins, CSS).
        $fullHtml = view('templatebuilder::canvas', [
            'body' => $result['html'],
            'font' => $template->default_font ?: config('templatebuilder.defaults.font', 'DejaVu Sans'),
            'margin' => is_array($template->margin) ? $template->margin : config('templatebuilder.defaults.margin_mm'),
            'css' => (string) ($template->css ?? ''),
        ])->render();

        return [
            'html' => $fullHtml,
            'warnings' => $result['warnings'],
        ];
    }

    public function validateDefinition(array $definition): array
    {
        $errors = [];
        $layout = $definition['layout_config'] ?? null;

        if ($definition['mode'] === DocumentTemplate::MODE_BLOCK) {
            if (! is_array($layout) || empty($layout['blocks']) || ! is_array($layout['blocks'])) {
                $errors[] = 'Block-mode templates require a layout_config.blocks array.';
            } else {
                foreach ($layout['blocks'] as $i => $block) {
                    if (empty($block['type'])) {
                        $errors[] = "Block #{$i} is missing a 'type'.";
                    }
                }
            }
        }

        if ($definition['mode'] === DocumentTemplate::MODE_CODE && empty($definition['html'])) {
            $errors[] = 'Code-mode templates require html.';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function listStarters(): array
    {
        return collect(DefaultTemplates::definitions())->map(fn (array $d) => [
            'document_type' => $d['document_type'],
            'name' => $d['name'],
            'mode' => $d['mode'],
            'format' => $d['format'],
            'orientation' => $d['orientation'],
            'description' => 'Predefined starter layout — clone and customise.',
        ])->values()->all();
    }

    public function createFromStarter(string $documentType): DocumentTemplate
    {
        $definition = collect(DefaultTemplates::definitions())
            ->firstWhere('document_type', $documentType);

        if (! $definition) {
            throw new \RuntimeException("No starter template exists for document type '{$documentType}'.");
        }

        // User-owned copy: not a system default, starts as a draft, no code collision.
        $definition['is_system_default'] = false;
        $definition['status'] = DocumentTemplate::STATUS_DRAFT;
        $definition['published_version'] = 0;
        $definition['code'] = null;
        $definition['name'] = $definition['name'].' (Copy)';

        return DocumentTemplate::create($definition);
    }

    public function activeDefaults(): array
    {
        $types = ['invoice', 'quotation', 'purchase_order', 'receipt'];

        return collect($types)
            ->mapWithKeys(fn (string $type) => [$type => $this->resolveFor($type)?->id])
            ->all();
    }

    /**
     * Seed the starter templates for the current tenant. Idempotent — skips any
     * document_type that already has a system-default template.
     */
    public function seedDefaults(): void
    {
        foreach (DefaultTemplates::definitions() as $definition) {
            $exists = DocumentTemplate::forDocumentType($definition['document_type'])
                ->where('is_system_default', true)
                ->exists();
            if ($exists) {
                continue;
            }

            $template = DocumentTemplate::create($definition);

            // Published version snapshot.
            $template->versions()->create([
                'template_id' => $template->id,
                'version' => 1,
                'layout_config' => $template->layout_config,
                'html' => $template->html,
                'css' => $template->css,
                'change_note' => 'Seeded starter template',
                'is_published' => true,
            ]);

            // Tenant-scope default assignment.
            DocumentTemplateAssignment::create([
                'document_type' => $template->document_type,
                'template_id' => $template->id,
                'scope_type' => 'tenant',
                'scope_value' => null,
                'priority' => 10,
                'is_active' => true,
            ]);
        }
    }

    private function scopePriority(string $scopeType): int
    {
        return [
            'tenant' => 10,
            'language' => 30,
            'currency' => 40,
            'warehouse' => 50,
            'branch' => 60,
            'customer_group' => 70,
        ][$scopeType] ?? 10;
    }

    /** @return never */
    private function notFound(string $id): void
    {
        throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Document template '{$id}' not found.");
    }
}
