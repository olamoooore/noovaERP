<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\PageVersion;
use Modules\WebsiteBuilder\Models\Website;
use Tests\TenantTestCase;

/**
 * Regression: Page::createVersion() must be atomic.
 *
 * Production 2026-09-22: a save failed at the version INSERT (stale sequence →
 * primary-key collision) after the first statement had already marked every
 * version is_current=false. The page was thereby stranded with no current
 * version — the editor loaded blank (content: null) while the published HTML
 * still rendered. A failed insert must roll back the is_current flip.
 */
class WebsitePageVersionAtomicityTest extends TenantTestCase
{
    public function test_failed_version_insert_keeps_the_previous_version_current(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Atomicity Admin',
            'email' => 'atomicity-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $website = Website::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Atomicity Website',
            'slug' => 'atomicity-'.Str::random(6),
            'status' => 'draft',
            'created_by' => $userId,
        ]);

        // The Website model auto-creates a Home page (slug 'home') with an
        // initial current version.
        $page = $website->pages()->where('slug', 'home')->firstOrFail();
        $first = $page->currentVersion;
        $this->assertNotNull($first, 'initial version must be current');
        $this->assertTrue($first->is_current);

        // Force the next version INSERT to fail (as a PK collision did in
        // production). The is_current reset runs first and must roll back.
        $event = 'eloquent.creating: '.PageVersion::class;
        Event::listen($event, function () {
            throw new \RuntimeException('forced version insert failure');
        });

        try {
            $page->createVersion(['html' => '<p>v2</p>'], $userId);
            $this->fail('expected the forced insert failure to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced version insert failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }

        $current = $page->fresh()->currentVersion;
        $this->assertNotNull($current, 'page must never be left without a current version');
        $this->assertSame($first->id, $current->id, 'the previous version must remain current after a failed save');

        // And a subsequent save works normally.
        $second = $page->createVersion(['html' => '<p>v3</p>'], $userId);
        $this->assertTrue($second->is_current);
        $this->assertSame($second->id, $page->fresh()->currentVersion->id);
        $this->assertFalse($first->fresh()->is_current);
    }
}
