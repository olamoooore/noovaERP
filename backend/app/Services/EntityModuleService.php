<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Config-driven module activation per entity.
 * Respects module_dependency_rules.
 */
class EntityModuleService
{
    /**
     * List available and active modules for an entity.
     */
    public function listModules(int $legalEntityId): array
    {
        $active = DB::table('entity_modules')
            ->where('legal_entity_id', $legalEntityId)
            ->where('is_active', true)
            ->pluck('module_code')
            ->toArray();

        $allModules = $this->getAllModuleCodes();

        return array_map(fn(string $code) => [
            'module_code' => $code,
            'is_active'   => in_array($code, $active),
        ], $allModules);
    }

    /**
     * Activate a module for an entity, checking dependency rules.
     */
    public function activate(int $legalEntityId, string $moduleCode, ?int $userId = null): void
    {
        // Check hard dependencies
        $deps = DB::table('module_dependency_rules')
            ->where('module_code', $moduleCode)
            ->where('is_hard_dependency', true)
            ->pluck('depends_on_module_code');

        foreach ($deps as $dep) {
            $depActive = DB::table('entity_modules')
                ->where('legal_entity_id', $legalEntityId)
                ->where('module_code', $dep)
                ->where('is_active', true)
                ->exists();

            if (!$depActive) {
                throw new \RuntimeException(
                    "Cannot activate '{$moduleCode}': hard dependency '{$dep}' is not active."
                );
            }
        }

        DB::table('entity_modules')->updateOrInsert(
            ['legal_entity_id' => $legalEntityId, 'module_code' => $moduleCode],
            [
                'is_active'    => true,
                'activated_at' => now(),
                'activated_by' => $userId,
                'updated_at'   => now(),
            ]
        );
    }

    /**
     * Deactivate a module. Fails if another active module depends on it.
     */
    public function deactivate(int $legalEntityId, string $moduleCode): void
    {
        // Check if any active module has a hard dependency on this one
        $activeModules = DB::table('entity_modules')
            ->where('legal_entity_id', $legalEntityId)
            ->where('is_active', true)
            ->where('module_code', '!=', $moduleCode)
            ->pluck('module_code');

        foreach ($activeModules as $active) {
            $depends = DB::table('module_dependency_rules')
                ->where('module_code', $active)
                ->where('depends_on_module_code', $moduleCode)
                ->where('is_hard_dependency', true)
                ->exists();

            if ($depends) {
                throw new \RuntimeException(
                    "Cannot deactivate '{$moduleCode}': module '{$active}' depends on it."
                );
            }
        }

        DB::table('entity_modules')
            ->where('legal_entity_id', $legalEntityId)
            ->where('module_code', $moduleCode)
            ->update([
                'is_active'  => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * Apply an industry pack's default modules to an entity.
     */
    public function applyIndustryPack(int $legalEntityId, string $packCode, ?int $userId = null): int
    {
        $pack = DB::table('industry_packs')
            ->where('code', $packCode)
            ->where('is_active', true)
            ->first();

        if (!$pack) {
            throw new \RuntimeException("Industry pack '{$packCode}' not found.");
        }

        $modules = json_decode($pack->module_defaults, true) ?? [];
        $count = 0;

        // Activate in dependency order (accounting first)
        usort($modules, fn($a, $b) => $a === 'accounting' ? -1 : ($b === 'accounting' ? 1 : 0));

        foreach ($modules as $moduleCode) {
            try {
                $this->activate($legalEntityId, $moduleCode, $userId);
                $count++;
            } catch (\RuntimeException $e) {
                // Log but don't fail the whole pack
                \Illuminate\Support\Facades\Log::warning("Failed to activate module during pack apply", [
                    'module'    => $moduleCode,
                    'entity_id' => $legalEntityId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function getAllModuleCodes(): array
    {
        return [
            'accounting', 'inventory', 'sales', 'pos', 'ecommerce',
            'ocr', 'field_service', 'payroll', 'loyalty',
            'communication', 'website_builder', 'event_management', 'iot',
        ];
    }
}
