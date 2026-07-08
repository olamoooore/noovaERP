<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'mode' => $this->mode,
            'format' => $this->format,
            'orientation' => $this->orientation,
            'margin' => $this->margin,
            'default_font' => $this->default_font,
            'default_locale' => $this->default_locale,
            'layout_config' => $this->layout_config,
            'html' => $this->html,
            'css' => $this->css,
            'status' => $this->status,
            'published_version' => $this->published_version,
            'is_system_default' => $this->is_system_default,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
