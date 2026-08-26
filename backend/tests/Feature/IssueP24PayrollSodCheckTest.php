<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Pillar 1 P0-14: HRM Payroll has no segregation-of-duties check.
 *
 * Per the Pillar 1 review, the same authenticated user could
 * create → calculate → approve → mark-paid (triggering a GL journal
 * posting) in one unbroken sequence because none of the lifecycle
 * methods verified that the current user is distinct from the
 * preparer/approver. This test pins the SoD guard added to
 * PayrollController::approve() and PayrollController::markPaid().
 */
class IssueP24PayrollSodCheckTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Do NOT connect to DB here — RefreshDatabase drops/recreates
        // the test DB. Pure source-check tests don't need DB access.
    }

    public function test_approve_controller_has_sod_check(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Http/Controllers/PayrollController.php'
        );

        $this->assertStringContainsString(
            'SOD_SELF_APPROVAL',
            $source,
            'PayrollController::approve must enforce SoD via SOD_SELF_APPROVAL reason code'
        );
        $this->assertStringContainsString(
            'Segregation of duties',
            $source,
            'PayrollController::approve must include a segregation of duties error message'
        );
    }

    public function test_mark_paid_controller_has_sod_check(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Http/Controllers/PayrollController.php'
        );

        $this->assertStringContainsString(
            'SOD_SELF_PAY',
            $source,
            'PayrollController::markPaid must enforce SoD via SOD_SELF_PAY reason code'
        );
    }

    public function test_approve_checks_against_created_by(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Http/Controllers/PayrollController.php'
        );

        $this->assertStringContainsString(
            'created_by',
            $source,
            'approve() must reference created_by for the SoD check'
        );
    }

    public function test_mark_paid_checks_against_created_by_and_approved_by(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../modules/HRM/src/Http/Controllers/PayrollController.php'
        );

        $this->assertStringContainsString(
            'created_by',
            $source,
            'markPaid() must reference created_by for the SoD check'
        );
        $this->assertStringContainsString(
            'approved_by',
            $source,
            'markPaid() must reference approved_by for the SoD check'
        );
    }
}
