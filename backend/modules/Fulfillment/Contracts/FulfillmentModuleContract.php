<?php

namespace Modules\Fulfillment\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Fulfillment module contract (ADC §5 — Module Contract adoption).
 */
class FulfillmentModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'fulfillment'; }
    public function getName(): string { return 'Fulfillment'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'Multi-tenant fulfillment — shipments, carriers, tracking, shipping rates and zones'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return ['inventory', 'sales']; }

    public function getPermissions(): array
    {
        return [
            'fulfillment.shipments.view', 'fulfillment.shipments.create',
            'fulfillment.rates.manage',
        ];
    }

    public function getEvents(): array
    {
        return ['fulfillment.shipment.created', 'fulfillment.shipment.delivered', 'fulfillment.tracking.updated'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['carrier_setup'],
            'default_settings'     => [],
            'recommended_roles'    => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires'  => ['inventory', 'sales'],
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
            \Modules\Fulfillment\Models\Shipment::class,
            \Modules\Fulfillment\Models\ShipmentLine::class,
            \Modules\Fulfillment\Models\FulfillmentProvider::class,
            \Modules\Fulfillment\Models\ShippingRate::class,
            \Modules\Fulfillment\Models\TrackingEvent::class,
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
