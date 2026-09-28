<?php

namespace Modules\Storefront\Providers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Models\StockLevel;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CatalogQuery;
use Modules\Storefront\Services\OrderService;
use Modules\Storefront\Services\OrderStateMachine;
use Modules\Storefront\Services\StorefrontCache;
use Modules\Storefront\Shortcodes\DefaultFunctions;
use Modules\Storefront\Shortcodes\Parser;
use Modules\Storefront\Shortcodes\Renderer;
use Modules\Storefront\Shortcodes\StorefrontPageContentFilter;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;
use Throwable;

/**
 * Storefront module service provider.
 *
 * What is NOT done here, and why (platform conventions, verified live):
 *  - Routes are NOT loaded here. routes/api.php is required by ModuleManager
 *    inside the authenticated tenant group in routes/tenant.php (re-wrapping
 *    with ['api','tenant'] was the issue #143 crash pattern).
 *  - Migrations are NOT registered here. modules/Storefront/database/migrations/tenant
 *    is merged into tenancy.migration_parameters by App\Providers\ModuleServiceProvider.
 *  - Views/translations are auto-loaded by ModuleServiceProvider under the
 *    `storefront::` namespace.
 */
class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorefrontShortcodeRegistry::class, function ($app): StorefrontShortcodeRegistry {
            $registry = new StorefrontShortcodeRegistry;
            DefaultFunctions::register($registry, $app->make(CatalogQuery::class));

            return $registry;
        });

        $this->app->singleton(Parser::class);
        $this->app->singleton(StorefrontCache::class);

        $this->app->singleton(Renderer::class, fn ($app): Renderer => new Renderer(
            $app->make(StorefrontShortcodeRegistry::class),
            $app->make(Parser::class),
            $app->make(StorefrontCache::class),
        ));

        $this->app->singleton(StorefrontPageContentFilter::class);

        // The ONE order state machine + writer service (FR-ORD-01). All
        // writers (checkout, webhook completion, admin, customer) resolve
        // OrderService from the container — no private transition maps.
        $this->app->singleton(OrderStateMachine::class);
        $this->app->singleton(OrderService::class, fn ($app): OrderService => new OrderService(
            $app->make(OrderStateMachine::class),
        ));
    }

    public function boot(): void
    {
        $this->registerExtensionPoints();
        $this->registerCacheInvalidation();
        $this->registerCommunicationSubtypes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Storefront\Console\Commands\ExpireStorefrontCarts::class,
            ]);
        }
    }

    /**
     * Declare our customer-facing event subtypes on the Communication
     * engine's registry — the sanctioned boot-time path (the registry's own
     * docblock). Without this, engine entrances throw InvalidArgumentException
     * inside the queued listener and the mail silently never sends (the live
     * P-05 defect pattern: invoice.delivered / invoice.approval_submitted
     * are unregistered producers today).
     */
    protected function registerCommunicationSubtypes(): void
    {
        $registry = \Modules\Communication\Support\EventSubtypeRegistry::class;

        if (class_exists($registry)) {
            $registry::register(
                'storefront.customer.registered',
                'storefront.customer.email_verification',
                'storefront.customer.email_verified',
                'storefront.customer.password_reset',
            );
        }
    }

    /**
     * Publish the shortcode renderer into the platform page-content pipeline
     * (Appendix B §B8: one renderer path — WebsiteBuilder /w/ applies every
     * tagged PageContentFilter). Extension point for future filters too.
     */
    protected function registerExtensionPoints(): void
    {
        $this->app->tag([StorefrontPageContentFilter::class], 'page_content_filters');
    }

    /**
     * Event-driven fragment-cache invalidation (FR-BLD-08 / R-03): any
     * catalog, stock, category or settings mutation bumps the tenant's
     * cache version, atomically orphaning stale Tier A fragments.
     */
    protected function registerCacheInvalidation(): void
    {
        $bump = function (): void {
            try {
                if (function_exists('tenant') && tenant()) {
                    app(StorefrontCache::class)->bumpVersion((string) tenant('id'));
                }
            } catch (Throwable) {
                // Best-effort: a cache bump must never break the write itself.
            }
        };

        foreach ([Product::class, ProductVariant::class, ProductCategory::class, StockLevel::class, StorefrontSettings::class] as $model) {
            $model::saved($bump);
            $model::deleted($bump);

            // Only soft-deletable models dispatch the restored event.
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
                $model::restored($bump);
            }
        }
    }
}
