<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MergeFieldResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'group' => $this->resource['group'] ?? null,
            'fields' => collect($this->resource['fields'] ?? [])->map(fn ($f) => [
                'token' => $f['token'] ?? null,
                'label' => $f['label'] ?? null,
            ])->values(),
        ];
    }
}
