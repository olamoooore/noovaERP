<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CloseCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'entity_id'           => $this->entity_id,
            'accounting_book_id'  => $this->accounting_book_id,
            'period_id'           => $this->period_id,
            'name'                => $this->name,
            'template_id'         => $this->template_id,
            'status'              => $this->status,
            'created_by'          => $this->created_by,
            'opened_at'           => $this->opened_at,
            'closed_at'           => $this->closed_at,
            'created_at'          => $this->created_at,
        ];
    }
}
