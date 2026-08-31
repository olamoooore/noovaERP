<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Chatter HTTP API integration test — exercises the live router/middleware
 * path for /api/v1/chatter/{subjectType}/{subjectId}.
 */
class ChatterApiTest extends TenantTestCase
{
    private User $actor;

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext('chatter-api-tenant');
        Storage::fake('local');

        $this->actor = User::factory()->create(['name' => 'Api Actor']);
        $this->subject = User::factory()->create(['name' => 'Api Subject']);
    }

    public function test_show_returns_200_and_bundle_payload(): void
    {
        $this->actingAs($this->actor);

        $response = $this->getJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}"
        );

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'logs',
                'messages',
                'activities',
                'attachments',
            ],
        ]);
    }

    public function test_add_message_returns_201_and_message_payload(): void
    {
        $this->actingAs($this->actor);

        $response = $this->postJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}/messages",
            ['body' => 'Posted via API test.']
        );

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Posted via API test.');
        $response->assertJsonPath('data.actor.id', $this->actor->id);
    }

    public function test_add_activity_validates_activity_type(): void
    {
        $this->actingAs($this->actor);

        $response = $this->postJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}/activities",
            [
                'activity_type' => 'invalid_type',
                'summary' => 'Should fail',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['activity_type']);
    }

    public function test_add_activity_creates_planned_activity(): void
    {
        $this->actingAs($this->actor);

        $response = $this->postJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}/activities",
            [
                'activity_type' => 'call',
                'summary' => 'Schedule onboarding call',
                'assigned_to_id' => $this->actor->id,
                'due_date' => now()->addDay()->toDateTimeString(),
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'planned');
        $response->assertJsonPath('data.activity_type', 'call');
        $response->assertJsonPath('data.assigned_to.id', $this->actor->id);
    }

    public function test_mark_activity_done_flips_status(): void
    {
        $this->actingAs($this->actor);

        $create = $this->postJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}/activities",
            ['activity_type' => 'todo', 'summary' => 'Follow up']
        )->json('data');

        $done = $this->postJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}/activities/{$create['id']}/done"
        );

        $done->assertOk();
        $done->assertJsonPath('data.status', 'done');
    }

    public function test_upload_attachment_persists_file(): void
    {
        $this->actingAs($this->actor);

        $response = $this->postJson(
            '/api/v1/chatter/'.urlencode(User::class)."/{$this->subject->id}/attachments",
            ['file' => UploadedFile::fake()->create('doc.pdf', 64)]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.filename', 'doc.pdf');
        $response->assertJsonPath('data.size', 64 * 1024);
    }

    public function test_show_rejects_invalid_subject_type(): void
    {
        $this->actingAs($this->actor);

        $response = $this->getJson(
            '/api/v1/chatter/'.urlencode('App\\Models\\NotARealClass')."/{$this->subject->id}"
        );

        $response->assertStatus(422);
    }
}
