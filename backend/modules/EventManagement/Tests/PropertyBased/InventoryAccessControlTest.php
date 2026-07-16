<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use App\Models\User;
use Mockery;
use Modules\EventManagement\Services\AccessControlService;
use Tests\TestCase;

/**
 * Property Test 11: Inventory Access Control
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 6.4, 6.5
 *
 * This property-based test ensures that non-staff users (customers) cannot
 * access inventory data, while staff users can.
 *
 * Property Tested:
 * For any user without staff role, attempting to access inventory data should
 * fail with an authorization error.
 */
class InventoryAccessControlTest extends TestCase
{
    protected AccessControlService $accessControlService;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize service
        $this->accessControlService = new AccessControlService;
    }

    /**
     * Property 11: Inventory Access Control
     *
     * For any user without staff role, attempting to access inventory data
     * should fail with an authorization error.
     *
     * Validates: Requirements 6.4, 6.5
     *
     * @test
     */
    public function property_non_staff_users_cannot_access_inventory_data(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate customer user (non-staff) - mock user without staff roles
                $customerUser = $this->generateMockCustomerUser();

                // Attempt to check if user can view inventory
                $canViewInventory = $this->accessControlService->canViewInventory($customerUser);

                // Assert access is denied
                if ($canViewInventory === false) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'user_id' => $customerUser->id ?? 'mock',
                        'can_view_inventory' => $canViewInventory,
                        'expected' => false,
                        'message' => 'Customer user should not be able to view inventory',
                    ];
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Inventory access control failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass inventory access control test'
        );
    }

    /**
     * Additional test: Staff users CAN access inventory data
     *
     * This complements the main property test by verifying that staff users
     * are correctly granted access.
     *
     * @test
     */
    public function property_staff_users_can_access_inventory_data(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate staff user - mock user with staff roles
                $staffUser = $this->generateMockStaffUser();

                // Attempt to check if user can view inventory
                $canViewInventory = $this->accessControlService->canViewInventory($staffUser);

                // Assert access is granted
                if ($canViewInventory === true) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'user_id' => $staffUser->id ?? 'mock',
                        'can_view_inventory' => $canViewInventory,
                        'expected' => true,
                        'message' => 'Staff user should be able to view inventory',
                    ];
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Staff inventory access failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass staff inventory access test'
        );
    }

    // Helper Methods

    /**
     * Generate a mock customer user (non-staff)
     * Uses Mockery to create a user that returns false for hasAnyRole
     */
    private function generateMockCustomerUser()
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->with(['staff', 'admin', 'super-admin'])
            ->andReturn(false);
        $user->id = rand(1000, 9999);

        return $user;
    }

    /**
     * Generate a mock staff user
     * Uses Mockery to create a user that returns true for hasAnyRole
     */
    private function generateMockStaffUser()
    {
        $staffRoles = ['staff', 'admin', 'super-admin'];
        $randomRole = $staffRoles[array_rand($staffRoles)];

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->with(['staff', 'admin', 'super-admin'])
            ->andReturn(true);
        $user->id = rand(1000, 9999);
        $user->role = $randomRole;

        return $user;
    }
}
