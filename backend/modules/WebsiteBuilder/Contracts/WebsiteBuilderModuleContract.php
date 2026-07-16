<?php

namespace Modules\WebsiteBuilder\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * WebsiteBuilder module contract (ADC §5 — Module Contract adoption).
 */
class WebsiteBuilderModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'website-builder';
    }

    public function getName(): string
    {
        return 'Website Builder';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Website Builder — pages, components, templates, assets, publishing';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'websitebuilder.pages.view', 'websitebuilder.pages.create',
            'websitebuilder.pages.publish', 'websitebuilder.templates.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['websitebuilder.page.published', 'websitebuilder.page.drafted'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['website_setup'],
            'default_settings' => [],
            'recommended_roles' => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => [],
            'settings' => [],
            'min_tier' => null,
        ];
    }

    public function getDeactivationRules(): array
    {
        return [
            'removes_data' => false,
            'preserves_entities' => [],
            'cleanup_jobs' => [],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\WebsiteBuilder\Models\Website::class,
            \Modules\WebsiteBuilder\Models\Page::class,
            \Modules\WebsiteBuilder\Models\Component::class,
            \Modules\WebsiteBuilder\Models\Template::class,
            \Modules\WebsiteBuilder\Models\Asset::class,
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions' => false,
            'ai_automation' => false,
            'ai_data_access' => [],
        ];
    }
}
