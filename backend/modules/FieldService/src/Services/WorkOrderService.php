<?php

namespace Modules\FieldService\Services;

use Illuminate\Support\Facades\Auth;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorkOrderActivity;
use Illuminate\Validation\ValidationException;

class WorkOrderService
{
    private FieldServiceNotificationService $notifications;

    public function __construct()
    {
        $this->notifications = app(FieldServiceNotificationService::class);
    }

    /**
     * Create a new Work Order with auto-generated reference.
     */
    public function create(array $data): WorkOrder
    {
        $data['reference']  = WorkOrder::generateReference();
        $data['created_by'] = Auth::id();
        $data['status']     = $data['status'] ?? 'draft';

        $wo = WorkOrder::create($data);

        $this->logActivity($wo, 'system', 'Work Order created.', ['status' => 'draft']);

        // Assign lead technician if provided
        if (!empty($data['lead_technician_id'])) {
            $wo->technicians()->syncWithoutDetaching([
                $data['lead_technician_id'] => ['role' => 'lead', 'assigned_at' => now()],
            ]);
        }

        // Assign additional technicians if provided
        if (!empty($data['technician_ids']) && is_array($data['technician_ids'])) {
            foreach ($data['technician_ids'] as $tid) {
                if ($tid !== $data['lead_technician_id']) {
                    $wo->technicians()->syncWithoutDetaching([
                        $tid => ['role' => 'assistant', 'assigned_at' => now()],
                    ]);
                }
            }
        }

        return $wo->load(['type', 'customer', 'leadTechnician', 'technicians', 'team']);
    }

    /**
     * Update a Work Order.
     */
    public function update(WorkOrder $wo, array $data): WorkOrder
    {
        $wo->fill($data);
        $wo->save();

        // Handle technician assignment updates
        if (array_key_exists('technician_ids', $data)) {
            $sync = [];
            if (!empty($data['lead_technician_id'])) {
                $sync[$data['lead_technician_id']] = ['role' => 'lead', 'assigned_at' => now()];
            }
            foreach (($data['technician_ids'] ?? []) as $tid) {
                if (!isset($sync[$tid])) {
                    $sync[$tid] = ['role' => 'assistant', 'assigned_at' => now()];
                }
            }
            $wo->technicians()->sync($sync);
            $this->logActivity($wo, 'assignment', 'Technician assignment updated.');
        }

        return $wo->fresh(['type', 'customer', 'leadTechnician', 'technicians', 'team', 'parts', 'timeLogs', 'activities']);
    }

    /**
     * Transition work order status through the state machine.
     */
    public function transitionStatus(WorkOrder $wo, string $newStatus): WorkOrder
    {
        if (!$wo->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from '{$wo->status}' to '{$newStatus}'.",
            ]);
        }

        $oldStatus = $wo->status;
        $wo->status = $newStatus;

        if ($newStatus === 'in_progress' && is_null($wo->actual_start)) {
            $wo->actual_start = now();
        }

        if ($newStatus === 'completed') {
            $wo->actual_end    = $wo->actual_end ?? now();
            $wo->completed_at  = now();
        }

        $wo->save();

        $this->logActivity($wo, 'status_change', "Status changed from {$oldStatus} to {$newStatus}.", [
            'from' => $oldStatus,
            'to'   => $newStatus,
        ]);

        // WhatsApp notifications (non-blocking)
        match ($newStatus) {
            'scheduled'   => $this->notifications->notifyScheduled($wo),
            'in_progress' => $this->notifications->notifyDispatched($wo),
            'completed'   => $this->notifications->notifyCompleted($wo),
            default       => null,
        };

        return $wo;
    }

    /**
     * Assign technicians to a work order.
     */
    public function assignTechnicians(WorkOrder $wo, array $technicianIds, ?int $leadId = null): WorkOrder
    {
        $sync = [];
        foreach ($technicianIds as $tid) {
            $role = ($leadId && $tid == $leadId) ? 'lead' : 'assistant';
            $sync[$tid] = ['role' => $role, 'assigned_at' => now()];
        }
        $wo->technicians()->sync($sync);

        if ($leadId && in_array($leadId, $technicianIds)) {
            $wo->lead_technician_id = $leadId;
            $wo->save();
        }

        $this->logActivity($wo, 'assignment', 'Technicians assigned/updated.');

        return $wo->fresh(['technicians', 'leadTechnician']);
    }

    /**
     * Add a chatter note to a work order.
     */
    public function addNote(WorkOrder $wo, string $body): WorkOrderActivity
    {
        return $this->logActivity($wo, 'note', $body);
    }

    /**
     * Log an activity entry on the work order's chatter.
     */
    public function logActivity(WorkOrder $wo, string $type, string $body, array $metadata = []): WorkOrderActivity
    {
        return WorkOrderActivity::create([
            'work_order_id' => $wo->id,
            'user_id'       => Auth::id(),
            'type'          => $type,
            'body'          => $body,
            'metadata'      => $metadata ?: null,
            'created_at'    => now(),
        ]);
    }
}
