<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\ModuleCatalogEntry;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanModule;
use App\SuperAdmin\Domain\PlanVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PlanCatalogService
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    /** @return Collection<int, PlanFamily> */
    public function listFamilies(): Collection
    {
        return PlanFamily::withCount('versions')
            ->with(['versions.prices'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function createFamily(array $data): PlanFamily
    {
        $family = PlanFamily::create($data);

        $this->auditLogger->log(
            action: 'plan_family.created',
            target: $family,
            before: [],
            after: $family->toAuditSnapshot(),
        );

        return $family;
    }

    /** @param array<string, mixed> $data */
    public function updateFamily(PlanFamily $family, array $data): PlanFamily
    {
        $before = $family->toAuditSnapshot();
        $family->update($data);

        $this->auditLogger->log(
            action: 'plan_family.updated',
            target: $family,
            before: $before,
            after: $family->fresh()->toAuditSnapshot(),
        );

        return $family->fresh();
    }

    /** @param array<string, mixed> $data */
    public function createVersion(PlanFamily $family, array $data): PlanVersion
    {
        $version = $family->versions()->create($data);

        $this->auditLogger->log(
            action: 'plan_version.created',
            target: $version,
            before: [],
            after: $version->toAuditSnapshot(),
        );

        return $version->load('prices');
    }

    /** @param array<string, mixed> $data */
    public function updateVersion(PlanVersion $version, array $data): PlanVersion
    {
        $before = $version->toAuditSnapshot();
        $version->update($data);

        $this->auditLogger->log(
            action: 'plan_version.updated',
            target: $version,
            before: $before,
            after: $version->fresh()->toAuditSnapshot(),
        );

        return $version->fresh()->load('prices');
    }

    /**
     * Upsert prices for a plan version.
     *
     * @param array<int, array{billing_interval: string, unit_amount: int, currency?: string}> $prices
     */
    public function setPrices(PlanVersion $version, array $prices): void
    {
        DB::transaction(function () use ($version, $prices): void {
            foreach ($prices as $price) {
                $currency = strtoupper($price['currency'] ?? 'USD');

                $version->prices()->updateOrCreate(
                    ['currency' => $currency, 'billing_interval' => $price['billing_interval']],
                    ['unit_amount' => (int) $price['unit_amount']],
                );
            }
        });

        $this->auditLogger->log(
            action: 'plan_version.prices_set',
            target: $version,
            before: [],
            after: ['prices' => $prices],
        );
    }

    public function attachModule(PlanVersion $version, ModuleCatalogEntry $module): void
    {
        PlanModule::firstOrCreate([
            'plan_version_id'   => $version->id,
            'module_catalog_id' => $module->id,
        ], ['is_included' => true]);

        $this->auditLogger->log(
            action: 'plan_version.module_attached',
            target: $version,
            before: [],
            after: ['module_key' => $module->module_key],
        );
    }

    public function detachModule(PlanVersion $version, ModuleCatalogEntry $module): void
    {
        PlanModule::where('plan_version_id', $version->id)
            ->where('module_catalog_id', $module->id)
            ->delete();

        $this->auditLogger->log(
            action: 'plan_version.module_detached',
            target: $version,
            before: ['module_key' => $module->module_key],
            after: [],
        );
    }
}
