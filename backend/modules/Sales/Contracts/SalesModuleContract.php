<?php

namespace Modules\Sales\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Sales module contract (ADC §5 — Module Contract adoption).
 */
class SalesModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'sales'; }
    public function getName(): string { return 'Sales'; }
    public function getType(): string { return 'core'; }
    public function getDescription(): string { return 'Sales orders, quotes, and revenue management.'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'sales.orders.view', 'sales.orders.create', 'sales.orders.edit',
            'sales.quotes.view', 'sales.quotes.create',
        ];
    }

    public function getEvents(): array
    {
        return ['sales.order.created', 'sales.order.confirmed', 'sales.quote.approved'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['channel_setup', 'tax_setup'],
            'default_settings'     => ['currency' => 'USD'],
            'recommended_roles'    => ['sales_manager', 'sales_rep'],
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            \Modules\Sales\src\Models\SalesOrder::class,
            \Modules\Sales\src\Models\SalesOrderLine::class,
        ];
    }

    public function getAICapabilities(): array
    {
        return [
            'has_ai_assistant' => true,
            'ai_suggestions'   => true,
            'ai_automation'    => false,
            'ai_data_access'   => ['sales.orders', 'sales.quotes'],
        ];
    }
}
