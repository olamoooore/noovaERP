<?php

namespace Modules\WhatsApp\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * WhatsApp module contract (ADC §5 — Module Contract adoption).
 */
class WhatsAppModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'whatsapp'; }
    public function getName(): string { return 'WhatsApp'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'WhatsApp integration — API key management, messaging'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'whatsapp.apikeys.view', 'whatsapp.apikeys.manage',
            'whatsapp.messages.send',
        ];
    }

    public function getEvents(): array
    {
        return ['whatsapp.message.sent', 'whatsapp.message.delivered'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['whatsapp_api_setup'],
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
            \Modules\WhatsApp\Models\WhatsAppApiKey::class,
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
