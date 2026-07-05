<?php

namespace Modules\Communication\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Communication module contract (ADC §5 — Module Contract adoption).
 */
class CommunicationModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'communication'; }
    public function getName(): string { return 'Communication'; }
    public function getType(): string { return 'core'; }
    public function getDescription(): string { return 'Multi-channel communication engine — messages, templates, outbox, subscribers, preferences'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'communication.messages.view', 'communication.messages.send',
            'communication.templates.manage', 'communication.preferences.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['communication.message.sent', 'communication.message.failed', 'communication.outbox.dispatched'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['channel_configuration'],
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
            \Modules\Communication\Models\CommunicationMessage::class,
            \Modules\Communication\Models\CommunicationTemplate::class,
            \Modules\Communication\Models\CommunicationChannelLog::class,
            \Modules\Communication\Models\CommunicationSubscriber::class,
            \Modules\Communication\Models\CommunicationOutbox::class,
            \Modules\Communication\Models\CommunicationPreference::class,
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
