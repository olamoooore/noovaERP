<?php

namespace Modules\POS\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * POS module contract (ADC §5 — Module Contract adoption).
 */
class POSModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'pos';
    }

    public function getName(): string
    {
        return 'POS';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Point of Sale terminal operations and offline queue.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return ['sales', 'inventory'];
    }

    public function getPermissions(): array
    {
        return [
            'pos.orders.view', 'pos.orders.create', 'pos.orders.void',
            'pos.refunds.process', 'pos.offline.sync',
        ];
    }

    public function getEvents(): array
    {
        return ['pos.order.created', 'pos.order.held', 'pos.offline.synced'];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => ['sales', 'inventory'],
            'settings' => ['terminal_id' => null],
            'min_tier' => null,
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\POS\src\Models\PosOrder::class,
            \Modules\POS\src\Models\PosOrderItem::class,
            \Modules\POS\src\Models\PosHeldOrder::class,
        ];
    }

    public function getExternalModeConfig(): array
    {
        return [
            'supports_coupled' => true,
            'supports_decoupled' => true,
            'supports_external' => true,
            'external_api_endpoints' => ['/api/v1/pos/orders'],
        ];
    }
}
