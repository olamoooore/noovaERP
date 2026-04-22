<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI Finding resource.
 * SECURITY: raw structured_output (full AI payload) is NOT exposed here.
 * Permission-scoped access to structured_output requires a separate policy gate.
 */
class FindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'review_id'    => $this->review_id,
            'account_code' => $this->account_code,
            'finding_type' => $this->finding_type,
            'status'       => $this->status,
            'risk_status'  => $this->risk_status,
            'confidence'   => $this->confidence,
            'description'  => $this->description,
            'accepted_by'  => $this->accepted_by,
            'accepted_at'  => $this->accepted_at,
            'dismissed_by' => $this->dismissed_by,
            'dismissed_at' => $this->dismissed_at,
            'created_at'   => $this->created_at,
            // structured_output intentionally omitted — use dedicated permission-gated endpoint
        ];
    }
}
