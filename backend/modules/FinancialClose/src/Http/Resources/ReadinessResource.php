<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'readiness_score'     => $this->readiness_score,
            'total_accounts'      => $this->total_accounts,
            'reviewed_accounts'   => $this->reviewed_accounts,
            'signed_off_accounts' => $this->signed_off_accounts,
            'open_blockers'       => $this->open_blockers,
            'open_findings'       => $this->open_findings,
            'is_ready'            => $this->readiness_score >= 100 && $this->open_blockers === 0,
            'generated_at'        => $this->generated_at,
        ];
    }
}
