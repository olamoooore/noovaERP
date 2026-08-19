<?php

namespace Tests\Feature;

use Tests\TestCase;

class RM003LoyaltyRewardStockBoundaryTest extends TestCase
{
    public function test_redemption_service_does_not_use_default_warehouse_fallback_for_product_rewards(): void
    {
        $source = file_get_contents(
            base_path('modules/Loyalty/src/Services/RedemptionService.php')
        );

        $this->assertStringNotContainsString('Warehouse::getDefault()', $source,
            'Product-backed reward redemption must not use default warehouse fallback.');
    }

    public function test_product_backed_rewards_require_explicit_warehouse_and_use_inventory_authority(): void
    {
        $source = file_get_contents(
            base_path('modules/Loyalty/src/Services/RedemptionService.php')
        );

        $this->assertStringContainsString('Cannot redeem product-backed loyalty reward without explicit warehouse_id.', $source,
            'Product-backed reward redemption must hard-fail without warehouse resolution.');
        $this->assertStringContainsString('Cannot cancel product-backed loyalty reward without explicit warehouse_id.', $source,
            'Product-backed reward cancellation must hard-fail without warehouse resolution.');
        $this->assertStringContainsString('inventoryService->adjustStock(', $source,
            'Product-backed rewards must route stock mutation through InventoryService.');
    }

    public function test_non_product_rewards_use_quota_counter_only(): void
    {
        $source = file_get_contents(
            base_path('modules/Loyalty/src/Services/RedemptionService.php')
        );

        $this->assertStringContainsString('if (! $isProductBacked && $reward->reward_quota_quantity !== null)', $source,
            'Quota decrement must apply only to non-product rewards.');
        $this->assertStringContainsString('if (! $isProductBacked && $redemption->reward->reward_quota_quantity !== null)', $source,
            'Quota restoration must apply only to non-product rewards.');
        $this->assertStringContainsString("decrement('reward_quota_quantity')", $source,
            'Non-product reward redemption must decrement reward_quota_quantity.');
        $this->assertStringContainsString("increment('reward_quota_quantity')", $source,
            'Non-product reward cancellation must increment reward_quota_quantity.');
    }

    public function test_rm003_quota_semantic_separation_migration_exists(): void
    {
        $source = file_get_contents(
            base_path('modules/Loyalty/database/migrations/tenant/2026_05_10_000001_add_reward_quota_quantity_to_loyalty_rewards.php')
        );

        $this->assertStringContainsString("'reward_quota_quantity'", $source,
            'RM-003 requires dedicated reward_quota_quantity column.');
        $this->assertStringContainsString("DB::raw('stock_quantity')", $source,
            'Migration must backfill reward_quota_quantity from legacy stock_quantity for compatibility.');
    }
}
