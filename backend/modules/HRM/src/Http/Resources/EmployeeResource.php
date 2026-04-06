<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'employee_number' => $this->employee_number,
            'name'            => $this->name,
            'email'           => $this->email,
            'contact_number'  => $this->contact_number,
            'status'          => $this->status,
            'user'            => $this->whenLoaded('user', fn() => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'department'      => $this->whenLoaded('department', fn() => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'location'        => $this->whenLoaded('location', fn() => [
                'id'   => $this->location->id,
                'name' => $this->location->name,
            ]),
            'manager'         => $this->whenLoaded('manager', fn() => [
                'id'   => $this->manager->id,
                'name' => $this->manager->name,
            ]),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
