<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Contracts;

interface DataSourceContract
{
    /** The document type this source serves, e.g. 'invoice'. */
    public static function documentType(): string;

    /**
     * Permission the caller must hold to render this source document
     * (the source document's own read permission — not document.view).
     */
    public function permission(): string;

    /** Eager-load the source record + relations. */
    public function load(string $documentId): self;

    /**
     * Legal entity the loaded source document belongs to, when the base
     * schema can resolve it (null when the document carries no entity link).
     * Must only be called after load().
     */
    public function legalEntityId(): ?int;

    /**
     * Template resolution dimensions for the document (entity, branch,
     * currency, customer language, customer group, channel). Loads the
     * source record itself so renderers can pass the result straight to
     * DocumentTemplateService::resolveFor() (ADR-004 §11, issue #612).
     *
     * @return array<string,mixed>
     */
    public function resolutionContext(string $documentId): array;

    /** The typed merge-field payload consumed by the renderer. */
    public function toArray(): array;

    /** Typed catalog of available merge fields for the editor picker. */
    public function fieldCatalog(): array;

    /** Realistic dummy data for the editor canvas. */
    public function sample(): array;
}
