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

    public function deleteUnused(string $id): void;

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

    /**
     * Catalog of predefined starter templates a user can clone from.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listStarters(): array;

    /**
     * Create a user-owned (non-system) template pre-filled from a starter.
     */
    public function createFromStarter(string $documentType): DocumentTemplate;

    /**
     * Provision the system-default starter templates for the current tenant.
     * Idempotent — skips any document_type that already has a system default.
     */
    public function seedDefaults(): void;

    /**
     * Map of document_type => active (resolved) template id, for surfacing
     * which template currently backs each document in the UI.
     *
     * @return array<string, ?string>
     */
    public function activeDefaults(): array;
}
