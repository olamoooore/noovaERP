<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\SuperAdmin\Domain\PlanVersion;
use App\SuperAdmin\Domain\Subscription;
use App\SuperAdmin\Domain\SubscriptionInvoice;
use App\SuperAdmin\Application\EntitlementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Subscription360Seeder extends Seeder
{
    public function run(): void
    {
        // Resolve plan versions by family key (must run after PlanCatalogSeeder)
        $essential    = PlanVersion::whereHas('family', fn ($q) => $q->where('key', 'essential'))->first();
        $growth       = PlanVersion::whereHas('family', fn ($q) => $q->where('key', 'growth'))->first();
        $entStandard  = PlanVersion::whereHas('family', fn ($q) => $q->where('key', 'enterprise-standard'))->first();

        if (!$essential || !$growth || !$entStandard) {
            $this->command->warn('Plan versions not found. Run PlanCatalogSeeder first.');
            return;
        }

        $tenants = DB::table('tenants')->pluck('id', 'name');
        $now     = now();

        $seeds = [
            [
                'name'             => 'Acme Corporation',
                'plan_version'     => $essential,
                'status'           => 'active',
                'period_start'     => $now->copy()->subMonth(),
                'period_end'       => $now->copy()->addMonth(),
                'billing_interval' => 'monthly',
                'currency'         => 'SGD',
                'invoice_status'   => 'paid',
            ],
            [
                'name'             => 'Globex Inc',
                'plan_version'     => $growth,
                'status'           => 'active',
                'period_start'     => $now->copy()->subYear(),
                'period_end'       => $now->copy()->addYear(),
                'billing_interval' => 'yearly',
                'currency'         => 'SGD',
                'invoice_status'   => 'paid',
            ],
            [
                'name'             => 'Initech LLC',
                'plan_version'     => $essential,
                'status'           => 'canceled',
                'period_start'     => $now->copy()->subMonths(2),
                'period_end'       => $now->copy()->subMonth(),
                'billing_interval' => 'monthly',
                'currency'         => 'MYR',
                'invoice_status'   => 'void',
                'canceled_at'      => $now->copy()->subMonth(),
                'cancellation_reason' => 'Tenant suspended for non-payment.',
            ],
            [
                'name'             => 'Umbrella Co',
                'plan_version'     => $essential,
                'status'           => 'trialing',
                'period_start'     => $now->copy(),
                'period_end'       => $now->copy()->addDays(14),
                'billing_interval' => 'monthly',
                'currency'         => 'SGD',
                'trial_starts_at'  => $now->copy(),
                'trial_ends_at'    => $now->copy()->addDays(14),
                'invoice_status'   => null,
            ],
            [
                'name'             => 'Wayne Enterprises',
                'plan_version'     => $entStandard,
                'status'           => 'past_due',
                'period_start'     => $now->copy()->subMonth(),
                'period_end'       => $now->copy(),
                'billing_interval' => 'monthly',
                'currency'         => 'SGD',
                'invoice_status'   => 'open',
            ],
            [
                'name'             => 'Stark Industries',
                'plan_version'     => $growth,
                'status'           => 'expired',
                'period_start'     => $now->copy()->subMonths(2),
                'period_end'       => $now->copy()->subMonth(),
                'billing_interval' => 'monthly',
                'currency'         => 'MYR',
                'invoice_status'   => 'uncollectible',
            ],
        ];

        // Clear existing subscriptions + invoices to avoid duplicates on re-seed
        DB::table('subscription_invoices')->delete();
        DB::table('subscriptions')->delete();

        foreach ($seeds as $seed) {
            $tenantId = $tenants[$seed['name']] ?? null;

            if (!$tenantId) {
                $this->command->warn("Tenant [{$seed['name']}] not found — skipping.");
                continue;
            }

            $subscription = Subscription::create([
                'tenant_id'            => $tenantId,
                'plan_version_id'      => $seed['plan_version']->id,
                'status'               => $seed['status'],
                'trial_starts_at'      => $seed['trial_starts_at'] ?? null,
                'trial_ends_at'        => $seed['trial_ends_at'] ?? null,
                'current_period_start' => $seed['period_start'],
                'current_period_end'   => $seed['period_end'],
                'canceled_at'          => $seed['canceled_at'] ?? null,
                'cancellation_reason'  => $seed['cancellation_reason'] ?? null,
            ]);

            if (!empty($seed['invoice_status'])) {
                $nextVal  = DB::selectOne("SELECT nextval('invoice_number_seq') AS val")->val;
                $year     = now()->year;
                $invNumber = 'INV-' . $year . '-' . str_pad((string) $nextVal, 5, '0', STR_PAD_LEFT);

                $price = DB::table('plan_prices')
                    ->where('plan_version_id', $seed['plan_version']->id)
                    ->where('billing_interval', $seed['billing_interval'])
                    ->where('currency', $seed['currency'])
                    ->first();

                $amount = $price?->unit_amount ?? 0;

                SubscriptionInvoice::create([
                    'subscription_id'  => $subscription->id,
                    'tenant_id'        => $tenantId,
                    'invoice_number'   => $invNumber,
                    'billing_interval' => $seed['billing_interval'],
                    'currency'         => $seed['currency'],
                    'subtotal'         => $amount,
                    'tax'              => 0,
                    'total'            => $amount,
                    'status'           => $seed['invoice_status'],
                    'due_date'         => $seed['period_end']->copy()->subDays(14)->toDateString(),
                    'paid_at'          => $seed['invoice_status'] === 'paid'
                        ? $seed['period_start']->copy()->addDay()
                        : null,
                    'period_start'     => $seed['period_start'],
                    'period_end'       => $seed['period_end'],
                ]);
            }
        }

        $this->command->info('Subscription360Seeder: 6 subscriptions seeded with real plan pricing.');

        // Rebuild entitlements for all seeded tenants
        $entitlementService = app(EntitlementService::class);
        foreach ($seeds as $seed) {
            $tenantId = $tenants[$seed['name']] ?? null;
            if ($tenantId) {
                $entitlementService->rebuild($tenantId, null, 'subscription_seeded');
            }
        }

        $this->command->info('Subscription360Seeder: Entitlements rebuilt for all seeded tenants.');
    }
}
