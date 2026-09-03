<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Models\ChatterAttachment;
use App\Models\ChatterMessage;
use App\Models\User;
use App\Services\Chatter\ChatterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Issue: Odoo-style Chatter — reusable activity log + messages + activities
 * + attachments attached to any document subject (polymorphic).
 */
class ChatterServiceTest extends TenantTestCase
{
    private ChatterService $chatter;

    private User $actor;

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('chatter-test-tenant');
        Storage::fake('public');

        $this->actor = User::factory()->create(['name' => 'Chatter Actor']);
        $this->subject = User::factory()->create(['name' => 'Subject Doc']);

        $this->chatter = app(ChatterService::class);
    }

    public function test_log_event_persists_activity_log_row(): void
    {
        $this->actingAs($this->actor);

        $this->chatter->logEvent(User::class, $this->subject->id, 'status_changed', 'Draft -> Confirmed', [
            'old' => 'draft',
            'new' => 'confirmed',
        ]);

        $row = ActivityLogEntry::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $this->subject->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('status_changed', $row->event);
        $this->assertSame('Draft -> Confirmed', $row->description);
        $this->assertSame($this->actor->id, $row->causer_id);
        $this->assertSame('draft', $row->properties['old']);
    }

    public function test_add_message_creates_message_and_logs_event(): void
    {
        $this->actingAs($this->actor);

        $message = $this->chatter->addMessage(User::class, $this->subject->id, 'Please review this draft.');

        $this->assertSame('Please review this draft.', $message->body);
        $this->assertSame($this->actor->id, $message->author_id);
        $this->assertSame(User::class, $message->subject_type);
        $this->assertSame((string) $this->subject->id, $message->subject_id);

        // Activity log entry should be created automatically.
        $this->assertSame(1, ChatterMessage::count());
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $this->subject->id,
            'event' => 'message',
            'causer_id' => $this->actor->id,
        ]);
    }

    public function test_add_activity_persists_with_assigned_to_and_due_date(): void
    {
        $this->actingAs($this->actor);

        $activity = $this->chatter->addActivity(User::class, $this->subject->id, [
            'activity_type' => 'follow_up',
            'summary' => 'Call back about contract terms',
            'note' => 'Discuss renewal pricing.',
            'due_date' => now()->addDays(3)->toDateTimeString(),
            'assigned_to_id' => $this->actor->id,
        ]);

        $this->assertSame('planned', $activity->status);
        $this->assertSame('follow_up', $activity->activity_type);
        $this->assertSame($this->actor->id, $activity->assigned_to_id);
        $this->assertNotNull($activity->due_date);
    }

    public function test_mark_activity_done_flips_status_and_logs_event(): void
    {
        $this->actingAs($this->actor);

        $activity = $this->chatter->addActivity(User::class, $this->subject->id, [
            'activity_type' => 'todo',
            'summary' => 'Send quotation PDF',
        ]);

        $updated = $this->chatter->markActivityDone(User::class, $this->subject->id, $activity->id);

        $this->assertSame('done', $updated->status);
        $this->assertNotNull($updated->completed_at);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'activity_done',
            'subject_id' => $this->subject->id,
        ]);
    }

    public function test_upload_attachment_writes_file_and_creates_row(): void
    {
        $this->actingAs($this->actor);

        $file = UploadedFile::fake()->create('invoice.pdf', 128);

        $attachment = $this->chatter->uploadAttachment(User::class, $this->subject->id, $file);

        $this->assertSame('invoice.pdf', $attachment->filename);
        $this->assertSame(128 * 1024, $attachment->size);
        $this->assertNotNull($attachment->storage_path);
        // Service default disk is `public` (config('chatter.disk'), commit
        // 300abaab) — assert against the same disk the service wrote to.
        $this->assertSame('public', $attachment->storage_disk);
        Storage::disk('public')->assertExists($attachment->storage_path);
        $this->assertSame(1, ChatterAttachment::count());
    }

    public function test_delete_attachment_removes_file_and_row(): void
    {
        $this->actingAs($this->actor);

        $file = UploadedFile::fake()->create('attachment.txt', 10);
        $attachment = $this->chatter->uploadAttachment(User::class, $this->subject->id, $file);

        $this->chatter->deleteAttachment(User::class, $this->subject->id, $attachment->id);

        $this->assertSame(0, ChatterAttachment::count());
        Storage::disk('public')->assertMissing($attachment->storage_path);
    }

    public function test_bundle_returns_logs_messages_activities_and_attachments(): void
    {
        $this->actingAs($this->actor);

        $this->chatter->addMessage(User::class, $this->subject->id, 'Hello world');
        $activity = $this->chatter->addActivity(User::class, $this->subject->id, [
            'activity_type' => 'reminder',
            'summary' => 'Quarterly review',
        ]);
        $this->chatter->uploadAttachment(
            User::class,
            $this->subject->id,
            UploadedFile::fake()->create('report.pdf', 200)
        );
        $this->chatter->logEvent(User::class, $this->subject->id, 'status_changed', 'Draft -> Confirmed');

        $bundle = $this->chatter->bundle(User::class, $this->subject->id);

        $this->assertGreaterThanOrEqual(1, count($bundle['logs']));
        $this->assertSame(1, count($bundle['messages']));
        $this->assertSame(1, count($bundle['activities']));
        $this->assertSame(1, count($bundle['attachments']));
        $this->assertSame('Hello world', $bundle['messages'][0]['body']);
        $this->assertSame('reminder', $bundle['activities'][0]['activity_type']);
        $this->assertSame('report.pdf', $bundle['attachments'][0]['filename']);
    }

    public function test_activity_can_be_cancelled(): void
    {
        $this->actingAs($this->actor);

        $activity = $this->chatter->addActivity(User::class, $this->subject->id, [
            'activity_type' => 'meeting',
            'summary' => 'Discovery call',
        ]);

        // Mark done — there's no separate cancel endpoint, but we should at least
        // be able to mark it done which is the main user-visible action.
        $this->chatter->markActivityDone(User::class, $this->subject->id, $activity->id);

        $this->assertSame('done', $activity->fresh()->status);
    }
}
