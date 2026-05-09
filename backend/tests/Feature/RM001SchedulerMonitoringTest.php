<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * RM-001 runtime monitoring closure proof.
 */
class RM001SchedulerMonitoringTest extends TestCase
{
    public function test_expiry_command_logs_structured_success_metrics(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('Reservation expiry command completed', $source,
            'Expiry command must log structured success event.');
        $this->assertStringContainsString("'status' => 'success'", $source,
            'Success log payload must include status=success.');
        $this->assertStringContainsString("'expired_count' => \$expiredCount", $source,
            'Success log payload must include expired_count metric.');
        $this->assertStringContainsString("'duration_ms' => \$durationMs", $source,
            'Success log payload must include duration_ms metric.');
    }

    public function test_expiry_command_logs_structured_failure_metrics(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('Failed to run reservation expiry command', $source,
            'Failure path must still log command failure.');
        $this->assertStringContainsString("'status' => 'failed'", $source,
            'Failure log payload must include status=failed.');
        $this->assertStringContainsString("'duration_ms' => \$durationMs", $source,
            'Failure log payload must include duration_ms metric.');
    }

    public function test_expiry_scheduler_frequency_remains_every_ten_minutes(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("Schedule::command('inventory:expire-reservations')", $source,
            'Scheduler entry for inventory:expire-reservations must remain present.');
        $this->assertStringContainsString('->everyTenMinutes()', $source,
            'Reservation expiry scheduler must run every 10 minutes for checkout-sensitive flows.');
        $this->assertStringContainsString('->withoutOverlapping()', $source,
            'Reservation expiry scheduler must remain overlap-protected.');
    }
}
