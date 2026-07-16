<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'status' => $this->status,
            'total_gross' => $this->total_gross,
            'total_deductions' => $this->total_deductions,
            'total_net' => $this->total_net,
            'currency' => $this->currency,
            'entries_count' => $this->whenLoaded('payrolls', fn () => $this->payrolls->count(), 0),
            'payrolls' => PayrollResource::collection($this->whenLoaded('payrolls')),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'notes' => $this->notes,
            'journal_entry_id' => $this->journal_entry_id,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
