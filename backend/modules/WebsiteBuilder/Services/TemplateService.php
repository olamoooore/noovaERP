<?php

namespace Modules\WebsiteBuilder\Services;

use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\Template;
use Modules\WebsiteBuilder\Models\Website;

class TemplateService
{
    /**
     * Clone a template to create a new website.
     */
    public function cloneToWebsite(Template $template, string $name, ?string $slug, int $userId): Website
    {
        $schema = $template->schema;

        $website = Website::create([
            'name' => $name,
            'slug' => $slug ?? Str::slug($name),
            'settings' => $schema['styles'] ?? [],
            'created_by' => $userId,
        ]);

        // Create pages from template
        if (! empty($schema['pages'])) {
            foreach ($schema['pages'] as $index => $pageData) {
                $page = $website->pages()->create([
                    'title' => $pageData['title'] ?? 'Page '.($index + 1),
                    'slug' => $pageData['slug'] ?? 'page-'.($index + 1),
                    'is_home' => $pageData['is_home'] ?? ($index === 0),
                    'seo_title' => $pageData['seo_title'] ?? null,
                    'seo_description' => $pageData['seo_description'] ?? null,
                    'status' => 'draft',
                    'sort_order' => $index,
                ]);

                $page->createVersion($pageData['content'] ?? [], $userId);
            }
        }

        return $website;
    }
}
