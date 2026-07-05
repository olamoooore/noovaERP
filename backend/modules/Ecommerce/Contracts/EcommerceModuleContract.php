<?php

namespace Modules\Ecommerce\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * Ecommerce module contract (ADC §5 — Module Contract adoption).
 */
class EcommerceModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'ecommerce'; }
    public function getName(): string { return 'Website'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'E-commerce storefront — carts, orders, customer sessions'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return ['sales', 'inventory']; }

    public function getPermissions(): array
    {
        return [
            'ecommerce.orders.view', 'ecommerce.products.manage', 'ecommerce.carts.view',
        ];
    }

    public function getEvents(): array
    {
        return ['ecommerce.order.created', 'ecommerce.cart.abandoned'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['storefront_setup'],
            'default_settings'     => [],
            'recommended_roles'    => ['admin'],
        ];
    }

    public function getActivationRules(): array
    {
        return [
            'requires'  => ['sales', 'inventory'],
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
            \Modules\Ecommerce\Models\WebCart::class,
            \Modules\Ecommerce\Models\WebCustomerSession::class,
            \Modules\Ecommerce\Models\WebOrderItem::class,
            \Modules\Ecommerce\Models\WebOrder::class,
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
