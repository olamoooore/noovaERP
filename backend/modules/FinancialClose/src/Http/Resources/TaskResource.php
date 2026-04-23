<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id'               => $this->id,
            'cycle_id'         => $this->cycle_id,
            'title'            => $this->title,
            'status'           => $this->status,
            'priority'         => $this->priority,
            'assigned_to'      => $this->assigned_to,
            'due_date'         => $this->due_date,
            'soft_due_date'    => $this->soft_due_date ?? null,
            'completed_at'     => $this->completed_at ?? null,
            'is_critical'      => (bool) $this->is_critical,
            'metadata'         => $this->metadata,
            'description'      => $metadata['description'] ?? null,
            'owner_label'       => $metadata['owner_label'] ?? null,
            'workstream'       => $metadata['workstream'] ?? null,
            'deliverable'      => $metadata['deliverable'] ?? null,
            'business_impact'  => $metadata['business_impact'] ?? null,
            'next_step'        => $metadata['next_step'] ?? null,
            'blocker_reason'   => $metadata['blocker_reason'] ?? null,
            'related_accounts' => array_values($metadata['related_accounts'] ?? []),
            'evidence_items'   => array_values($metadata['evidence_items'] ?? []),
            'checklist_items'  => array_values($metadata['checklist_items'] ?? []),
            'dependencies'     => $this->whenLoaded('dependencies', function () {
                return $this->dependencies->map(function ($dependency): array {
                    return [
                        'id' => $dependency->id,
                        'dependency_type' => $dependency->dependency_type,
                        'depends_on_task_id' => $dependency->depends_on_task_id,
                        'depends_on_task_title' => $dependency->dependsOnTask?->title,
                        'depends_on_task_status' => $dependency->dependsOnTask?->status,
                    ];
                })->values();
            }, []),
            'escalations'      => $this->whenLoaded('escalations', function () {
                return $this->escalations->map(fn ($escalation) => [
                    'id' => $escalation->id,
                    'escalation_level' => $escalation->escalation_level,
                    'status' => $escalation->status,
                    'reason' => $escalation->reason,
                    'escalated_to' => $escalation->escalated_to,
                    'resolved_at' => $escalation->resolved_at,
                    'created_at' => $escalation->created_at,
                ])->values();
            }, []),
            'detail_title'     => $metadata['detail_title'] ?? Str::headline($this->title),
            'created_at'       => $this->created_at,
        ];
    }
}
