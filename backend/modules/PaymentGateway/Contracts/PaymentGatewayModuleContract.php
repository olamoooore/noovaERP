<?php

namespace Modules\PaymentGateway\Contracts;

use App\Modules\Contracts\BaseModuleContract;

/**
 * PaymentGateway module contract (ADC §5 — Module Contract adoption).
 */
class PaymentGatewayModuleContract extends BaseModuleContract
{
    public function getKey(): string { return 'payment_gateway'; }
    public function getName(): string { return 'Payment Gateway'; }
    public function getType(): string { return 'addon'; }
    public function getDescription(): string { return 'Multi-gateway payment processing — Stripe, HitPay, offline methods'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getDependencies(): array { return []; }

    public function getPermissions(): array
    {
        return [
            'paymentgateways.view', 'paymentgateways.manage',
            'paymentgateways.transactions.view',
        ];
    }

    public function getEvents(): array
    {
        return ['paymentgateway.transaction.completed', 'paymentgateway.transaction.failed'];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => ['gateway_configuration'],
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
            \Modules\PaymentGateway\Models\PaymentGateway::class,
            \Modules\PaymentGateway\Models\PaymentTransaction::class,
            \Modules\PaymentGateway\Models\OfflinePaymentMethod::class,
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
