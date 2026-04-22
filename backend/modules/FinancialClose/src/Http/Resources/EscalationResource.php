<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EscalationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'task_id'          => $this->task_id,
            'escalation_level' => $this->escalation_level,
            'status'           => $this->status,
            'reason'           => $this->reason,
            'escalated_to'     => $this->escalated_to,
            'resolved_at'      => $this->resolved_at,
            'created_at'       => $this->created_at,
        ];
    }
}
