<?php

namespace Modules\EventManagement\Tests\Unit;

use Modules\EventManagement\Exceptions\CapacityExceededException;
use Modules\EventManagement\Exceptions\EventManagementException;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;
use Modules\EventManagement\Exceptions\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;

class ExceptionHierarchyTest extends TestCase
{
    public function test_invalid_state_transition_exception_extends_base()
    {
        $exception = new InvalidStateTransitionException('new', 'cancelled', 123);

        $this->assertInstanceOf(EventManagementException::class, $exception);
        $this->assertEquals('INVALID_STATE_TRANSITION', $exception->getErrorCode());
        $this->assertEquals(422, $exception->getHttpStatusCode());

        $details = $exception->getDetails();
        $this->assertEquals('new', $details['from_state']);
        $this->assertEquals('cancelled', $details['to_state']);
        $this->assertEquals(123, $details['booking_id']);
    }

    public function test_capacity_exceeded_exception_extends_base()
    {
        $exception = new CapacityExceededException(456, 5, 2);

        $this->assertInstanceOf(EventManagementException::class, $exception);
        $this->assertEquals('CAPACITY_EXCEEDED', $exception->getErrorCode());
        $this->assertEquals(409, $exception->getHttpStatusCode());

        $details = $exception->getDetails();
        $this->assertEquals(456, $details['event_id']);
        $this->assertEquals(5, $details['requested_tickets']);
        $this->assertEquals(2, $details['available_capacity']);
    }

    public function test_feature_not_enabled_exception_extends_base()
    {
        $exception = new FeatureNotEnabledException('event_management', 'tenant123');

        $this->assertInstanceOf(EventManagementException::class, $exception);
        $this->assertEquals('FEATURE_NOT_ENABLED', $exception->getErrorCode());
        $this->assertEquals(403, $exception->getHttpStatusCode());

        $details = $exception->getDetails();
        $this->assertEquals('event_management', $details['feature']);
        $this->assertEquals('tenant123', $details['tenant_id']);
    }

    public function test_exception_messages_are_properly_formatted()
    {
        $stateException = new InvalidStateTransitionException('new', 'cancelled', 123);
        $this->assertStringContainsString("Invalid state transition from 'new' to 'cancelled'", $stateException->getMessage());
        $this->assertStringContainsString('booking ID 123', $stateException->getMessage());

        $capacityException = new CapacityExceededException(456, 5, 2);
        $this->assertStringContainsString('Event has reached maximum capacity', $capacityException->getMessage());
        $this->assertStringContainsString('Requested 5 tickets', $capacityException->getMessage());
        $this->assertStringContainsString('only 2 available', $capacityException->getMessage());

        $featureException = new FeatureNotEnabledException('event_management', 'tenant123');
        $this->assertStringContainsString("Feature 'event_management' is not enabled", $featureException->getMessage());
        $this->assertStringContainsString("tenant 'tenant123'", $featureException->getMessage());
    }
}
