<?php

namespace Modules\IoT\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * IoT module contract (ADC §5 — Module Contract adoption).
 */
class IoTModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'iot'; }
    public function getName(): string { return 'NoovaERP IoT Hub'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'Connect and manage IoT devices — printers, payment terminals, scanners, kiosks'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'iot.devices.view', 'iot.devices.manage', 'iot.print_jobs.create',
        ];
    }

    public function getEvents(): array
    {
        return ['iot.device.registered', 'iot.device.status_changed', 'iot.print_job.completed'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['device_registration'],
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
            \Modules\IoT\Models\IoTDevice::class,
            \Modules\IoT\Models\IoTDeviceTemplate::class,
            \Modules\IoT\Models\PrintJob::class,
            \Modules\IoT\Models\DeviceConfiguration::class,
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
