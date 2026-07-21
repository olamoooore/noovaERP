<?php

namespace Modules\OCRBookkeeping\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * OCRBookkeeping module contract (ADC §5 — Module Contract adoption).
 */
class OCRBookkeepingModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'ocr-bookkeeping'; }
    public function getName(): string { return 'OCR Bookkeeping'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'OCR-powered bookkeeping — document scanning, vendor mapping, account mapping'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'ocr.documents.view', 'ocr.documents.process',
            'ocr.mappings.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['ocr.document.processed', 'ocr.mapping.created'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['ocr_configuration'],
            'default_settings'     => [],
            'recommended_roles'    => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires'  => [],
            'settings'  => [],
            'min_tier'  => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data'       => false,
            'preserves_entities' => [],
            'cleanup_jobs'       => [],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\OCRBookkeeping\Models\OcrDocument::class,
            \Modules\OCRBookkeeping\Models\OcrVendorMapping::class,
            \Modules\OCRBookkeeping\Models\OcrAccountMapping::class,
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions'   => false,
            'ai_automation'    => false,
            'ai_data_access'   => [],
        ];
    }
}
