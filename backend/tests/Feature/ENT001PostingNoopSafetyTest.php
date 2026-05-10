<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ENT-001: Entitlement safety
 *
 * Feature/plan restrictions may block access, but must never degrade posting
 * into a silent no-op. For stock-tracked orders, missing cost basis must fail
 * closed instead of skipping COGS/inventory lines.
 */
class ENT001PostingNoopSafetyTest extends TestCase
{
    public function test_pos_posting_service_fails_closed_when_tracked_items_have_no_cost_basis(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        $this->assertStringContainsString('orderHasTrackedInventoryItems', $source,
            'PosPostingService must check whether order has tracked inventory items.');
        $this->assertStringContainsString('Cannot post stock-tracked POS order without cost basis', $source,
            'PosPostingService must hard-fail when COGS/inventory lines would be skipped for tracked items.');
        $this->assertStringContainsString('throw new \\RuntimeException', $source,
            'PosPostingService must throw instead of silently continuing.');
    }

    public function test_pos_posting_service_guard_runs_before_cogs_line_creation(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        $guardPos = strpos($source, 'Cannot post stock-tracked POS order without cost basis');
        $cogsPos = strpos($source, "accountMappingKey: 'cogs'");

        $this->assertNotFalse($guardPos, 'POS fail-closed guard must exist.');
        $this->assertNotFalse($cogsPos, 'POS cogs mapping line must exist.');
        $this->assertLessThan($cogsPos, $guardPos,
            'POS fail-closed guard must execute before COGS line creation.');
    }

    public function test_web_order_posting_service_fails_closed_when_tracked_items_have_no_cost_basis(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString('orderHasTrackedInventoryItems', $source,
            'WebOrderPostingService must check whether order has tracked inventory items.');
        $this->assertStringContainsString('Cannot post stock-tracked web order without cost basis', $source,
            'WebOrderPostingService must hard-fail when COGS/inventory lines would be skipped for tracked items.');
        $this->assertStringContainsString('throw new \\RuntimeException', $source,
            'WebOrderPostingService must throw instead of silently continuing.');
    }

    public function test_web_order_posting_service_guard_runs_before_cogs_line_creation(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $guardPos = strpos($source, 'Cannot post stock-tracked web order without cost basis');
        $cogsPos = strpos($source, "accountMappingKey: 'cogs'");

        $this->assertNotFalse($guardPos, 'Web fail-closed guard must exist.');
        $this->assertNotFalse($cogsPos, 'Web cogs mapping line must exist.');
        $this->assertLessThan($cogsPos, $guardPos,
            'Web fail-closed guard must execute before COGS line creation.');
    }

    public function test_entitlement_controller_remains_deny_closed_for_mutations(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Api/FeatureController.php'));

        $this->assertStringContainsString('Feature entitlement changes must be managed through plan configuration.', $source,
            'FeatureController::toggle must remain deny-closed to prevent unsafe runtime mutation toggles.');
        $this->assertStringContainsString("return response()->json([", $source,
            'FeatureController should explicitly return forbidden responses for mutation attempts.');
    }
}
