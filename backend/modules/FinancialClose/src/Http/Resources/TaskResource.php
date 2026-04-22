<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'cycle_id'         => $this->cycle_id,
            'title'            => $this->title,
            'status'           => $this->status,
            'priority'         => $this->priority,
            'assigned_to'      => $this->assigned_to,
            'due_date'         => $this->due_date,
            'soft_due_date'    => $this->soft_due_date ?? null,
            'is_critical'      => (bool) $this->is_critical,
            'metadata'         => $this->metadata,
            'created_at'       => $this->created_at,
        ];
    }
}
