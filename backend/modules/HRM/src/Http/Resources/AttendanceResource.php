<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'employee'   => $this->whenLoaded('employee', fn () => [
                'id'              => $this->employee->id,
                'name'            => $this->employee->name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'date'       => $this->date?->toDateString(),
            'check_in'   => $this->check_in,
            'check_out'  => $this->check_out,
            'duration'   => $this->getDuration(),
            'notes'      => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function getDuration(): ?string
    {
        if (!$this->check_in || !$this->check_out) return null;

        [$inH, $inM]   = explode(':', $this->check_in);
        [$outH, $outM] = explode(':', $this->check_out);

        $minutes = ((int)$outH * 60 + (int)$outM) - ((int)$inH * 60 + (int)$inM);
        if ($minutes <= 0) return null;

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}
