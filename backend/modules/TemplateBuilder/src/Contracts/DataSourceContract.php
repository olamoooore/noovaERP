<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Contracts;

interface DataSourceContract
{
    /** The document type this source serves, e.g. 'invoice'. */
    public static function documentType(): string;

    /** Eager-load the source record + relations. */
    public function load(string $documentId): self;

    /** The typed merge-field payload consumed by the renderer. */
    public function toArray(): array;

    /** Typed catalog of available merge fields for the editor picker. */
    public function fieldCatalog(): array;

    /** Realistic dummy data for the editor canvas. */
    public function sample(): array;
}
