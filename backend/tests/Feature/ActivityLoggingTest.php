<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Features\ActivityLogging\Services\ActivityLogService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Activity Logging evidence test — ADC Section 8.
 *
 * Proves the centralized ActivityLogService persists a complete audit row
 * (causer, subject, event, properties) into the Spatie activity_log table.
 */
class ActivityLoggingTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('test-tenant');
    }

    public function test_activity_log_records_causer_subject_event_and_properties(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create(['name' => 'Recorded Subject']);

        app(ActivityLogService::class)
            ->for(User::class, (int) $actor->id)
            ->on($subject)
            ->withProperties(['action' => 'manual_update', 'source' => 'evidence-test'])
            ->useEvent('updated')
            ->log($subject, ['ip' => '127.0.0.1']);

        $row = DB::table('activity_log')
            ->where('subject_type', User::class)
            ->where('subject_id', $subject->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'Activity log row was not created.');
        $this->assertSame(User::class, $row->causer_type);
        $this->assertEquals($actor->id, $row->causer_id);
        $this->assertSame('updated', $row->event);

        $props = json_decode((string) $row->properties, true);
        $this->assertSame('manual_update', $props['action'] ?? null);
        $this->assertSame('127.0.0.1', $props['ip'] ?? null);
    }
}
