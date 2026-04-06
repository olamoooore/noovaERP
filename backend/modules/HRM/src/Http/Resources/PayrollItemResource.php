<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'category'    => $this->category,
            'amount'      => $this->amount,
            'description' => $this->description,
        ];
    }
}
