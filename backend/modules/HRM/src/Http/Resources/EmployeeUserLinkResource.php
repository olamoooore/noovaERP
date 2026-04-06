<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeUserLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'employee_id' => $this->employee_id,
            'user_id'     => $this->user_id,
            'type'        => $this->type,
            'user'        => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
