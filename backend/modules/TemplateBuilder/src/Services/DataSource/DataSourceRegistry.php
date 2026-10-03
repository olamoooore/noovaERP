<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Services\DataSource;

use App\Http\Middleware\RequirePermission;
use App\Platform\DataSecurity\FieldMaskingService;
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
     * PHI (patient names) is masked unless the caller holds
     * medical_cases.phi.view (#567/#611) — enforced here, at the single
     * choke point every PDF/HTML render reads through, so no data source
     * can bypass it.
     *
     * @return array<string,mixed>
     */
    public function loadPayload(string $documentType, string $documentId): array
    {
        return self::maskProtectedFields($this->make($documentType)->load($documentId)->toArray());
    }

    /**
     * Template resolution dimensions exposed by the document's data source
     * (issue #612). Callers that render/write should pass this to
     * DocumentTemplateService::resolveFor() so entity/language/currency/
     * customer-group assignments actually apply.
     *
     * @return array<string,mixed>
     */
    public function resolutionContextFor(string $documentType, string $documentId): array
    {
        return $this->make($documentType)->resolutionContext($documentId);
    }

    /**
     * Recursively mask PHI fields when the current request's user does not
     * hold medical_cases.phi.view. Uses the platform FieldMaskingService.
     *
     * @param  array<array-key,mixed>  $payload
     * @return array<array-key,mixed>
     */
    public static function maskProtectedFields(array $payload): array
    {
        if (self::canViewPhi()) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::maskProtectedFields($value);
            } elseif ($key === 'patient_name' && is_string($value) && $value !== '') {
                $payload[$key] = FieldMaskingService::maskDefault($value);
            }
        }

        return $payload;
    }

    /**
     * Whether the current request user may see PHI. Fail closed when there is
     * no authenticated user (queues/console) or no tenant context.
     */
    private static function canViewPhi(): bool
    {
        $request = request();

        if ($request === null || $request->user() === null) {
            return false;
        }

        return RequirePermission::allows($request, 'medical_cases.phi.view');
    }

    /** @return Collection<string, class-string<DataSourceContract>> */
    public function all(): Collection
    {
        return collect($this->sources);
    }
}
