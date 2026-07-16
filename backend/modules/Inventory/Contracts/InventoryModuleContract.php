<?php

namespace Modules\Inventory\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Inventory module contract (ADC §5 — Module Contract adoption).
 */
class InventoryModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'inventory';
    }

    public function getName(): string
    {
        return 'Inventory';
    }

    public function getType(): string
    {
        return 'core';
    }

    public function getDescription(): string
    {
        return 'Stock management, purchase orders, and warehouse operations.';
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
            'inventory.stock.view', 'inventory.stock.adjust',
            'inventory.purchase_orders.view', 'inventory.purchase_orders.create',
        ];
    }

    public function getEvents(): array
    {
        return ['inventory.stock.adjusted', 'inventory.purchase_order.received'];
    }

    public function getActivationRules(): array
    {
        return [
            'requires' => [],
            'settings' => ['warehouse_id' => null],
            'min_tier' => null,
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\Inventory\src\Models\PurchaseOrder::class,
            \Modules\Inventory\src\Models\PurchaseOrderItem::class,
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => false,
            'ai_suggestions' => true,
            'ai_automation' => false,
            'ai_data_access' => ['inventory.stock', 'inventory.purchases'],
        ];
    }
}
