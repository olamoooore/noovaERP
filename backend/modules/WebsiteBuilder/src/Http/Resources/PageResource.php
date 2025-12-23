<?php

namespace Modules\WebsiteBuilder\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'website_id' => $this->website_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'is_home' => $this->is_home,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'content' => $this->whenLoaded('currentVersion', fn() => $this->currentVersion->content),
            'version' => $this->whenLoaded('currentVersion', fn() => $this->currentVersion->version),
            'website' => new WebsiteResource($this->whenLoaded('website')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
