<?php

namespace Modules\IoT\Services;

use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\AttendanceEvent;
use Modules\HRM\Models\EmployeeBiometricSubject;
use Modules\HRM\Services\AttendanceSummaryService;

/**
 * Bridges palm vein scan results to HRM attendance events.
 *
 * When the palm vein scanner identifies a user (by subject_code),
 * this service finds the matching employee and creates a check-in
 * or check-out AttendanceEvent.
 */
class PalmVeinAttendanceService
{
    public function __construct(
        private AttendanceSummaryService $summaryService
    ) {}

    /**
     * Process a palm vein scan and create the appropriate attendance event.
     *
     * @param  string  $subjectCode  The identifier returned by the scanner (matches EmployeeBiometricSubject.subject_code)
     * @param  string  $deviceId  The IoT device ID that performed the scan
     * @param  string  $agentId  The agent that reported the scan
     * @param  string|null  $capturedAt  ISO8601 timestamp from the device (null = now)
     * @param  string|null  $sourceEventId  Idempotency key — if omitted, one is generated
     * @return array{success: bool, message: string, event?: AttendanceEvent, employee?: object}
     */
    public function processScan(
        string $subjectCode,
        ?string $deviceId,
        string $agentId,
        ?string $capturedAt = null,
        ?string $sourceEventId = null
    ): array {
        // 1. Look up the biometric subject — works for any type (palm_vein, rfid, fingerprint)
        $subject = EmployeeBiometricSubject::where('subject_code', $subjectCode)
            ->where('status', 'active')
            ->with('employee')
            ->first();

        if (! $subject) {
            Log::warning('PalmVeinAttendance: No active biometric subject found', [
                'subject_code' => $subjectCode,
                'device_id' => $deviceId,
            ]);

            return [
                'success' => false,
                'message' => 'No employee registered for this palm code. Register the employee first.',
            ];
        }

        $employee = $subject->employee;
        if (! $employee) {
            return [
                'success' => false,
                'message' => 'Biometric subject has no linked employee.',
            ];
        }

        // 2. Determine check-in vs check-out
        $eventType = $this->determineEventType($employee->id);

        // 3. Build idempotency key
        $timestamp = $capturedAt ? \Carbon\Carbon::parse($capturedAt) : now();
        $sourceId = $sourceEventId ?? sprintf(
            'palm-%s-%s-%s',
            $subjectCode,
            $deviceId,
            $timestamp->format('YmdHis')
        );

        // 4. Prevent duplicate events (idempotency)
        if (AttendanceEvent::where('source_event_id', $sourceId)->exists()) {
            Log::info('PalmVeinAttendance: Duplicate event skipped', ['source_event_id' => $sourceId]);

            return [
                'success' => false,
                'message' => 'Duplicate scan — event already recorded.',
            ];
        }

        // 5. Create the attendance event
        $event = AttendanceEvent::create([
            'employee_id' => $employee->id,
            'subject_id' => $subject->id,
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'captured_at' => $timestamp,
            'source_event_id' => $sourceId,
            'status' => 'pending',
            'meta' => [
                'agent_id' => $agentId,
                'subject_code' => $subjectCode,
                'source' => 'palm_vein',
            ],
        ]);

        // 6. Process into daily summary
        try {
            $this->summaryService->processEvent($event);
        } catch (\Throwable $e) {
            Log::error('PalmVeinAttendance: Failed to update daily summary', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the whole request — the event is recorded, summary can be reprocessed
        }

        Log::info('PalmVeinAttendance: Attendance event created', [
            'employee_id' => $employee->id,
            'event_type' => $eventType,
            'event_id' => $event->id,
        ]);

        return [
            'success' => true,
            'message' => sprintf('%s recorded for %s', ucfirst(str_replace('_', '-', $eventType)), $employee->name),
            'event' => $event,
            'employee' => $employee,
            'event_type' => $eventType,
        ];
    }

    /**
     * Decide check_in or check_out based on the employee's last event today.
     * Logic: if the last event today is check_in → next is check_out, otherwise check_in.
     */
    private function determineEventType(int $employeeId): string
    {
        $lastEvent = AttendanceEvent::where('employee_id', $employeeId)
            ->whereDate('captured_at', today())
            ->whereIn('event_type', ['check_in', 'check_out'])
            ->latest('captured_at')
            ->first();

        if ($lastEvent && $lastEvent->event_type === 'check_in') {
            return 'check_out';
        }

        return 'check_in';
    }
}
