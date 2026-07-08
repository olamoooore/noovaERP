<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\TemplateBuilder\Models\DocumentTemplate;

interface DocumentTemplateServiceContract
{
    public function list(array $filters = []): LengthAwarePaginator;

    public function find(string $id): ?DocumentTemplate;

    public function create(array $data): DocumentTemplate;

    public function update(string $id, array $data): DocumentTemplate;

    public function archive(string $id): DocumentTemplate;

    public function duplicate(string $id, ?string $newName = null): DocumentTemplate;

    public function publish(string $id, ?string $changeNote = null): DocumentTemplate;

    public function restoreVersion(string $id, int $version): DocumentTemplate;

    public function setDefault(string $id, array $scope = []): void;

    /**
     * Resolve which template to use for a document given a context
     * (branch, currency, language, …). Falls back to the tenant default.
     */
    public function resolveFor(string $documentType, array $context = []): ?DocumentTemplate;

    /**
     * Render a template definition to HTML against sample/supplied data.
     *
     * @return array{html:string, warnings:array<int,string>}
     */
    public function preview(DocumentTemplate $template, ?array $sampleData = null): array;

    /**
     * Validate a template definition (layout_config / html).
     *
     * @return array{valid:bool, errors:array<int,string>}
     */
    public function validateDefinition(array $definition): array;
}
