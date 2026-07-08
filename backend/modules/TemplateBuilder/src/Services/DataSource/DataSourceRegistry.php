<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use Illuminate\Support\Collection;
use Modules\TemplateBuilder\Contracts\DataSourceContract;
use RuntimeException;

/**
 * Maps a document type to the DataSourceContract implementation that serves it.
 */
final class DataSourceRegistry
{
    /** @var array<string, class-string<DataSourceContract>> */
    private array $sources = [];

    /** @param class-string<DataSourceContract> $sourceClass */
    public function register(string $documentType, string $sourceClass): void
    {
        $this->sources[$documentType] = $sourceClass;
    }

    public function has(string $documentType): bool
    {
        return isset($this->sources[$documentType]);
    }

    /** @return list<string> */
    public function documentTypes(): array
    {
        return array_keys($this->sources);
    }

    public function make(string $documentType): DataSourceContract
    {
        if (! isset($this->sources[$documentType])) {
            throw new RuntimeException("No data source registered for document type '{$documentType}'.");
        }

        return app($this->sources[$documentType]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function fieldCatalogFor(string $documentType): array
    {
        return $this->make($documentType)->fieldCatalog();
    }

    /**
     * @return array<string,mixed>
     */
    public function sampleFor(string $documentType): array
    {
        return $this->make($documentType)->sample();
    }

    /**
     * Load a record's typed payload.
     *
     * @return array<string,mixed>
     */
    public function loadPayload(string $documentType, string $documentId): array
    {
        return $this->make($documentType)->load($documentId)->toArray();
    }

    /** @return Collection<string, class-string<DataSourceContract>> */
    public function all(): Collection
    {
        return collect($this->sources);
    }
}
