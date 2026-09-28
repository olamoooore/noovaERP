<?php

namespace Modules\Storefront\Contracts;

use App\Modules\Contracts\BaseModuleContract;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontCartItem;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderItem;
use Modules\Storefront\Models\StorefrontOrderPaymentAttempt;
use Modules\Storefront\Models\StorefrontOrderStatusHistory;
use Modules\Storefront\Models\StorefrontSession;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * Storefront module contract.
 *
 * NOTE ON PLACEMENT: legacy modules keep Contracts/ at the module top level,
 * outside the composer `Modules\<Name>\ -> modules/<Name>/src/` rule, so those
 * contract classes are not resolvable by the autoloader. This contract lives
 * under src/ deliberately (Appendix E §E6: "avoid the known quirks") so it is
 * autoloadable and any future live-path call site can actually resolve it.
 * Until ModuleManager grows a real contract call site this remains registry
 * data — do not assume it gates anything (repo SOP).
 */
class StorefrontModuleContract extends BaseModuleContract
{
    public function getKey(): string
    {
        return 'storefront';
    }

    public function getName(): string
    {
        return 'Storefront';
    }

    public function getType(): string
    {
        return 'addon';
    }

    public function getDescription(): string
    {
        return 'Builder-native, tenant-generic e-commerce module (GrapesJS storefront shortcodes).';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDependencies(): array
    {
        return ['sales', 'inventory'];
    }

    /**
     * Enforced permission strings. These MUST match the coarse platform
     * convention seeded in RolePermissionSeeder::PERMISSION_MATRIX and the
     * exact RequirePermission middleware params on the live routes (FR-MOD-03).
     */
    public function getPermissions(): array
    {
        return [
            'storefront.view',
            'storefront.write',
            'storefront.settings',
        ];
    }

    public function getOwnedEntities(): array
    {
        return [
            StorefrontSettings::class,
            StorefrontAccount::class,
            StorefrontSession::class,
            StorefrontCart::class,
            StorefrontCartItem::class,
            StorefrontOrder::class,
            StorefrontOrderItem::class,
            StorefrontOrderStatusHistory::class,
            StorefrontOrderPaymentAttempt::class,
        ];
    }

    public function getOnboardingConfig(): array
    {
        return [
            'required_setup_steps' => [
                'Configure store settings (currency, tax display, policies)',
                'Publish products to the storefront (product visibility)',
                'Build store pages in WebsiteBuilder (Store block group)',
            ],
            'default_settings' => [
                'enabled' => true,
                'tax_display' => 'exclusive',
                'guest_checkout' => true,
                'allow_backorder' => false,
                'cart_ttl_days' => 30,
            ],
            'recommended_roles' => ['OWNER', 'ADMIN', 'MANAGER'],
        ];
    }
}
