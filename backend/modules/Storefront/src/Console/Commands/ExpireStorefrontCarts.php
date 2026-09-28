<?php

namespace Modules\Storefront\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Storefront\Models\StorefrontCart;

/**
 * Cart retention (Appendix E §E2: carts expire after the tenant-configured
 * TTL). Scheduler wiring lands with the P2 ops slice; safe to run ad hoc.
 */
class ExpireStorefrontCarts extends Command
{
    protected $signature = 'storefront:carts:expire {--tenant= : Restrict to one tenant id}';

    protected $description = 'Mark active storefront carts past their expiry date as expired';

    public function handle(): int
    {
        $total = 0;

        $tenants = $this->option('tenant')
            ? Tenant::query()->where('id', $this->option('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            $count = (int) $tenant->run(function (): int {
                if (! Schema::hasTable('storefront_carts')) {
                    return 0;
                }

                return StorefrontCart::query()
                    ->where('status', 'active')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->update(['status' => 'expired']);
            });

            if ($count > 0) {
                $this->info("Tenant {$tenant->id}: expired {$count} cart(s).");
            }

            $total += $count;
        }

        $this->info("Done. {$total} storefront cart(s) expired.");

        return self::SUCCESS;
    }
}
