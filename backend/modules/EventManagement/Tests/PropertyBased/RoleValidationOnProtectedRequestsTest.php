<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Modules\EventManagement\Http\Middleware\EnsureStaffMiddleware;
use Modules\EventManagement\Services\AccessControlService;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Property Test 28: Role Validation on Protected Requests
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 12.5
 *
 * This property-based test ensures that role validation occurs before processing
 * any request to a protected API endpoint.
 *
 * Property Tested:
 * For any request to a protected API endpoint, the system should validate the
 * user's role before processing the request.
 */
class RoleValidationOnProtectedRequestsTest extends TestCase
{
    protected EnsureStaffMiddleware $middleware;

    protected AccessControlService $accessControlService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the AccessControlService to avoid database dependencies
        $this->accessControlService = Mockery::mock(AccessControlService::class);

        // Create middleware with real ResponseFactory from Laravel container
        $this->middleware = new EnsureStaffMiddleware(
            $this->accessControlService,
            app('Illuminate\Contracts\Routing\ResponseFactory')
        );
    }

    /**
     * Property 28: Role Validation on Protected Requests
     *
     * For any request to a protected API endpoint, the system should validate
     * the user's role before processing the request.
     *
     * Validates: Requirements 12.5
     *
     * @test
     */
    public function property_role_validation_occurs_before_request_processing(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random user (could be customer or staff)
                $isStaff = (bool) rand(0, 1);
                $user = $isStaff ? $this->generateMockStaffUser() : $this->generateMockCustomerUser();

                // Mock the AccessControlService behavior for this iteration
                $this->accessControlService
                    ->shouldReceive('isStaff')
                    ->with($user)
                    ->andReturn($isStaff);

                // Create a mock request with the user
                $request = $this->createMockRequest($user);

                // Track if the next closure was called (indicates request was processed)
                $nextCalled = false;
                $next = function ($req) use (&$nextCalled) {
                    $nextCalled = true;

                    return new JsonResponse(['success' => true]);
                };

                // Execute middleware
                $response = $this->middleware->handle($request, $next);

                // Validate behavior based on user role
                if ($isStaff) {
                    // Staff users: next should be called, response should be successful
                    if ($nextCalled && $response->getStatusCode() === 200) {
                        $passedIterations++;
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'user_type' => 'staff',
                            'next_called' => $nextCalled,
                            'status_code' => $response->getStatusCode(),
                            'expected' => 'next() should be called and return 200',
                            'message' => 'Staff user should be allowed to proceed',
                        ];
                    }
                } else {
                    // Customer users: next should NOT be called, response should be 403
                    if (! $nextCalled && $response->getStatusCode() === Response::HTTP_FORBIDDEN) {
                        $passedIterations++;
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'user_type' => 'customer',
                            'next_called' => $nextCalled,
                            'status_code' => $response->getStatusCode(),
                            'expected' => 'next() should NOT be called and return 403',
                            'message' => 'Customer user should be blocked before processing',
                        ];
                    }
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
            'Role validation failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass role validation test'
        );
    }

    /**
     * Additional test: Unauthenticated requests are rejected before processing
     *
     * This verifies that authentication is checked before role validation.
     *
     * @test
     */
    public function property_unauthenticated_requests_rejected_before_processing(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create a mock request without a user (unauthenticated)
                $request = $this->createMockRequest(null);

                // Track if the next closure was called
                $nextCalled = false;
                $next = function ($req) use (&$nextCalled) {
                    $nextCalled = true;

                    return new JsonResponse(['success' => true]);
                };

                // Execute middleware
                $response = $this->middleware->handle($request, $next);

                // Validate that request was rejected before processing
                if (! $nextCalled && $response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'next_called' => $nextCalled,
                        'status_code' => $response->getStatusCode(),
                        'expected' => 'next() should NOT be called and return 401',
                        'message' => 'Unauthenticated request should be blocked before processing',
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
            'Unauthenticated request rejection failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass unauthenticated request rejection test'
        );
    }

    /**
     * Additional test: Role validation returns descriptive error messages
     *
     * This verifies that error responses include helpful information.
     *
     * @test
     */
    public function property_role_validation_returns_descriptive_errors(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate customer user (will be rejected)
                $user = $this->generateMockCustomerUser();

                // Mock the AccessControlService to return false for customer
                $this->accessControlService
                    ->shouldReceive('isStaff')
                    ->with($user)
                    ->andReturn(false);

                $request = $this->createMockRequest($user);

                $next = function ($req) {
                    return new JsonResponse(['success' => true]);
                };

                // Execute middleware
                $response = $this->middleware->handle($request, $next);

                // Validate error response structure
                $data = json_decode($response->getContent(), true);

                $hasErrorStructure = isset($data['error']) &&
                                    isset($data['error']['code']) &&
                                    isset($data['error']['message']);

                if ($hasErrorStructure && $response->getStatusCode() === Response::HTTP_FORBIDDEN) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'has_error_structure' => $hasErrorStructure,
                        'response_data' => $data,
                        'status_code' => $response->getStatusCode(),
                        'message' => 'Error response should have descriptive structure',
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
            'Descriptive error message failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass descriptive error message test'
        );
    }

    // Helper Methods

    /**
     * Create a mock request with a user
     */
    private function createMockRequest($user): Request
    {
        $request = Mockery::mock(Request::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('header')
            ->with('X-Request-ID', Mockery::any())
            ->andReturn('test-request-'.uniqid());

        return $request;
    }

    /**
     * Generate a mock customer user (non-staff)
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
