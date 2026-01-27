<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Modules\EventManagement\Services\AccessControlService;
use App\Models\User;
use Mockery;

/**
 * Property Test 26: Configuration Access Control
 * 
 * Feature: erp-event-management-phase4
 * 
 * Validates Requirements: 12.2, 12.3
 * 
 * This property-based test ensures that non-staff users (customers) cannot
 * access configuration endpoints, while admin users can.
 * 
 * Property Tested:
 * For any user without appropriate authorization, attempting to access event
 * configuration endpoints should fail with a 403 Forbidden response.
 */
class ConfigurationAccessControlTest extends TestCase
{
    protected AccessControlService $accessControlService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize service
        $this->accessControlService = new AccessControlService();
    }

    /**
     * Property 26: Configuration Access Control
     * 
     * For any user without appropriate authorization, attempting to access event
     * configuration endpoints should fail with a 403 Forbidden response.
     * 
     * Validates: Requirements 12.2, 12.3
     * 
     * @test
     */
    public function property_non_admin_users_cannot_access_configuration(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate customer user (non-admin)
                $customerUser = $this->generateMockCustomerUser();
                
                // Attempt to check if user can configure events
                $canConfigure = $this->accessControlService->canConfigureEvents($customerUser);
                
                // Assert access is denied
                if ($canConfigure === false) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'user_id' => $customerUser->id ?? 'mock',
                        'can_configure' => $canConfigure,
                        'expected' => false,
                        'message' => 'Customer user should not be able to configure events',
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
            "Configuration access control failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass configuration access control test"
        );
    }

    /**
     * Additional test: Staff users (non-admin) cannot access configuration
     * 
     * This verifies that even staff users without admin role cannot configure events.
     * 
     * @test
     */
    public function property_staff_users_without_admin_cannot_access_configuration(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate staff user (non-admin)
                $staffUser = $this->generateMockStaffUser();
                
                // Attempt to check if user can configure events
                $canConfigure = $this->accessControlService->canConfigureEvents($staffUser);
                
                // Assert access is denied
                if ($canConfigure === false) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'user_id' => $staffUser->id ?? 'mock',
                        'can_configure' => $canConfigure,
                        'expected' => false,
                        'message' => 'Staff user (non-admin) should not be able to configure events',
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
            "Staff configuration access failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass staff configuration access test"
        );
    }

    /**
     * Additional test: Admin users CAN access configuration
     * 
     * This complements the main property test by verifying that admin users
     * are correctly granted access.
     * 
     * @test
     */
    public function property_admin_users_can_access_configuration(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate admin user
                $adminUser = $this->generateMockAdminUser();
                
                // Attempt to check if user can configure events
                $canConfigure = $this->accessControlService->canConfigureEvents($adminUser);
                
                // Assert access is granted
                if ($canConfigure === true) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'user_id' => $adminUser->id ?? 'mock',
                        'can_configure' => $canConfigure,
                        'expected' => true,
                        'message' => 'Admin user should be able to configure events',
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
            "Admin configuration access failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass admin configuration access test"
        );
    }

    // Helper Methods

    /**
     * Generate a mock customer user (non-admin)
     * Uses Mockery to create a user that returns false for hasAnyRole
     */
    private function generateMockCustomerUser()
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->with(['admin', 'super-admin'])
            ->andReturn(false);
        $user->id = rand(1000, 9999);
        
        return $user;
    }

    /**
     * Generate a mock staff user (non-admin)
     * Uses Mockery to create a user that returns false for admin roles
     */
    private function generateMockStaffUser()
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->with(['admin', 'super-admin'])
            ->andReturn(false);
        $user->id = rand(1000, 9999);
        $user->role = 'staff';
        
        return $user;
    }

    /**
     * Generate a mock admin user
     * Uses Mockery to create a user that returns true for hasAnyRole
     */
    private function generateMockAdminUser()
    {
        $adminRoles = ['admin', 'super-admin'];
        $randomRole = $adminRoles[array_rand($adminRoles)];

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->with(['admin', 'super-admin'])
            ->andReturn(true);
        $user->id = rand(1000, 9999);
        $user->role = $randomRole;
        
        return $user;
    }
}
