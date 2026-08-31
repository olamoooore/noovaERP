<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * Pillar 2 §3 — Website Builder page payload size guard.
 *
 * Background: GrapesJS embeds inline images as `data:image/…;base64,…` URLs
 * inside the page state. A 5 MB PNG becomes ~6.7 MB of base64 and combined
 * with the rest of the page can easily exceed PHP's `post_max_size` (8 MB
 * default, now 64 MB on production). The fix is two-fold:
 *
 *  1. Frontend (browser-image-compression) compresses images to ≤1920 px,
 *     re-encoded as WebP/JPEG, before saving.
 *  2. Backend (this test) enforces a hard upper bound on the JSON payload
 *     and returns a clear 413 with a human-readable message instead of
 *     letting PHP silently truncate the body.
 */
class WebsiteBuilderPageSizeGuardTest extends TenantTestCase
{
    private User $user;

    private Website $website;

    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('website-builder-size-guard');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->website = Website::create([
            'name' => 'Test Site '.Str::random(6),
            'slug' => 'test-site-'.Str::random(6),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        // Website auto-creates a Home page on creation.
        $this->page = $this->website->pages()->firstOrFail();
    }

    public function test_normal_size_payload_succeeds(): void
    {
        $payload = [
            'content' => [
                'html' => '<p>Hello world</p>',
                'css' => 'p { color: red; }',
                'components' => ['type' => 'text', 'content' => 'Hello world'],
                'styles' => [],
            ],
        ];

        $response = $this->putJson(
            "/api/v1/website-builder/pages/{$this->page->id}",
            $payload
        );

        $response->assertOk();
        // Title is at data.title when PageResource is returned; if no
        // resource wrapper is present the path is just `title`.
        $body = $response->json();
        $this->assertNotEmpty($body, 'Response body is empty: '.$response->getContent());
        $title = $body['data']['title'] ?? $body['title'] ?? null;
        $this->assertSame('Home', $title, 'Expected title "Home" but got: '.json_encode($body));
    }

    public function test_payload_exceeding_max_returns_413_with_clear_message(): void
    {
        // Default max_page_bytes is 50 MB (52428800). Build a content array
        // with one big string > 50 MB.
        $maxBytes = (int) config('website_builder.max_page_bytes');
        $oversizeString = str_repeat('A', $maxBytes + 1);

        $payload = [
            'content' => [
                'html' => '<p>'.$oversizeString.'</p>',
                'css' => '',
                'components' => [],
                'styles' => [],
            ],
        ];

        $response = $this->putJson(
            "/api/v1/website-builder/pages/{$this->page->id}",
            $payload
        );

        $response->assertStatus(413);
        $response->assertJsonStructure(['success', 'message']);
        $this->assertStringContainsString(
            'exceeding',
            $response->json('message')
        );
        $this->assertStringContainsString(
            'compress',
            $response->json('message')
        );
    }

    public function test_max_page_bytes_can_be_overridden_by_config(): void
    {
        // Lower the ceiling to 1 KB and verify the 413 fires much sooner.
        config(['website_builder.max_page_bytes' => 1024]);

        $payload = [
            'content' => [
                'html' => str_repeat('A', 2048),
                'css' => '',
                'components' => [],
                'styles' => [],
            ],
        ];

        $response = $this->putJson(
            "/api/v1/website-builder/pages/{$this->page->id}",
            $payload
        );

        $response->assertStatus(413);
    }

    public function test_payload_with_inline_base64_png_under_limit_is_accepted(): void
    {
        // A 10 KB base64 PNG fits well under the default 50 MB ceiling — this
        // verifies the guard does NOT reject legitimate smaller payloads.
        $tinyPng = base64_encode(random_bytes(10 * 1024));
        $payload = [
            'content' => [
                'html' => '<img src="data:image/png;base64,'.$tinyPng.'" />',
                'css' => '',
                'components' => [
                    [
                        'type' => 'image',
                        'attributes' => ['src' => 'data:image/png;base64,'.$tinyPng],
                    ],
                ],
                'styles' => [],
            ],
        ];

        $response = $this->putJson(
            "/api/v1/website-builder/pages/{$this->page->id}",
            $payload
        );

        $response->assertOk();
    }
}
