<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Modules\HRM\Models\AttendanceDailySummary;
use Modules\HRM\Models\AttendanceEvent;

class AttendanceSummaryService
{
    /**
     * Update the daily attendance summary for the employee based on the given event.
     * Called immediately after an event is ingested.
     */
    public function processEvent(AttendanceEvent $event): void
    {
        $date = Carbon::parse($event->captured_at)->toDateString();

        $summary = AttendanceDailySummary::firstOrCreate(
            ['employee_id' => $event->employee_id, 'date' => $date],
            ['status' => 'present']
        );

        $capturedTime = Carbon::parse($event->captured_at)->format('H:i:s');

        if ($event->event_type === 'check_in') {
            // Keep the earliest check_in of the day
            if (is_null($summary->first_check_in) || $capturedTime < $summary->first_check_in) {
                $summary->first_check_in = $capturedTime;
            }
        } elseif ($event->event_type === 'check_out') {
            // Keep the latest check_out of the day
            if (is_null($summary->last_check_out) || $capturedTime > $summary->last_check_out) {
                $summary->last_check_out = $capturedTime;
            }
        }

        // Recalculate worked_minutes when both timestamps are available
        if ($summary->first_check_in && $summary->last_check_out) {
            $checkIn  = Carbon::createFromFormat('H:i:s', $summary->first_check_in);
            $checkOut = Carbon::createFromFormat('H:i:s', $summary->last_check_out);
            $summary->worked_minutes = max(0, $checkIn->diffInMinutes($checkOut));
        }

        // Determine exception state
        $hasBoth    = $summary->first_check_in && $summary->last_check_out;
        $hasOnlyIn  = $summary->first_check_in && !$summary->last_check_out;
        $hasOnlyOut = !$summary->first_check_in && $summary->last_check_out;

        if ($hasBoth) {
            $summary->has_exception  = false;
            $summary->exception_type = 'none';
        } elseif ($hasOnlyOut) {
            $summary->has_exception  = true;
            $summary->exception_type = 'missing_check_in';
        } elseif ($hasOnlyIn) {
            $summary->has_exception  = true;
            $summary->exception_type = 'missing_check_out';
        } else {
            $summary->has_exception  = true;
            $summary->exception_type = 'partial_day';
        }

        $summary->save();

        $event->update(['status' => 'processed']);
    }
}
