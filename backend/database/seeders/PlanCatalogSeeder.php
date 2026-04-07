<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 4 official NoovaERP POS plan tiers from the Phase C tier packaging document.
 *
 * Tiers:        Essential | Growth | Enterprise Standard | Enterprise Custom
 * Currencies:   SGD (Singapore) and MYR (Malaysia)
 * Pricing:      Monthly and Yearly (yearly = 10 months, 2 months free)
 * Source:       noovaerp_pos_tier_packaging April 2026 — Sheet 01 Pricing Reference
 */
class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ── Clean slate (FK order: invoices → subscriptions → prices → modules → versions → families) ──
        DB::table('subscription_invoices')->delete();
        DB::table('subscriptions')->delete();
        DB::table('plan_prices')->delete();
        DB::table('plan_modules')->delete();
        DB::table('plan_versions')->delete();
        DB::table('plan_families')->delete();

        $now = now();

        // ── Plan families ────────────────────────────────────────────────────
        // Each tier's positioning from Executive Summary sheet
        $families = [
            [
                'key'         => 'essential',
                'name'        => 'Essential',
                'description' => 'Run my outlet. Best for single outlets, basic operations, and low-complexity deployments.',
                'is_active'   => true,
                'sort_order'  => 1,
            ],
            [
                'key'         => 'growth',
                'name'        => 'Growth',
                'description' => 'Help me scale operations. Ideal for SMEs adding devices, payments, loyalty, promos, or customer-facing enhancements.',
                'is_active'   => true,
                'sort_order'  => 2,
            ],
            [
                'key'         => 'enterprise-standard',
                'name'        => 'Enterprise Standard',
                'description' => 'Control all outlets centrally. For chains and larger operators needing governance, resilience, and central control.',
                'is_active'   => true,
                'sort_order'  => 3,
            ],
            [
                'key'         => 'enterprise-custom',
                'name'        => 'Enterprise Custom',
                'description' => 'Fit my business model exactly. For franchise, multi-company, or highly specific workflow and branding needs.',
                'is_active'   => true,
                'sort_order'  => 4,
            ],
        ];

        // Pricing matrix (in cents)
        // Source: noovaerp_pos_tier_packaging April 2026 — Sheet 01 Pricing Reference
        // Yearly = 10× monthly (2 months free)
        $prices = [
            'essential' => [
                ['currency' => 'SGD', 'billing_interval' => 'monthly', 'unit_amount' =>   6_000], // SGD  60 /mo
                ['currency' => 'SGD', 'billing_interval' => 'yearly',  'unit_amount' =>  60_000], // SGD 600 /yr
                ['currency' => 'MYR', 'billing_interval' => 'monthly', 'unit_amount' =>  18_000], // MYR 180 /mo
                ['currency' => 'MYR', 'billing_interval' => 'yearly',  'unit_amount' => 180_000], // MYR 1,800 /yr
            ],
            'growth' => [
                ['currency' => 'SGD', 'billing_interval' => 'monthly', 'unit_amount' =>   8_000], // SGD  80 /mo
                ['currency' => 'SGD', 'billing_interval' => 'yearly',  'unit_amount' =>  80_000], // SGD 800 /yr
                ['currency' => 'MYR', 'billing_interval' => 'monthly', 'unit_amount' =>  24_000], // MYR 240 /mo
                ['currency' => 'MYR', 'billing_interval' => 'yearly',  'unit_amount' => 240_000], // MYR 2,400 /yr
            ],
            'enterprise-standard' => [
                ['currency' => 'SGD', 'billing_interval' => 'monthly', 'unit_amount' =>   10_000], // SGD  100 /mo
                ['currency' => 'SGD', 'billing_interval' => 'yearly',  'unit_amount' =>  100_000], // SGD 1,000 /yr
                ['currency' => 'MYR', 'billing_interval' => 'monthly', 'unit_amount' =>   30_000], // MYR  300 /mo
                ['currency' => 'MYR', 'billing_interval' => 'yearly',  'unit_amount' =>  300_000], // MYR 3,000 /yr
            ],
            'enterprise-custom' => [
                // Baseline recurring only — custom project fees quoted separately
                ['currency' => 'SGD', 'billing_interval' => 'monthly', 'unit_amount' =>   12_000], // SGD  120 /mo (baseline)
                ['currency' => 'SGD', 'billing_interval' => 'yearly',  'unit_amount' =>  120_000], // SGD 1,200 /yr
                ['currency' => 'MYR', 'billing_interval' => 'monthly', 'unit_amount' =>   48_000], // MYR  480 /mo (baseline)
                ['currency' => 'MYR', 'billing_interval' => 'yearly',  'unit_amount' =>  480_000], // MYR 4,800 /yr
            ],
        ];

        foreach ($families as $familyData) {
            $familyId = DB::table('plan_families')->insertGetId(array_merge($familyData, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $versionId = DB::table('plan_versions')->insertGetId([
                'plan_family_id'     => $familyId,
                'version_label'      => 'v1',
                'description'        => 'Initial release — April 2026',
                'is_active'          => true,
                'is_publicly_listed' => true,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            foreach ($prices[$familyData['key']] as $price) {
                DB::table('plan_prices')->insert(array_merge($price, [
                    'plan_version_id' => $versionId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]));
            }
        }

        $this->command->info('PlanCatalogSeeder: 4 plan families seeded with SGD + MYR pricing (monthly + yearly).');
    }
}
